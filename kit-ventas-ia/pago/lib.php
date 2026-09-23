<?php
declare(strict_types=1);

/*
 * Checkout Pro de Mercado Pago para el playbook "Oferta, Tráfico y WhatsApp".
 *
 * El repo de despliegue es PÚBLICO: aquí no hay secretos ni datos de compradores.
 * Todo eso vive fuera del document root, en una carpeta hermana de public_html:
 *
 *   <padre de public_html>/patologicos-private/mercadopago.php       config con el Access Token (lo coloca David en hPanel)
 *   <padre de public_html>/patologicos-private/kit-ventas-ia/        kit-ventas-ia.zip + compras/ + correos/ + secreto.key
 *
 * Flujo: crear.php (preferencia) → Mercado Pago → webhook.php y gracias/ (verifican el pago contra la API)
 *        → descargar.php (link firmado que expira). Nunca se confía en los parámetros de la URL para dar acceso.
 */

if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
  http_response_code(404);
  exit;
}

const PTLG_PRODUCTO = 'kit-ventas-ia';
const PTLG_TITULO = 'Playbook Oferta, Tráfico y WhatsApp';
const PTLG_PRECIO = 349.0;
const PTLG_MONEDA = 'MXN';
const PTLG_ZIP = 'kit-ventas-ia.zip';
const PTLG_ZIP_NOMBRE = 'Oferta-Trafico-WhatsApp.zip';
const PTLG_DESCARGA_DIAS = 30;
const PTLG_DESCARGA_MAX = 20;
const PTLG_DIAS_DUDAS = 90;
const PTLG_AVISO_A = 'hola@patologicos.com';
const PTLG_REMITENTE = 'no-reply@patologicos.com';
const PTLG_FOLIO_RE = '/\AOTW-\d{6}-[A-F0-9]{8}\z/';
const PTLG_PAGO_RE = '/\A\d{1,20}\z/';

// ---------------------------------------------------------------- utilidades

function ptlg_security_headers(): void {
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: DENY');
  header('Referrer-Policy: no-referrer');
  header('Cache-Control: no-store, private, max-age=0');
  header('X-Robots-Tag: noindex, nofollow, noarchive');
}

