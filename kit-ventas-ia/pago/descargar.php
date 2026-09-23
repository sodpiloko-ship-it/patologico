<?php
declare(strict_types=1);

// Entrega el zip a quien tenga el link firmado de una compra pagada (expira y tiene tope de descargas).
require __DIR__ . '/lib.php';

$folio = (string)($_GET['f'] ?? '');
$token = (string)($_GET['t'] ?? '');
if (preg_match(PTLG_FOLIO_RE, $folio) !== 1 || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
  ptlg_error('Este enlace de descarga no es válido.', 404);
  exit;
}
$esperado = ptlg_token($folio);
$compra = ptlg_leer_compra($folio);
if ($esperado === '' || !hash_equals($esperado, $token) || $compra === null) {
  ptlg_error('Este enlace de descarga no es válido.', 404);
  exit;
}
if (($compra['estado'] ?? '') !== 'pagado') {
  ptlg_error('Esta compra no está activa. Si crees que es un error, escríbenos con tu folio ' . $folio . '.', 403);
  exit;
}
$pagado = strtotime((string)($compra['pagado_en'] ?? '')) ?: 0;
if ($pagado + PTLG_DESCARGA_DIAS * 86400 < time()) {
  ptlg_error('Este enlace ya venció. Escríbenos con tu folio ' . $folio . ' y te mandamos uno nuevo.', 410);
  exit;
}
if ((int)($compra['descargas'] ?? 0) >= PTLG_DESCARGA_MAX) {
  ptlg_error('Este enlace llegó a su límite de descargas. Escríbenos con tu folio ' . $folio . ' y te ayudamos.', 429);
  exit;
}
$dir = ptlg_dir('');
$zip = $dir === false ? '' : $dir . DIRECTORY_SEPARATOR . PTLG_ZIP;
if ($zip === '' || !is_file($zip)) {
  ptlg_log('zip_faltante', ['folio' => $folio]);
  ptlg_error('El archivo no está disponible en este momento. Ya nos avisó el sistema; escríbenos y te lo mandamos.', 503);
  exit;
}

ptlg_con_candado($folio, function () use ($folio) {
  $c = ptlg_leer_compra($folio);
  if ($c === null) return;
  $c['descargas'] = (int)($c['descargas'] ?? 0) + 1;
  $c['ultima_descarga'] = gmdate('c');
  ptlg_guardar_compra($c);
});

ptlg_security_headers();
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . PTLG_ZIP_NOMBRE . '"');
header('Content-Length: ' . (string)filesize($zip));
readfile($zip);
