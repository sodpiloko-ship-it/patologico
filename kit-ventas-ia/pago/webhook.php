<?php
declare(strict_types=1);

// Aviso de Mercado Pago (webhook o IPN). No se confía en el aviso: se consulta el pago en la API
// con nuestro Access Token y solo entonces se actualiza la compra (ptlg_procesar_pago).
require __DIR__ . '/lib.php';

header('Content-Type: text/plain; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');

$raw = (string)file_get_contents('php://input');
$cuerpo = json_decode($raw, true);
$cuerpo = is_array($cuerpo) ? $cuerpo : [];

// PHP convierte "data.id" de la URL en "data_id".
$tipo = (string)($_GET['type'] ?? $_GET['topic'] ?? $cuerpo['type'] ?? $cuerpo['topic'] ?? '');
$id = (string)($_GET['data_id'] ?? $_GET['id'] ?? ($cuerpo['data']['id'] ?? '') ?: ($cuerpo['resource'] ?? ''));
if (preg_match('~(\d{1,20})/?\z~', $id, $m) === 1) $id = $m[1];

// Firma opcional: si la config trae 'webhook_secret' (panel de Mercado Pago > Webhooks), se exige.
$cfg = ptlg_config();
$secreto = is_array($cfg) && is_string($cfg['webhook_secret'] ?? null) ? trim($cfg['webhook_secret']) : '';
if ($secreto !== '' && isset($_GET['data_id'])) {
  $firma = (string)($_SERVER['HTTP_X_SIGNATURE'] ?? '');
  $requestId = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
  $partes = [];
  foreach (explode(',', $firma) as $par) {
    $kv = explode('=', trim($par), 2);
    if (count($kv) === 2) $partes[trim($kv[0])] = trim($kv[1]);
  }
  $manifiesto = 'id:' . strtolower((string)$_GET['data_id']) . ';request-id:' . $requestId . ';ts:' . ($partes['ts'] ?? '') . ';';
  if (empty($partes['v1']) || !hash_equals(hash_hmac('sha256', $manifiesto, $secreto), $partes['v1'])) {
    ptlg_log('firma_invalida', ['id' => $id]);
    http_response_code(401);
    echo 'firma';
    exit;
  }
}

if ($tipo !== 'payment' || $id === '') {
  echo 'ok';
  exit;
}

$r = ptlg_procesar_pago($id);
if (($r['error'] ?? '') === 'api') {
  http_response_code(500); // Mercado Pago reintenta más tarde
  echo 'reintentar';
  exit;
}
ptlg_log('webhook', ['pago' => $id, 'estado' => $r['compra']['estado'] ?? ($r['error'] ?? '')]);
echo 'ok';
