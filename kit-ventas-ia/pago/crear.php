<?php
declare(strict_types=1);

// Crea la preferencia de Checkout Pro y manda al comprador a pagar en Mercado Pago.
// El precio y el producto salen de lib.php (servidor), nunca de la URL.
require __DIR__ . '/lib.php';

if (ptlg_config() === false) {
  ptlg_log('sin_config');
  ptlg_error('Estamos terminando de configurar el pago. Vuelve en un rato o escríbenos y te lo resolvemos hoy.');
  exit;
}

$folio = ptlg_nuevo_folio();
$utm = [];
foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'pos', 'ab'] as $clave) {
  $valor = (string)($_GET[$clave] ?? '');
  if ($valor !== '' && preg_match('/\A[\w.\-|]{1,80}\z/', $valor) === 1) $utm[$clave] = $valor;
}

$compra = [
  'folio' => $folio,
  'producto' => PTLG_PRODUCTO,
  'estado' => 'creado',
  'monto' => PTLG_PRECIO,
  'moneda' => PTLG_MONEDA,
  'creado' => gmdate('c'),
  'utm' => $utm,
  'avisos' => [],
];
if (!ptlg_guardar_compra($compra)) {
  ptlg_log('sin_almacen', ['folio' => $folio]);
  ptlg_error('No pudimos preparar tu compra. Escríbenos y te ayudamos a completarla.');
  exit;
}

$base = ptlg_base_url();
$preferencia = [
  'items' => [[
    'id' => PTLG_PRODUCTO,
    'title' => PTLG_TITULO,
    'description' => 'Playbook en PDF para ejecutar con tu IA + prompts + hoja de seguimiento',
    'quantity' => 1,
    'currency_id' => PTLG_MONEDA,
    'unit_price' => PTLG_PRECIO,
  ]],
  'external_reference' => $folio,
  'back_urls' => [
    'success' => $base . '/kit-ventas-ia/gracias/',
    'pending' => $base . '/kit-ventas-ia/gracias/',
    'failure' => $base . '/kit-ventas-ia/gracias/',
  ],
  'auto_return' => 'approved',
  'notification_url' => $base . '/kit-ventas-ia/pago/webhook.php',
  'statement_descriptor' => 'PATOLOGICOS',
  'payment_methods' => ['installments' => 1],
  'metadata' => ['folio' => $folio, 'producto' => PTLG_PRODUCTO],
];

list($status, $respuesta) = ptlg_mp('POST', '/checkout/preferences', $preferencia);
if ($status < 200 || $status >= 300 || !is_array($respuesta) || !is_string($respuesta['init_point'] ?? null)) {
  ptlg_log('preferencia_error', ['folio' => $folio, 'status' => $status]);
  ptlg_error('Mercado Pago no respondió. Intenta de nuevo en unos minutos o escríbenos.');
  exit;
}

$compra['preferencia_id'] = (string)($respuesta['id'] ?? '');
ptlg_guardar_compra($compra);

$cfg = ptlg_config();
$destino = !empty($cfg['sandbox']) && is_string($respuesta['sandbox_init_point'] ?? null)
  ? $respuesta['sandbox_init_point']
  : $respuesta['init_point'];
ptlg_security_headers();
header('Location: ' . $destino, true, 303);
