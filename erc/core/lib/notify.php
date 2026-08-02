<?php
/**
 * NUCLEO PATO — avisos (Telegram + correo).
 *
 * LECCION PAGADA CON VENTAS: en Velas el checkout mostraba "gracias por tu compra" y el
 * pedido no llegaba a ningun lado. En hosting compartido `mail()` cae en spam o falla en
 * SILENCIO. Por eso el nucleo expone `pato_capturar()`: GUARDA primero y avisa despues.
 * Si el aviso falla, el negocio no pierde el pedido — solo pierde la notificacion, y eso
 * queda registrado para poder repararlo.
 *
 * Los tokens NUNCA viven en el repo: se leen de variables de entorno o de
 * `secrets/telegram.json` en el servidor (que se sube a mano).
 */
declare(strict_types=1);

/** [bot_token, chat_id] o [null, null]. */
function pato_telegram_cfg(?string $destinatario = null): array
{
    $tok = getenv('TELEGRAM_BOT_TOKEN') ?: null;
    $chat = getenv('TELEGRAM_CHAT_ID') ?: null;

    if (!$tok) {
        foreach ([dirname(PATO_CORE_DIR) . '/secrets/telegram.json',
                  dirname(PATO_CORE_DIR) . '/../secrets/telegram.json'] as $f) {
            if (is_file($f)) {
                $j = json_decode((string) @file_get_contents($f), true);
                if (is_array($j) && !empty($j['bot_token'])) {
                    $tok  = $j['bot_token'];
                    $chat = $chat ?: (isset($j['chat_id']) ? $j['chat_id'] : null);
                    break;
                }
            }
        }
    }
    // El negocio puede rutear a su propia gente (fundadora, encargado).
    $mapa = pato_cfg('telegram', []);
    if ($destinatario && is_array($mapa) && !empty($mapa[$destinatario]) && $mapa[$destinatario] !== 'default') {
        $chat = $mapa[$destinatario];
    } elseif (is_array($mapa) && !empty($mapa['default']) && $mapa['default'] !== 'default' && !$chat) {
        $chat = $mapa['default'];
    }
    return [$tok, $chat];
}

/** Manda un mensaje por Telegram. Devuelve true si salio. Nunca lanza. */
function pato_telegram(string $texto, ?string $destinatario = null): bool
{
    list($tok, $chat) = pato_telegram_cfg($destinatario);
    if (!$tok || !$chat) return false;
    $url  = "https://api.telegram.org/bot{$tok}/sendMessage";
    $post = http_build_query(['chat_id' => $chat, 'text' => $texto]);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post, CURLOPT_TIMEOUT => 8,
        ]);
        $r = curl_exec($ch);
        curl_close($ch);
        return $r !== false;
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 8,
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $post,
    ]]);
    return @file_get_contents($url, false, $ctx) !== false;
}

/** Correo a los avisados del negocio. Devuelve cuantos salieron. */
function pato_mail(string $asunto, string $cuerpo): int
{
    $to = pato_cfg('avisos', []);
    if (!is_array($to) || !$to) return 0;
    $from = pato_cfg('from', 'no-reply@' . parse_url((string) pato_cfg('dominio', 'localhost'), PHP_URL_HOST));
    $n = 0;
    foreach ($to as $dest) {
        $ok = @mail($dest, $asunto, $cuerpo,
            "From: {$from}\r\nContent-Type: text/plain; charset=utf-8");
        if ($ok) $n++;
    }
    return $n;
}

/**
 * EL PATRON DEL NUCLEO: guardar primero, avisar despues.
 *
 * @param string $bitacora  archivo JSONL donde vive el registro (p.ej. 'leads.jsonl')
 * @param array  $rec       lo que se guarda
 * @param string $titulo    encabezado del aviso
 * @return array  ['ok'=>bool,'id'=>string,'telegram'=>bool,'mail'=>int]
 */
function pato_capturar(string $bitacora, array $rec, string $titulo = 'Nuevo registro'): array
{
    if (empty($rec['id'])) {
        $rec['id'] = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
    }
    // 1) GUARDAR. Si esto falla, es un error real y el caller debe saberlo.
    $guardado = pato_append($bitacora, $rec);
    if (!$guardado) {
        return ['ok' => false, 'id' => $rec['id'], 'telegram' => false, 'mail' => 0,
                'error' => 'no se pudo guardar'];
    }
    // 2) AVISAR (best-effort). Que falle no pierde el registro.
    $lineas = [pato_cfg('marca', 'Pato') . ' — ' . $titulo];
    foreach ($rec as $k => $v) {
        if (in_array($k, ['ua', 'at', 'id'], true)) continue;
        if (is_array($v)) $v = implode(', ', $v);
        if ((string) $v === '') continue;
        $lineas[] = $k . ': ' . $v;
    }
    $lineas[] = 'folio: ' . $rec['id'];
    $texto = implode("\n", $lineas);

    $tg = pato_telegram($texto);
    $ml = pato_mail(pato_cfg('marca', 'Pato') . ' — ' . $titulo, $texto);

    // Si nadie se entero, queda anotado: es reparable, pero no puede pasar en silencio.
    if (!$tg && $ml === 0) {
        pato_append('avisos-fallidos.jsonl', ['ref' => $rec['id'], 'titulo' => $titulo]);
    }
    return ['ok' => true, 'id' => $rec['id'], 'telegram' => $tg, 'mail' => $ml];
}

/** Liga de WhatsApp con el mensaje ya armado (el canal de venta real en LatAm). */
function pato_wa_link(string $mensaje, ?string $numero = null): string
{
    $num = $numero ?: (string) pato_cfg('whatsapp', '');
    $num = preg_replace('/[^0-9]/', '', $num);
    return 'https://wa.me/' . $num . '?text=' . rawurlencode($mensaje);
}
