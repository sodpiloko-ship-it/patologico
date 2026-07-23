<?php
// Agenda del día de producción (.ics): evento con las velas por hacer de los pedidos CONFIRMADOS.
// Solo con sesión de la comanda. Día por defecto: mañana (sobre pedido, envío a 2 días del pago).
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (!cmd_current()) { http_response_code(403); exit('Necesitas entrar a la comanda.'); }

$dia = cmd_dia_produccion();
$q = (string) ($_GET['d'] ?? '');
if ($q !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $q)) {
    try { $dia = new DateTime($q); } catch (Throwable $t) {}
}

$conf = cmd_confirmados();
$L = [];
$total = 0.0;
foreach ($conf as $o) {
    $total += (float) ($o['total'] ?? 0);
    $L[] = '· ' . (($o['nombre'] ?? '') !== '' ? $o['nombre'] : 'Sin nombre')
         . ((($o['aromas'] ?? '') !== '') ? ' (aromas: ' . $o['aromas'] . ')' : '')
         . ' — ' . implode(' / ', (array) ($o['items'] ?? []));
}
$desc = count($conf)
    ? "Velas por hacer de " . count($conf) . " pedido(s) confirmados — total $" . number_format($total, 0) . "\n\n" . implode("\n", $L)
    : "Sin pedidos confirmados por ahora. Revisa la comanda antes de producir.";

$dt = $dia->format('Ymd');
$uid = 'produccion-' . $dt . '@velaslumin.com';
$now = gmdate('Ymd\THis\Z');
$summary = 'Producción Lumin (' . count($conf) . ' pedidos)';
$esc = fn(string $s): string => str_replace(["\\", "\n", ",", ";"], ["\\\\", "\\n", "\\,", "\\;"], $s);

$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Lumin Candele//Comanda//ES\r\n"
     . "BEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTAMP:{$now}\r\n"
     . "DTSTART:{$dt}T090000\r\nDTEND:{$dt}T120000\r\n"
     . "SUMMARY:" . $esc($summary) . "\r\n"
     . "DESCRIPTION:" . $esc($desc) . "\r\n"
     . "END:VEVENT\r\nEND:VCALENDAR\r\n";

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="produccion-lumin-' . $dt . '.ics"');
echo $ics;