function ptlg_h($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ptlg_log(string $evento, array $datos = []): void {
  $dir = ptlg_dir('');
  if ($dir === false) return;
  $linea = json_encode(['at' => gmdate('c'), 'evento' => $evento] + $datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  @file_put_contents($dir . '/eventos.log', $linea . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// ---------------------------------------------------------------- carpeta privada

function ptlg_path_within(string $path, string $root): bool {
  $path = str_replace('\\', '/', rtrim($path, '\\/')) . '/';
  $root = str_replace('\\', '/', rtrim($root, '\\/')) . '/';
  if (DIRECTORY_SEPARATOR === '\\') {
    $path = strtolower($path);
    $root = strtolower($root);
  }
  return strpos($path, $root) === 0;
}

/** Raíz privada (debe existir y estar FUERA del document root) o false. */
function ptlg_private_root() {
  static $cache = null;
  if ($cache !== null) return $cache;
  $docroot = @realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
  if ($docroot === false || !is_dir($docroot)) return $cache = false;
  $configurada = trim((string)getenv('PTLG_PRIVATE_DIR'));
  $candidata = $configurada !== '' ? $configurada : dirname($docroot) . DIRECTORY_SEPARATOR . 'patologicos-private';
  $real = @realpath($candidata);
  if ($real === false || !is_dir($real) || is_link($candidata) || ptlg_path_within($real, $docroot)) return $cache = false;
  return $cache = $real;
}

function ptlg_private_mode(string $path, int $mode): bool {
  if (DIRECTORY_SEPARATOR === '\\') return true; // solo desarrollo local: Windows no tiene permisos POSIX
  if (!@chmod($path, $mode)) return false;
  clearstatcache(true, $path);
  $perms = @fileperms($path);
  return is_int($perms) && (($perms & 0777) === $mode);
}

/** Subcarpeta privada del producto (se crea con 0700 y un .htaccess que niega todo). */
function ptlg_dir(string $sub) {
  $root = ptlg_private_root();
  if ($root === false) return false;
  $dir = $root . DIRECTORY_SEPARATOR . PTLG_PRODUCTO . ($sub !== '' ? DIRECTORY_SEPARATOR . $sub : '');
  if (!is_dir($dir)) {
    $old = umask(0077);
    $ok = @mkdir($dir, 0700, true);
    umask($old);
    if (!$ok && !is_dir($dir)) return false;
  }
  if (!ptlg_private_mode($dir, 0700)) return false;
  $guard = $dir . DIRECTORY_SEPARATOR . '.htaccess';
  if (!is_file($guard)) @file_put_contents($guard, "Require all denied\nDeny from all\n", LOCK_EX);
  return $dir;
}

function ptlg_escribir_privado(string $path, string $contenido): bool {
  $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
  $old = umask(0077);
  $n = @file_put_contents($tmp, $contenido, LOCK_EX);
  umask($old);
  if ($n === false || $n !== strlen($contenido) || !ptlg_private_mode($tmp, 0600)) {
    @unlink($tmp);
    return false;
  }
  if (DIRECTORY_SEPARATOR === '\\' && is_file($path)) @unlink($path);
  if (!@rename($tmp, $path)) {
    @unlink($tmp);
    return false;
  }
  return true;
}

// ---------------------------------------------------------------- configuración y API

/** Config de Mercado Pago (la coloca David fuera del repo) o false si falta. */
function ptlg_config() {
  static $cfg = null;
  if ($cfg !== null) return $cfg;
  $root = ptlg_private_root();
  if ($root === false) return $cfg = false;
  $file = $root . DIRECTORY_SEPARATOR . 'mercadopago.php';
  if (!is_file($file)) return $cfg = false;
  $data = include $file;
  if (!is_array($data) || !is_string($data['access_token'] ?? null) || trim($data['access_token']) === '') return $cfg = false;
  return $cfg = $data;
}

function ptlg_base_url(): string {
  $cfg = ptlg_config();
  $base = is_array($cfg) && is_string($cfg['base_url'] ?? null) ? $cfg['base_url'] : 'https://patologicos.com';
  return rtrim($base, '/');
}

/** Llamada HTTP a la API de Mercado Pago. Devuelve [status, json|null]. */
function ptlg_mp(string $metodo, string $ruta, ?array $cuerpo = null): array {
  $cfg = ptlg_config();
  if ($cfg === false) return [0, null];
  $base = is_string($cfg['api_base'] ?? null) ? rtrim($cfg['api_base'], '/') : 'https://api.mercadopago.com';
  $url = $base . $ruta;
  $headers = [
    'Authorization: Bearer ' . trim((string)$cfg['access_token']),
    'Accept: application/json',
  ];
  $payload = null;
  if ($cuerpo !== null) {
    $payload = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headers[] = 'Content-Type: application/json';
    $headers[] = 'X-Idempotency-Key: ' . bin2hex(random_bytes(16));
  }
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_CUSTOMREQUEST => $metodo,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 20,
      CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
  } else {
    $ctx = stream_context_create(['http' => [
      'method' => $metodo,
      'header' => implode("\r\n", $headers),
      'content' => $payload ?? '',
      'timeout' => 20,
      'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach (($http_response_header ?? []) as $h) {
      if (preg_match('~\AHTTP/\S+\s+(\d{3})~', $h, $m)) $status = (int)$m[1];
    }
  }
  if (!is_string($resp)) return [$status, null];
  $json = json_decode($resp, true);
  return [$status, is_array($json) ? $json : null];
}

// ---------------------------------------------------------------- compras

function ptlg_nuevo_folio(): string {
  return 'OTW-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function ptlg_compra_path(string $folio) {
  if (preg_match(PTLG_FOLIO_RE, $folio) !== 1) return false;
  $dir = ptlg_dir('compras');
  return $dir === false ? false : $dir . DIRECTORY_SEPARATOR . $folio . '.json';
}

function ptlg_leer_compra(string $folio): ?array {
  $path = ptlg_compra_path($folio);
  if ($path === false || !is_file($path)) return null;
  $data = json_decode((string)@file_get_contents($path), true);
  return is_array($data) ? $data : null;
}

function ptlg_guardar_compra(array $compra): bool {
  $path = ptlg_compra_path((string)($compra['folio'] ?? ''));
  if ($path === false) return false;
  $compra['actualizado'] = gmdate('c');
  $json = json_encode($compra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  return $json !== false && ptlg_escribir_privado($path, $json . PHP_EOL);
}

/** Ejecuta $fn con el candado de la compra (webhook y página de gracias pueden llegar a la vez). */
function ptlg_con_candado(string $folio, callable $fn) {
  $dir = ptlg_dir('compras');
  if ($dir === false || preg_match(PTLG_FOLIO_RE, $folio) !== 1) return null;
  $lock = @fopen($dir . DIRECTORY_SEPARATOR . $folio . '.lock', 'c');
  if (!is_resource($lock)) return null;
  flock($lock, LOCK_EX);
  try {
    return $fn();
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

// ---------------------------------------------------------------- descargas firmadas

function ptlg_secreto(): string {
  $dir = ptlg_dir('');
  if ($dir === false) return '';
  $file = $dir . DIRECTORY_SEPARATOR . 'secreto.key';
  $valor = is_file($file) ? trim((string)@file_get_contents($file)) : '';
  if (preg_match('/\A[a-f0-9]{64}\z/', $valor) === 1) return $valor;
  $valor = bin2hex(random_bytes(32));
  return ptlg_escribir_privado($file, $valor) ? $valor : '';
}

function ptlg_token(string $folio): string {
  $secreto = ptlg_secreto();
  return $secreto === '' ? '' : hash_hmac('sha256', PTLG_PRODUCTO . '|' . $folio, $secreto);
}

function ptlg_link_descarga(string $folio): string {
  return ptlg_base_url() . '/kit-ventas-ia/pago/descargar.php?f=' . rawurlencode($folio) . '&t=' . ptlg_token($folio);
}

// ---------------------------------------------------------------- pagos

const PTLG_ORDEN_ESTADOS = ['creado' => 0, 'rechazado' => 1, 'pendiente' => 2, 'pagado' => 3, 'reembolsado' => 4];

function ptlg_estado_mp(string $status): string {
  if ($status === 'approved') return 'pagado';
  if (in_array($status, ['pending', 'in_process', 'authorized', 'in_mediation'], true)) return 'pendiente';
  if (in_array($status, ['refunded', 'charged_back'], true)) return 'reembolsado';
  return 'rechazado';
}

/**
 * Verifica un pago contra la API de Mercado Pago y actualiza la compra.
 * Devuelve ['compra' => array] o ['error' => 'api'|'desconocido'|'invalido'].
 */
function ptlg_procesar_pago(string $pagoId): array {
  if (preg_match(PTLG_PAGO_RE, $pagoId) !== 1) return ['error' => 'invalido'];
  list($status, $pago) = ptlg_mp('GET', '/v1/payments/' . $pagoId);
  if ($status === 404) return ['error' => 'desconocido'];
  if ($status < 200 || $status >= 300 || !is_array($pago)) {
    ptlg_log('api_error', ['pago' => $pagoId, 'status' => $status]);
    return ['error' => 'api'];
  }
  $folio = (string)($pago['external_reference'] ?? '');
  if (preg_match(PTLG_FOLIO_RE, $folio) !== 1) return ['error' => 'desconocido'];

  $resultado = ptlg_con_candado($folio, function () use ($folio, $pago, $pagoId) {
    $compra = ptlg_leer_compra($folio);
    if ($compra === null) return ['error' => 'desconocido'];
    $monto_ok = abs((float)($pago['transaction_amount'] ?? 0) - PTLG_PRECIO) < 0.01
      && (string)($pago['currency_id'] ?? '') === PTLG_MONEDA;
    $nuevo = ptlg_estado_mp((string)($pago['status'] ?? ''));
    if ($nuevo === 'pagado' && !$monto_ok) {
      ptlg_log('monto_invalido', ['folio' => $folio, 'pago' => $pagoId]);
      $nuevo = 'rechazado';
    }
    $actual = (string)($compra['estado'] ?? 'creado');
    // Nunca se retrocede de estado (un aviso viejo de "pendiente" no des-paga una compra).
    if ((PTLG_ORDEN_ESTADOS[$nuevo] ?? 0) >= (PTLG_ORDEN_ESTADOS[$actual] ?? 0)) {
      $compra['estado'] = $nuevo;
    }
    $compra['pago_id'] = $pagoId;
    $compra['metodo'] = (string)($pago['payment_method_id'] ?? '') . '/' . (string)($pago['payment_type_id'] ?? '');
    $correo = (string)($pago['payer']['email'] ?? '');
    if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL)) $compra['correo'] = $correo;
    if ($compra['estado'] === 'pagado' && empty($compra['pagado_en'])) $compra['pagado_en'] = gmdate('c');
    if ($compra['estado'] === 'reembolsado' && empty($compra['reembolsado_en'])) $compra['reembolsado_en'] = gmdate('c');
    $avisos = is_array($compra['avisos'] ?? null) ? $compra['avisos'] : [];
    if ($compra['estado'] === 'pagado' && empty($avisos['comprador']) && !empty($compra['correo'])) {
      $avisos['comprador'] = ptlg_aviso_comprador($compra) ? gmdate('c') : 'fallo ' . gmdate('c');
    }
    if ($compra['estado'] === 'pagado' && empty($avisos['venta'])) {
      $avisos['venta'] = ptlg_aviso_venta($compra) ? gmdate('c') : 'fallo ' . gmdate('c');
    }
    $compra['avisos'] = $avisos;
    ptlg_guardar_compra($compra);
    return ['compra' => $compra];
  });
  return is_array($resultado) ? $resultado : ['error' => 'api'];
}

// ---------------------------------------------------------------- correo

/** Guarda una copia privada del correo y lo envía. */
function ptlg_mail(string $para, string $asunto, string $cuerpo, string $tipo, string $folio): bool {
  $dir = ptlg_dir('correos');
  if ($dir !== false) {
    ptlg_escribir_privado($dir . DIRECTORY_SEPARATOR . $folio . '-' . $tipo . '.txt',
      "Para: $para\nAsunto: $asunto\n\n$cuerpo\n");
  }
  $headers = 'From: Patológicos <' . PTLG_REMITENTE . ">\r\n"
    . 'Reply-To: ' . PTLG_AVISO_A . "\r\n"
    . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
  return @mail($para, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $cuerpo, $headers);
}

function ptlg_prompt_arranque(): string {
  return 'Te adjunto el PDF «Oferta, Tráfico y WhatsApp». Léelo completo y sigue al pie de la letra la sección '
    . '«Instrucciones para tu asistente de IA»: arma conmigo la Ficha de mi negocio y guíame etapa por etapa. Empieza.';
}

function ptlg_aviso_comprador(array $compra): bool {
  $cuerpo = implode("\n", [
    '¡Gracias por tu compra!',
    '',
    'Descarga tu playbook aquí (el enlace funciona ' . PTLG_DESCARGA_DIAS . ' días):',
    ptlg_link_descarga((string)$compra['folio']),
    '',
    'Cómo empezar en 2 minutos:',
    '1. Descomprime el archivo y abre ChatGPT, Claude o Gemini.',
    '2. Adjunta el PDF Oferta-Trafico-WhatsApp.pdf a una conversación nueva.',
    '3. Pega este mensaje y envíalo:',
    '',
    ptlg_prompt_arranque(),
    '',
    '¿Dudas? Tienes ' . PTLG_DIAS_DUDAS . ' días de dudas por WhatsApp:',
    ptlg_base_url() . '/kit-ventas-ia/dudas/',
    'Escríbenos con este mismo correo para identificar tu compra.',
    '',
    'Folio de compra: ' . $compra['folio'],
    '— Patológicos · patologicos.com',
  ]);
  return ptlg_mail((string)$compra['correo'], 'Tu playbook Oferta, Tráfico y WhatsApp está listo', $cuerpo, 'comprador', (string)$compra['folio']);
}

function ptlg_aviso_venta(array $compra): bool {
  $utm = is_array($compra['utm'] ?? null) ? $compra['utm'] : [];
  $cuerpo = implode("\n", [
    'Venta nueva del playbook Oferta, Tráfico y WhatsApp.',
    '',
    'Folio: ' . $compra['folio'],
    'Monto: $' . number_format(PTLG_PRECIO, 2) . ' ' . PTLG_MONEDA,
    'Correo del comprador: ' . ($compra['correo'] ?? '(sin correo)'),
    'Pago de Mercado Pago: ' . ($compra['pago_id'] ?? '') . ' · ' . ($compra['metodo'] ?? ''),
    'Origen: ' . ($utm ? json_encode($utm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'directo'),
    '',
    'Esta persona tiene ' . PTLG_DIAS_DUDAS . ' días de dudas por WhatsApp desde hoy.',
  ]);
  return ptlg_mail(PTLG_AVISO_A, 'Venta nueva · ' . $compra['folio'], $cuerpo, 'venta', (string)$compra['folio']);
}

// ---------------------------------------------------------------- páginas

function ptlg_pagina(string $titulo, string $html): void {
  ptlg_security_headers();
  header('Content-Type: text/html; charset=UTF-8');
  echo '<!DOCTYPE html><html lang="es-MX"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
    . '<title>' . ptlg_h($titulo) . ' | Pato</title>'
    . '<link rel="icon" href="/favicon.svg" type="image/svg+xml">'
    . '<style>body{margin:0;background:#F4F3EE;color:#191A16;font-family:"Hanken Grotesk",system-ui,-apple-system,sans-serif;font-size:17px;line-height:1.55}'
    . '.wrap{max-width:620px;margin:0 auto;padding:40px 20px 64px}h1{font-family:"Space Grotesk",system-ui,sans-serif;letter-spacing:-.02em;line-height:1.1;font-size:34px;margin:0 0 12px}'
    . '.btn{display:inline-block;background:#C8ED4B;color:#191A16;font-weight:700;padding:15px 24px;border-radius:999px;text-decoration:none;margin:8px 0 18px}'
    . '.box{background:#FBFAF7;border:1px solid #E0DED5;border-radius:14px;padding:18px;margin:18px 0}'
    . '.prompt{background:#191A16;color:#EDEDE6;border-radius:10px;padding:12px 14px;font-family:Consolas,monospace;font-size:13.5px;white-space:pre-wrap}'
    . '.muted{color:#5E5F57;font-size:14px}a{color:inherit}</style></head><body><div class="wrap">'
    . '<p style="font-weight:700;font-size:20px;margin:0 0 28px"><a href="/" style="text-decoration:none">pato</a></p>'
    . $html . '</div></body></html>';
}

function ptlg_error(string $mensaje, int $status = 503): void {
  http_response_code($status);
  ptlg_pagina('No pudimos continuar', '<h1>No pudimos continuar</h1><p>' . ptlg_h($mensaje) . '</p>'
    . '<p>Escríbenos y lo resolvemos: <a href="/kit-ventas-ia/dudas/">patologicos.com/kit-ventas-ia/dudas</a> o ' . ptlg_h(PTLG_AVISO_A) . '.</p>');
}
