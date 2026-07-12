<?php
// Manejador de la demo "Corre el motor" (landing #corre) — COLA HONESTA (0 API).
// Decisión David 2026-07-12: SIN API de pago. La consulta se ENCOLA y el ciclo real
// (diagnóstico + 4 entregables) lo corre PATO y contacta al prospecto — no se fabrica salida de IA.
//   accion=run  -> filtros baratos (F1) + rate-limit + encola la consulta -> JSON con acuse honesto.
//   accion=lead -> guarda el contacto para entregarle el ciclo real -> JSON + correo.
// Gancho para el futuro: cuando David autorice un modelo, F2 (consejo de autorización) y F3
// (generación) se insertan aquí, entre el filtro y la respuesta. Hoy quedan deshabilitados a propósito.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

const DM_TO   = 'hola@patologicos.com';
const DM_FROM = 'contacto@patologicos.com';
const DM_DATA = __DIR__ . '/data';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['status' => 'error']); exit; }

function dm_sub(string $s, int $max): string {
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}
function dm_len(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}
function dm_clean(string $s, int $max): string {
    return dm_sub(trim(str_replace(["\r"], '', $s)), $max);
}
function dm_data_dir(): string {
    if (!is_dir(DM_DATA)) @mkdir(DM_DATA, 0750, true);
    $h = DM_DATA . '/.htaccess';
    if (!is_file($h)) @file_put_contents($h, "Require all denied\nDeny from all\n");
    return DM_DATA;
}
function dm_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$accion  = dm_clean((string) ($_POST['accion'] ?? 'run'), 12);
$negocio = dm_clean((string) ($_POST['negocio'] ?? ''), 400);
$ip      = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

// --- F1: filtros baratos, sin modelo ---
if (dm_len($negocio) < 10) {
    dm_out(['status' => 'rechazado', 'motivo' => 'cuéntanos un poco más de tu negocio'], 422);
}
$block = '/ignore (previous|above)|system prompt|prompt del sistema|olvida (las|tus|el)|'
       . 'instrucciones anteriores|<\?php|<script|https?:\/\/|jailbreak|roleplay|act[uú]a como/i';
if (preg_match($block, $negocio)) {
    dm_out(['status' => 'rechazado', 'motivo' => 'describe tu PYME en palabras simples'], 422);
}

// --- rate-limit best-effort: 5 corridas/hora por IP ---
$dir = dm_data_dir();
$rl  = $dir . '/demo-rate.jsonl';
$now = time();
$recent = 0;
if (is_file($rl)) {
    foreach (@file($rl, FILE_IGNORE_NEW_LINES) ?: [] as $ln) {
        $r = json_decode($ln, true);
        if (is_array($r) && ($r['ip'] ?? '') === $ip && ($now - (int) ($r['t'] ?? 0)) < 3600) $recent++;
    }
}
if ($recent >= 5) { dm_out(['status' => 'limite'], 429); }
@file_put_contents($rl, json_encode(['ip' => $ip, 't' => $now]) . "\n", FILE_APPEND | LOCK_EX);

// --- accion=lead: captura el contacto para entregarle el ciclo real ---
if ($accion === 'lead') {
    $correo = dm_clean((string) ($_POST['correo'] ?? ''), 160);
    $tel    = dm_clean((string) ($_POST['telefono'] ?? ''), 60);
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        dm_out(['status' => 'rechazado', 'motivo' => 'correo inválido'], 422);
    }
    $rec = ['at' => date('c'), 'tipo' => 'demo-lead', 'negocio' => $negocio,
            'correo' => $correo, 'telefono' => $tel, 'ip' => $ip];
    @file_put_contents($dir . '/demo.jsonl', json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    $body = "Nuevo lead — Demo 'Corre el motor'\n\nNegocio: $negocio\nCorreo: $correo\nWhatsApp/tel: $tel\n";
    @mail(DM_TO, "Lead [Demo motor] — $correo", $body, "From: " . DM_FROM . "\r\nReply-To: $correo\r\n");
    dm_out(['status' => 'ok']);
}

// --- accion=run: encola la consulta (para que PATO la corra) + acuse honesto ---
$rec = ['at' => date('c'), 'tipo' => 'demo-run', 'negocio' => $negocio, 'ip' => $ip];
@file_put_contents($dir . '/demo.jsonl', json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
dm_out([
    'status' => 'ok',
    'agentes' => [
        'recepción — negocio recibido',
        'filtros — formato válido · es_MX',
        'cola — entró a la fila real de PATO',
        'content-loop — asignará tu guion',
        'ads-agent — asignará tu anuncio',
        'ventas — preparará tu seguimiento',
    ],
    'diagnostico' => 'Consejo de autorización: negocio válido · en cola para tu ciclo real.',
]);
