<?php
// Captura de pedidos del espejo Lumin (sandbox): guarda el pedido para la comanda.
// El cliente ademas abre WhatsApp con el pedido (placeOrder). Demo del patron Picando Tabla.
// PII: los pedidos viven en data/ (protegido por .htaccess + gitignored). Generado por PATO.
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$d = json_decode($raw, true);
if (!is_array($d) || empty($d['items'])) { http_response_code(400); echo json_encode(['ok' => false]); exit; }

$nombre = substr(strip_tags(trim($d['nombre']   ?? '')), 0, 120);
$wa     = substr(strip_tags(trim($d['whatsapp'] ?? '')), 0, 40);
$aromas = substr(strip_tags(trim($d['aromas']   ?? '')), 0, 200);
$total  = is_numeric($d['total'] ?? null) ? (float) $d['total'] : 0;

$lines = array();
foreach ($d['items'] as $it) {
  $qn = intval($it['qty'] ?? 0);
  $nm = substr(strip_tags($it['nombre'] ?? ''), 0, 120);
  $pr = is_numeric($it['precio'] ?? null) ? (float) $it['precio'] : 0;
  if ($nm !== '') $lines[] = $qn . 'x ' . $nm . ' ($' . number_format($pr, 0) . ')';
}
if (!count($lines)) { http_response_code(400); echo json_encode(array('ok' => false)); exit; }

$rec = array('at' => date('c'), 'nombre' => $nombre, 'whatsapp' => $wa, 'aromas' => $aromas,
             'total' => $total, 'items' => $lines, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '');
$dir = __DIR__ . '/data';
@mkdir($dir, 0755, true);
@file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
$json = json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($json !== false) @file_put_contents($dir . '/orders.jsonl', $json . PHP_EOL, FILE_APPEND | LOCK_EX);

echo json_encode(array('ok' => true));
