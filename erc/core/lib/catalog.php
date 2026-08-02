<?php
/**
 * NÚCLEO PATO — cotización desde el catálogo canónico POND.
 *
 * Un endpoint público nunca decide precios. Recibe referencias de oferta,
 * cantidades y selecciones; este módulo vuelve a leer la fuente canónica y
 * calcula el importe. Sólo `pond.business-catalog.v1` puede crear pedidos.
 */
declare(strict_types=1);

const PATO_CATALOGO_MAX_BYTES = 2 * 1024 * 1024;
const PATO_CATALOGO_MAX_OFFERS = 500;
const PATO_CATALOGO_MAX_OPTIONS = 50;
const PATO_CATALOGO_MAX_VALUES = 100;
const PATO_DINERO_MAX_MINOR = 999999999999;

/** Ruta segura al catálogo, siempre dentro de la raíz del sitio. */
function pato_catalogo_path(): ?string
{
    $rel = trim((string) pato_cfg('catalogo', 'data/catalogo-pond.json'));
    if ($rel === '' || strlen($rel) > 240) return null;
    $normal = str_replace('\\', '/', $rel);
    if ($normal[0] === '/' || preg_match('/^[A-Za-z]:/', $normal)) return null;
    $partes = explode('/', $normal);
    foreach ($partes as $parte) {
        if ($parte === '' || $parte === '.' || $parte === '..') return null;
        if (strtolower($parte) === 'secrets' || stripos($parte, '.env') === 0) return null;
    }
    $site = realpath(dirname(PATO_CORE_DIR));
    $path = realpath(dirname(PATO_CORE_DIR) . '/' . implode('/', $partes));
    if ($site === false || $path === false || !is_file($path)) return null;

    // realpath tambien resuelve symlinks: el archivo debe seguir contenido
    // dentro del sitio, no solo parecer relativo antes de resolverlo.
    $prefix = rtrim($site, '/\\') . DIRECTORY_SEPARATOR;
    $inside = PHP_OS_FAMILY === 'Windows'
        ? strncasecmp($path, $prefix, strlen($prefix)) === 0
        : strncmp($path, $prefix, strlen($prefix)) === 0;
    if (!$inside) return null;
    $size = @filesize($path);
    if (!is_int($size) || $size <= 0 || $size > PATO_CATALOGO_MAX_BYTES) {
        return null;
    }
    return $path;
}

/** Catálogo validado lo suficiente para ser fuente de precios. */
function pato_catalogo(): array
{
    $path = pato_catalogo_path();
    if ($path === null) {
        return ['ok' => false, 'error' => 'catálogo canónico no disponible'];
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return ['ok' => false, 'error' => 'catálogo canónico no disponible'];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)
        || ($data['schema_version'] ?? '') !== 'pond.business-catalog.v1'
        || ($data['business_id'] ?? '') !== pato_cfg('id')
        || !preg_match('/^[A-Z]{3}$/', (string) ($data['currency'] ?? ''))
        || empty($data['offers'])
        || !is_array($data['offers'])
        || count($data['offers']) > PATO_CATALOGO_MAX_OFFERS) {
        return ['ok' => false, 'error' => 'catálogo canónico inválido'];
    }
    return ['ok' => true, 'catalogo' => $data];
}

function pato_catalogo_diagnostico(): array
{
    $result = pato_catalogo();
    if (!$result['ok']) {
        return ['ok' => false, 'error' => $result['error']];
    }
    $catalogo = $result['catalogo'];
    $activos = 0;
    foreach ($catalogo['offers'] as $offer) {
        if (is_array($offer) && ($offer['active'] ?? true)) $activos++;
    }
    return [
        'ok' => $activos > 0,
        'schema_version' => $catalogo['schema_version'],
        'business_id' => $catalogo['business_id'],
        'currency' => $catalogo['currency'],
        'active_offers' => $activos,
    ];
}

/**
 * Cotiza items contra la fuente canónica.
 *
 * Entrada:
 *   [['offer_id'=>'hamburguesa','quantity'=>2,'selections'=>['tamano'=>'doble']]]
 *
 * Nunca acepta nombres, precios, totales ni líneas libres del cliente.
 */
