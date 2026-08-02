<?php
/**
 * NÚCLEO PATO — pedidos idempotentes y cotizados desde la fuente canónica.
 *
 * Registrar no significa confirmar, reservar ni cobrar. El caller sólo envía
 * referencias de oferta, cantidades y selecciones; catalog.php decide precios.
 */
declare(strict_types=1);

const PATO_PEDIDOS = 'pedidos.json';

function pato_estados(): array
{
    $estados = pato_cfg('estados', []);
    if (is_array($estados) && $estados) return array_values($estados);
    return ['nuevo', 'confirmado', 'en preparación', 'listo', 'entregado'];
}

function pato_pedidos(): array
{
    $data = pato_read(PATO_PEDIDOS, []);
    return isset($data['pedidos']) && is_array($data['pedidos'])
        ? $data['pedidos']
        : [];
}

function pato_pedidos_guardar(array $pedidos): bool
{
    return pato_write(PATO_PEDIDOS, ['pedidos' => array_values($pedidos)]);
}

/** Siguiente folio correlativo; se invoca dentro del lock de pedidos. */
function pato_folio(array $pedidos): string
{
    $prefix = (string) pato_cfg('folio_prefijo', 'PT');
    $max = (int) pato_cfg('folio_inicial', 1000);
    foreach ($pedidos as $pedido) {
        $number = (int) preg_replace(
            '/\D/',
            '',
            (string) ($pedido['id'] ?? '')
        );
        if ($number > $max) $max = $number;
    }
    return $prefix . '-' . ($max + 1);
}

/** Quita hashes internos antes de responder a un canal. */
function pato_pedido_publico(array $pedido): array
{
    unset($pedido['_idempotency_key_hash'], $pedido['_request_hash']);
    return $pedido;
}

/**
 * Crea una intención de pedido durable e idempotente.
 *
 * Requeridos:
 * - idempotency_key: 16..128 caracteres seguros;
 * - items: offer_id, quantity y selections.
 *
 * Los campos `lineas`, `precio` y `total` del caller no se usan.
 */
