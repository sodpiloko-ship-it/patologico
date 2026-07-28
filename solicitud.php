<?php
/**
 * PATO — captura de SOLICITUD DE DIAGNÓSTICO (el funnel del producto).
 *
 * Existe para que el formulario de /solicitar.html NO muera en el navegador. Es la lección
 * más cara del ecosistema: un checkout puede mostrar "gracias" y descartar TODO pedido.
 * Aquí el lead se guarda SIEMPRE del lado del servidor antes de intentar avisar.
 *
 * Orden de operaciones (a prueba de fallos):
 *   1) guarda en data/solicitudes.jsonl   <- si esto falla, respondemos error
 *   2) avisa por Telegram                 <- si falla, el lead YA está guardado
 *   3) avisa por correo                   <- idem
 *
 * SEGURIDAD: el token de Telegram NUNCA vive en el repo. Se lee, en este orden:
 *   1) variable de entorno TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID
 *   2) secrets/telegram.json -> {"bot_token":"...","chat_id":"..."} (subir A MANO al server)
 * La carpeta data/ y secrets/ se blindan con .htaccess (no legibles por web).
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

$NOTIFY = ['sodpiloko@gmail.com'];
$DATA_DIR = __DIR__ . '/data';
$STORE = $DATA_DIR . '/solicitudes.jsonl';

// --- blindaje de carpetas sensibles (idempotente) ---
foreach ([$DATA_DIR, __DIR__ . '/secrets'] as $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $h = $dir . '/.htaccess';
    if (!is_file($h)) @file_put_contents($h, "Require all denied\nDeny from all\n");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$d = json_decode($raw, true);
if (!is_array($d)) {
    // fallback: envío como formulario clásico
    $d = $_POST;
}
if (!is_array($d) || !$d) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'sin datos']);
    exit;
}

function pv($d, $k, $max = 400) {
    $v = isset($d[$k]) ? $d[$k] : '';
    if (is_array($v)) $v = implode(', ', $v);
    $v = trim((string) $v);
    if (function_exists('mb_substr')) return mb_substr($v, 0, $max);
    return substr($v, 0, $max);
}

$nombre  = pv($d, 'nombre', 120);
$correo  = pv($d, 'correo', 160);
$whats   = pv($d, 'whatsapp', 40);
$negocio = pv($d, 'negocio', 160);

// Contacto mínimo: sin correo ni WhatsApp el lead no sirve.
if ($correo === '' && $whats === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'faltan datos de contacto']);
    exit;
}
if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'correo inválido']);
    exit;
}

$rec = [
    'id'          => date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 6),
    'at'          => date('c'),
    'nombre'      => $nombre,
    'correo'      => $correo,
    'whatsapp'    => $whats,
    'interes'     => pv($d, 'interes', 40),
    'negocio'     => $negocio,
    'giro'        => pv($d, 'giro', 80),
    'ciudad'      => pv($d, 'ciudad', 80),
    'antiguedad'  => pv($d, 'antiguedad', 80),
    'producto'    => pv($d, 'producto', 300),
    'ticket'      => pv($d, 'ticket', 80),
    'catalogo'    => pv($d, 'catalogo', 80),
    'canales'     => pv($d, 'canales', 200),
    'herramientas'=> pv($d, 'herramientas', 200),
    'ventas_mes'  => pv($d, 'ventas_mes', 80),
    'objetivos'   => pv($d, 'objetivos', 300),
    'reto'        => pv($d, 'reto', 400),
    'origen'      => pv($d, 'origen', 120),
    'ua'          => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
];

// --- 1) GUARDAR primero. Si esto falla, es un error real. ---
$ok = @file_put_contents(
    $STORE,
    json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n",
    FILE_APPEND | LOCK_EX
);
if ($ok === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'no se pudo guardar la solicitud']);
    exit;
}

// --- 2) Telegram (best-effort) ---
function pato_tg_cfg() {
    $t = getenv('TELEGRAM_BOT_TOKEN');
    $c = getenv('TELEGRAM_CHAT_ID');
    if ($t && $c) return [$t, $c];
    $f = __DIR__ . '/secrets/telegram.json';
    if (is_file($f)) {
        $j = json_decode((string) @file_get_contents($f), true);
        if (is_array($j) && !empty($j['bot_token']) && !empty($j['chat_id'])) {
            return [$j['bot_token'], $j['chat_id']];
        }
    }
    return [null, null];
}

$tg_ok = false;
list($tok, $chat) = pato_tg_cfg();
if ($tok && $chat) {
    $msg = "🦆 NUEVA SOLICITUD DE DIAGNÓSTICO\n"
         . "Negocio: " . ($negocio ?: '—') . "\n"
         . "Nombre: " . ($nombre ?: '—') . "\n"
         . "Correo: " . ($correo ?: '—') . "\n"
         . "WhatsApp: " . ($whats ?: '—') . "\n"
         . "Giro: " . ($rec['giro'] ?: '—') . " · " . ($rec['ciudad'] ?: '—') . "\n"
         . "Ventas/mes: " . ($rec['ventas_mes'] ?: '—') . " · Ticket: " . ($rec['ticket'] ?: '—') . "\n"
         . "Reto: " . ($rec['reto'] ?: '—');
    $url = "https://api.telegram.org/bot{$tok}/sendMessage";
    $post = http_build_query(['chat_id' => $chat, 'text' => $msg]);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post, CURLOPT_TIMEOUT => 8,
        ]);
        $tg_ok = curl_exec($ch) !== false;
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 8,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $post,
        ]]);
        $tg_ok = @file_get_contents($url, false, $ctx) !== false;
    }
}

// --- 3) Correo (best-effort) ---
$cuerpo = "Nueva solicitud de diagnóstico\n\n";
foreach ($rec as $k => $v) {
    if ($k === 'ua') continue;
    $cuerpo .= str_pad($k, 13) . ': ' . $v . "\n";
}
foreach ($NOTIFY as $to) {
    @mail($to, 'Pato — solicitud de diagnóstico: ' . ($negocio ?: $nombre ?: 'sin nombre'),
          $cuerpo, "From: no-reply@patologicos.com\r\nContent-Type: text/plain; charset=utf-8");
}

echo json_encode(['ok' => true, 'id' => $rec['id'], 'notificado' => $tg_ok], JSON_UNESCAPED_UNICODE);