function pato_catalogo_cotizar(array $items, ?string $payment_method = null): array
{
    if (!$items || count($items) > 50) {
        return ['ok' => false, 'error' => 'items debe contener entre 1 y 50 partidas'];
    }
    $loaded = pato_catalogo();
    if (!$loaded['ok']) return $loaded;
    $catalogo = $loaded['catalogo'];
    $offers = [];
    foreach ($catalogo['offers'] as $offer) {
        if (!is_array($offer) || !($offer['active'] ?? true)) continue;
        $id = trim((string) ($offer['id'] ?? ''));
        if (!preg_match('/\A[a-z0-9][a-z0-9._-]{0,99}\z/D', $id)
            || isset($offers[$id])) {
            return ['ok' => false, 'error' => 'catálogo con identificadores inválidos'];
        }
        $offers[$id] = $offer;
    }

    $currency = (string) $catalogo['currency'];
    $lines = [];
    $subtotal = 0;
    $confirmations = [];
    $shipping = [];
    $payment_sets = [];
    $has_published_payment_methods = false;

    foreach ($items as $raw) {
        if (!is_array($raw)) {
            return ['ok' => false, 'error' => 'partida inválida'];
        }
        $offer_id = trim((string) ($raw['offer_id'] ?? ''));
        $prefix = pato_cfg('id') . ':';
        if (strpos($offer_id, $prefix) === 0) {
            $offer_id = substr($offer_id, strlen($prefix));
        }
        $quantity = $raw['quantity'] ?? 1;
        if (!is_int($quantity) || $quantity < 1 || $quantity > 99) {
            return ['ok' => false, 'error' => 'cantidad inválida'];
        }
        if (!isset($offers[$offer_id])) {
            return ['ok' => false, 'error' => 'oferta no disponible'];
        }
        $offer = $offers[$offer_id];
        $pricing = $offer['pricing'] ?? null;
        $price_minor = $offer['price_minor'] ?? null;
        if (!is_array($pricing)
            || ($pricing['type'] ?? '') !== 'fixed'
            || !is_int($price_minor)
            || $price_minor < 0
            || $price_minor > PATO_DINERO_MAX_MINOR) {
            return ['ok' => false, 'error' => 'la oferta requiere cotización humana'];
        }
        $availability = $offer['availability'] ?? null;
        if (!is_array($availability)) {
            return ['ok' => false, 'error' => 'disponibilidad inválida en catálogo'];
        }
        $availability_status = (string) ($availability['status'] ?? '');
        if (!in_array(
            $availability_status,
            ['available', 'requires_confirmation', 'unavailable'],
            true
        )) {
            return ['ok' => false, 'error' => 'disponibilidad inválida en catálogo'];
        }
        if ($availability_status === 'unavailable') {
            return ['ok' => false, 'error' => 'oferta no disponible'];
        }
        $fulfillment = $offer['fulfillment'] ?? null;
        if (!is_array($fulfillment)
            || !in_array(
                (string) ($fulfillment['type'] ?? ''),
                ['physical', 'service', 'pickup'],
                true
            )) {
            return ['ok' => false, 'error' => 'entrega inválida en catálogo'];
        }
        // El catálogo sólo declara disponibilidad; no acredita inventario vivo.
        // Toda oferta física o para recolección requiere confirmación humana,
        // incluso cuando su estado declarado sea `available`.
        if (in_array(
            (string) $fulfillment['type'],
            ['physical', 'pickup'],
            true
        ) || $availability_status === 'requires_confirmation') {
            $confirmations['availability'] = true;
        }

        $selections = $raw['selections'] ?? [];
        if (!is_array($selections)) {
            return ['ok' => false, 'error' => 'selecciones inválidas'];
        }
        $selected = [];
        $adjustment = 0;
        $option_ids = [];
        $options = $offer['options'] ?? [];
        if (!is_array($options) || count($options) > PATO_CATALOGO_MAX_OPTIONS) {
            return ['ok' => false, 'error' => 'opciones inválidas en catálogo'];
        }
        foreach ($options as $option) {
            if (!is_array($option)) {
                return ['ok' => false, 'error' => 'opciones inválidas en catálogo'];
            }
            $option_id = trim((string) ($option['id'] ?? ''));
            if (!preg_match('/\A[a-z0-9][a-z0-9._-]{0,99}\z/D', $option_id)
                || isset($option_ids[$option_id])) {
                return ['ok' => false, 'error' => 'opciones inválidas en catálogo'];
            }
            $option_ids[$option_id] = true;
            $requested = array_key_exists($option_id, $selections)
                ? (string) $selections[$option_id]
                : (isset($option['default']) ? (string) $option['default'] : null);
            if ($requested === null || $requested === '') {
                if (!empty($option['required'])) {
                    return ['ok' => false, 'error' => 'falta una selección requerida'];
                }
                continue;
            }
            $match = null;
            $values = $option['values'] ?? [];
            if (!is_array($values)
                || !$values
                || count($values) > PATO_CATALOGO_MAX_VALUES) {
                return ['ok' => false, 'error' => 'valores inválidos en catálogo'];
            }
            foreach ($values as $value) {
                if (!is_array($value)) continue;
                if ($requested === (string) ($value['id'] ?? '')) {
                    $match = $value;
                    break;
                }
            }
            if ($match === null) {
                return ['ok' => false, 'error' => 'selección no disponible'];
            }
            $delta = $match['price_delta_minor'] ?? 0;
            if (!is_int($delta)
                || $delta < -PATO_DINERO_MAX_MINOR
                || $delta > PATO_DINERO_MAX_MINOR) {
                return ['ok' => false, 'error' => 'modificador de precio inválido'];
            }
            $candidate_adjustment = $adjustment + $delta;
            if (!is_int($candidate_adjustment)
                || $candidate_adjustment < -PATO_DINERO_MAX_MINOR
                || $candidate_adjustment > PATO_DINERO_MAX_MINOR) {
                return ['ok' => false, 'error' => 'modificador de precio inválido'];
            }
            $adjustment = $candidate_adjustment;
            $selected[$option_id] = (string) ($match['id'] ?? $requested);
        }
        foreach ($selections as $key => $_) {
            if (!isset($option_ids[(string) $key])) {
                return ['ok' => false, 'error' => 'opción desconocida'];
            }
        }
        $unit = $price_minor + $adjustment;
        if (!is_int($unit)
            || $unit < 0
            || $unit > PATO_DINERO_MAX_MINOR
            || $unit > intdiv(PATO_DINERO_MAX_MINOR, $quantity)) {
            return ['ok' => false, 'error' => 'precio calculado inválido'];
        }
        $line_total = $unit * $quantity;
        if ($line_total > PATO_DINERO_MAX_MINOR - $subtotal) {
            return ['ok' => false, 'error' => 'total calculado fuera de rango'];
        }
        $subtotal += $line_total;
        $lines[] = [
            'offer_id' => (string) pato_cfg('id') . ':' . $offer_id,
            'title' => substr((string) ($offer['name'] ?? $offer_id), 0, 120),
            'quantity' => $quantity,
            'unit_amount_minor' => $unit,
            'line_total_minor' => $line_total,
            'selections' => $selected,
        ];

        $shipping_row = $fulfillment['shipping'] ?? null;
        if (!is_array($shipping_row)) {
            return ['ok' => false, 'error' => 'envío inválido en catálogo'];
        }
        $status = (string) ($shipping_row['status'] ?? '');
        if ($status === 'requires_confirmation') {
            if (($shipping_row['amount_minor'] ?? null) !== null) {
                return ['ok' => false, 'error' => 'envío inválido en catálogo'];
            }
            $confirmations['shipping'] = true;
        } elseif ($status === 'fixed') {
            $amount = $shipping_row['amount_minor'] ?? null;
            if (!is_int($amount)
                || $amount < 0
                || $amount > PATO_DINERO_MAX_MINOR) {
                return ['ok' => false, 'error' => 'envío inválido en catálogo'];
            }
            $shipping[(string) $amount] = $amount;
        } elseif ($status === 'not_applicable') {
            $amount = $shipping_row['amount_minor'] ?? null;
            if ($amount !== null && $amount !== 0) {
                return ['ok' => false, 'error' => 'envío inválido en catálogo'];
            }
        } else {
            return ['ok' => false, 'error' => 'envío inválido en catálogo'];
        }
        $methods = $offer['payment_methods'] ?? [];
        if (!is_array($methods) || count($methods) > 20) {
            return ['ok' => false, 'error' => 'métodos de pago inválidos'];
        }
        $method_ids = [];
        foreach ($methods as $method) {
            if (!is_string($method)
                || !preg_match('/\A[a-z][a-z0-9._-]{0,49}\z/D', $method)
                || isset($method_ids[$method])) {
                return ['ok' => false, 'error' => 'métodos de pago inválidos'];
            }
            $method_ids[$method] = true;
        }
        if ($method_ids) {
            $has_published_payment_methods = true;
        }
        $payment_sets[] = array_values($methods);
    }

    if (count($shipping) > 1) {
        $confirmations['shipping'] = true;
    }
    $shipping_minor = isset($confirmations['shipping'])
        ? null
        : (count($shipping) === 1 ? (int) reset($shipping) : 0);

    $allowed_payments = $payment_sets ? $payment_sets[0] : [];
    foreach ($payment_sets as $methods) {
        $allowed_payments = array_values(array_intersect($allowed_payments, $methods));
    }
    if ($payment_method !== null && $payment_method !== '') {
        if (!in_array($payment_method, $allowed_payments, true)) {
            return ['ok' => false, 'error' => 'método de pago no disponible'];
        }
    } elseif ($has_published_payment_methods) {
        $confirmations['payment'] = true;
    }

    if ($shipping_minor !== null
        && $shipping_minor > PATO_DINERO_MAX_MINOR - $subtotal) {
        return ['ok' => false, 'error' => 'total calculado fuera de rango'];
    }
    $total_minor = $shipping_minor === null ? null : $subtotal + $shipping_minor;
    return [
        'ok' => true,
        'schema_version' => 'pond.quote.v1',
        'business_id' => (string) pato_cfg('id'),
        'currency' => $currency,
        'lines' => $lines,
        'subtotal_minor' => $subtotal,
        'shipping_minor' => $shipping_minor,
        'total_minor' => $total_minor,
        'status' => $confirmations ? 'requires_confirmation' : 'estimated',
        'requires_human_confirmation' => array_keys($confirmations),
        'payment_methods' => $allowed_payments,
        'reservation_created' => false,
    ];
}