function pato_pedido_crear(
    array $input,
    array $verified_channel_context = []
): array
{
    $channel_id = pato_canal_origen($verified_channel_context);
    if ($channel_id === null) {
        return [
            'ok' => false,
            'error' => 'contexto de canal verificado requerido',
        ];
    }
    $principal_id = trim(
        (string) ($verified_channel_context['principal_id'] ?? '')
    );
    $key = trim((string) ($input['idempotency_key'] ?? ''));
    if (strlen($key) < 16
        || strlen($key) > 128
        || !preg_match('/\A[A-Za-z0-9._:-]+\z/D', $key)) {
        return ['ok' => false, 'error' => 'idempotency_key inválida'];
    }
    $intent_items = pato_pedido_items_normalizados($input['items'] ?? null);
    if ($intent_items === null) {
        return ['ok' => false, 'error' => 'items inválidos'];
    }
    $payment_method = trim((string) ($input['payment_method'] ?? ''));
    $wa = preg_replace('/[^0-9]/', '', (string) ($input['wa'] ?? ''));
    if (!is_string($wa)) $wa = '';
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $wa_valid = preg_match('/\A[0-9]{8,20}\z/D', $wa) === 1;
    $email_valid = strlen($email) <= 120
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    if (!$wa_valid && !$email_valid) {
        return [
            'ok' => false,
            'error' => 'WhatsApp o email válido requerido',
        ];
    }
    $normalized = [
        'cliente' => substr(
            trim((string) ($input['cliente'] ?? '')),
            0,
            80
        ) ?: 'Sin nombre',
        'wa' => $wa_valid ? $wa : '',
        'email' => $email_valid ? $email : '',
        'notas' => substr(
            trim((string) ($input['notas'] ?? '')),
            0,
            500
        ),
        'entrega' => substr(
            trim((string) ($input['entrega'] ?? '')),
            0,
            120
        ),
        // Nunca se deriva del body HTTP. El conector autenticado entrega este
        // contexto por un argumento separado y cerrado.
        'origen' => $channel_id,
        'payment_method' => $payment_method,
        'items' => $intent_items,
    ];
    $request_json = json_encode(
        $normalized,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($request_json === false) {
        return ['ok' => false, 'error' => 'solicitud inválida'];
    }
    $key_scope = json_encode(
        [
            (string) pato_cfg('id'),
            $channel_id,
            $principal_id,
            $key,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($key_scope === false) {
        return ['ok' => false, 'error' => 'solicitud inválida'];
    }
    $key_hash = hash('sha256', $key_scope);
    $request_hash = hash('sha256', $request_json);

    // La idempotencia se resuelve antes de releer el catálogo. Un retry puede
    // recuperar su folio aunque la oferta cambie o la fuente esté indisponible.
    try {
        $existing_result = pato_with_lock(PATO_PEDIDOS, function () use (
            $key_hash,
            $request_hash
        ) {
            return pato_pedido_idempotente(
                pato_pedidos(),
                $key_hash,
                $request_hash
            );
        });
    } catch (Throwable $error) {
        return [
            'ok' => false,
            'error' => 'almacén de pedidos no disponible',
        ];
    }
    if ($existing_result !== null) return $existing_result;

    $quote = pato_catalogo_cotizar(
        $intent_items,
        $payment_method !== '' ? $payment_method : null
    );
    if (!$quote['ok']) return $quote;
    $states = pato_estados();

    try {
        $result = pato_with_lock(PATO_PEDIDOS, function () use (
            $normalized,
            $quote,
            $states,
            $key_hash,
            $request_hash
        ) {
            $orders = pato_pedidos();
            $existing_result = pato_pedido_idempotente(
                $orders,
                $key_hash,
                $request_hash
            );
            if ($existing_result !== null) return $existing_result;

            $folio = pato_folio($orders);
            $total_minor = $quote['total_minor'];
            $order = [
                'id' => $folio,
                'at' => date('c'),
                'cliente' => $normalized['cliente'],
                'wa' => $normalized['wa'],
                'email' => $normalized['email'],
                'lineas' => $quote['lines'],
                'subtotal_minor' => $quote['subtotal_minor'],
                'shipping_minor' => $quote['shipping_minor'],
                'total_minor' => $total_minor,
                'total' => $total_minor === null
                    ? null
                    : round($total_minor / 100, 2),
                'moneda' => $quote['currency'],
                'estado' => $states[0],
                'notas' => $normalized['notas'],
                'entrega' => $normalized['entrega'],
                'origen' => $normalized['origen'],
                'metodo_pago' => $normalized['payment_method'],
                'requires_human_confirmation' =>
                    $quote['requires_human_confirmation'],
                'reservation_created' => false,
                'payment_status' => 'pending',
                'pagado' => false,
                '_idempotency_key_hash' => $key_hash,
                '_request_hash' => $request_hash,
            ];
            $orders[] = $order;
            if (!pato_pedidos_guardar($orders)) {
                return [
                    'ok' => false,
                    'error' => 'no se pudo guardar el pedido',
                ];
            }
            return [
                'ok' => true,
                'pedido' => pato_pedido_publico($order),
                'status' => 'registered_pending_confirmation',
                'replayed' => false,
            ];
        });
    } catch (Throwable $error) {
        return [
            'ok' => false,
            'error' => 'almacén de pedidos no disponible',
        ];
    }
    if (!$result['ok'] || !empty($result['replayed'])) return $result;

    // Auditoría posterior al commit principal. Si falla, la orden permanece
    // registrada y el fallo queda reparable.
    $order = $result['pedido'];
    $backup_ok = pato_append('pedidos.jsonl', $order);
    $event_ok = pato_evento('pedido', [
        'folio' => $order['id'],
        'total_minor' => $order['total_minor'],
    ]);
    if (!$backup_ok || !$event_ok) {
        pato_append('avisos-fallidos.jsonl', [
            'ref' => $order['id'],
            'titulo' => 'auditoría local de pedido',
        ]);
    }
    return $result;
}

/** Normaliza y cierra la intención que participa en el hash idempotente. */
function pato_pedido_items_normalizados($raw_items): ?array
{
    if (!is_array($raw_items)
        || !$raw_items
        || count($raw_items) > 50) {
        return null;
    }
    $result = [];
    foreach ($raw_items as $raw) {
        if (!is_array($raw)
            || array_diff(
                array_keys($raw),
                ['offer_id', 'quantity', 'selections']
            )) {
            return null;
        }
        $offer_id = $raw['offer_id'] ?? null;
        $quantity = $raw['quantity'] ?? 1;
        $selections = $raw['selections'] ?? [];
        if (!is_string($offer_id)
            || $offer_id === ''
            || strlen($offer_id) > 200
            || !is_int($quantity)
            || $quantity < 1
            || $quantity > 99
            || !is_array($selections)
            || count($selections) > 50) {
            return null;
        }
        $normalized_selections = [];
        foreach ($selections as $option => $value) {
            if (!is_string($option)
                || !preg_match('/\A[a-z0-9][a-z0-9._-]{0,99}\z/D', $option)
                || (!is_string($value) && !is_int($value))
                || strlen((string) $value) > 100) {
                return null;
            }
            $normalized_selections[$option] = (string) $value;
        }
        ksort($normalized_selections, SORT_STRING);
        $result[] = [
            'offer_id' => $offer_id,
            'quantity' => $quantity,
            'selections' => $normalized_selections,
        ];
    }
    return $result;
}

/** Devuelve replay/conflicto o null si la llave aún no existe. */
function pato_pedido_idempotente(
    array $orders,
    string $key_hash,
    string $request_hash
): ?array {
    foreach ($orders as $existing) {
        if (($existing['_idempotency_key_hash'] ?? '') !== $key_hash) {
            continue;
        }
        if (($existing['_request_hash'] ?? '') !== $request_hash) {
            return [
                'ok' => false,
                'error' => 'conflicto de idempotencia',
            ];
        }
        return [
            'ok' => true,
            'pedido' => pato_pedido_publico($existing),
            'status' => 'registered_pending_confirmation',
            'replayed' => true,
        ];
    }
    return null;
}

/**
 * Valida autoridad ya resuelta por el conector.
 *
 * Esta estructura nunca debe construirse desde el body del cliente. PATO
 * exige negocio, canal, identidad y scope; el gateway/conector conserva la
 * responsabilidad de verificar la credencial que produjo el contexto.
 */
function pato_canal_origen(array $context): ?string
{
    if (array_diff(
        array_keys($context),
        ['business_id', 'channel_id', 'principal_id', 'scopes']
    )) {
        return null;
    }
    if (($context['business_id'] ?? null) !== pato_cfg('id')) return null;
    $channel_id = $context['channel_id'] ?? null;
    if (!is_string($channel_id)
        || !preg_match('/\A[a-z0-9][a-z0-9._:-]{0,119}\z/D', $channel_id)) {
        return null;
    }
    $principal_id = $context['principal_id'] ?? null;
    if (!is_string($principal_id)
        || !preg_match('/\A[^\x00-\x20\x7f]{1,200}\z/D', $principal_id)) {
        return null;
    }
    $scopes = $context['scopes'] ?? null;
    if (!is_array($scopes)
        || !in_array('pond.action', $scopes, true)) {
        return null;
    }
    return $channel_id;
}

/** Aviso best-effort. Se llama después de crear el pedido. */
function pato_avisar_pedido(array $pedido): array
{
    $lines = [];
    foreach ((array) ($pedido['lineas'] ?? []) as $line) {
        $lines[] = '· ' . ($line['quantity'] ?? 1)
            . '× ' . ($line['title'] ?? '');
    }
    $total = ($pedido['total_minor'] ?? null) === null
        ? 'Total pendiente de confirmar'
        : 'Total: $'
            . number_format(((int) $pedido['total_minor']) / 100, 2)
            . ' ' . ($pedido['moneda'] ?? pato_cfg('moneda', 'MXN'));
    $text = pato_cfg('marca', 'Pato')
        . " — PEDIDO REGISTRADO {$pedido['id']}\n"
        . "Estado: pendiente de confirmación\n"
        . "Cliente: {$pedido['cliente']}\n"
        . (!empty($pedido['wa']) ? "WhatsApp: {$pedido['wa']}\n" : '')
        . (!empty($pedido['email']) ? "Correo: {$pedido['email']}\n" : '')
        . implode("\n", $lines) . "\n"
        . $total
        . (!empty($pedido['entrega'])
            ? "\nEntrega: {$pedido['entrega']}"
            : '')
        . (!empty($pedido['notas'])
            ? "\nNotas: {$pedido['notas']}"
            : '');

    $telegram = pato_telegram($text);
    $mail = pato_mail(
        pato_cfg('marca', 'Pato') . " — pedido {$pedido['id']}",
        $text
    );
    if (!$telegram && $mail === 0) {
        pato_append('avisos-fallidos.jsonl', [
            'ref' => $pedido['id'],
            'titulo' => 'pedido',
        ]);
    }
    return ['telegram' => $telegram, 'mail' => $mail];
}

function pato_pedido_estado(string $folio, string $estado): bool
{
    if (!in_array($estado, pato_estados(), true)) return false;
    try {
        $updated = pato_with_lock(PATO_PEDIDOS, function () use (
            $folio,
            $estado
        ) {
            $orders = pato_pedidos();
            $found = false;
            foreach ($orders as &$order) {
                if (($order['id'] ?? '') === $folio) {
                    $order['estado'] = $estado;
                    $order['estado_at'] = date('c');
                    $found = true;
                    break;
                }
            }
            unset($order);
            return $found ? pato_pedidos_guardar($orders) : false;
        });
    } catch (Throwable $error) {
        return false;
    }
    if ($updated) {
        pato_append('estados.jsonl', [
            'folio' => $folio,
            'estado' => $estado,
        ]);
    }
    return $updated;
}

/** Registrar pago es una acción de backoffice distinta de crear el pedido. */
function pato_pedido_pago(string $folio, bool $pagado = true): bool
{
    try {
        return pato_with_lock(PATO_PEDIDOS, function () use (
            $folio,
            $pagado
        ) {
            $orders = pato_pedidos();
            $found = false;
            foreach ($orders as &$order) {
                if (($order['id'] ?? '') === $folio) {
                    $order['pagado'] = $pagado;
                    $order['payment_status'] = $pagado ? 'paid' : 'pending';
                    $found = true;
                    break;
                }
            }
            unset($order);
            return $found ? pato_pedidos_guardar($orders) : false;
        });
    } catch (Throwable $error) {
        return false;
    }
}

function pato_pedido(string $folio): ?array
{
    foreach (pato_pedidos() as $order) {
        if (($order['id'] ?? '') === $folio) {
            return pato_pedido_publico($order);
        }
    }
    return null;
}

function pato_pedidos_de(string $key): array
{
    $key = strtolower(trim($key));
    $number = preg_replace('/[^0-9]/', '', $key);
    $result = [];
    foreach (pato_pedidos() as $order) {
        if ($key !== ''
            && strtolower((string) ($order['email'] ?? '')) === $key) {
            $result[] = pato_pedido_publico($order);
            continue;
        }
        if ($number !== '' && (string) ($order['wa'] ?? '') === $number) {
            $result[] = pato_pedido_publico($order);
        }
    }
    return $result;
}

function pato_resumen(): array
{
    $orders = pato_pedidos();
    $sold = 0.0;
    $collected = 0.0;
    $pending_total = 0;
    $by_state = [];
    foreach (pato_estados() as $state) $by_state[$state] = 0;
    foreach ($orders as $order) {
        if (is_numeric($order['total'] ?? null)) {
            $sold += (float) $order['total'];
            if (!empty($order['pagado'])) {
                $collected += (float) $order['total'];
            }
        } else {
            $pending_total++;
        }
        $state = (string) ($order['estado'] ?? '');
        if (isset($by_state[$state])) $by_state[$state]++;
    }
    return [
        'pedidos' => count($orders),
        'vendido' => round($sold, 2),
        'cobrado' => round($collected, 2),
        'por_cobrar' => round($sold - $collected, 2),
        'total_por_confirmar' => $pending_total,
        'moneda' => (string) pato_cfg('moneda', 'MXN'),
        'por_estado' => $by_state,
    ];
}
