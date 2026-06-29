<?php
// Verifica el magic-link (?e=email&t=token) -> inicia sesión -> redirige al panel.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$email = (string) ($_GET['e'] ?? '');
$raw   = (string) ($_GET['t'] ?? '');
if ($email && $raw && pnl_verify_token($email, $raw)) {
    pnl_login($email);
    header('Location: ./');
    exit;
}
http_response_code(403);
$c = pnl_cfg();
echo '<!doctype html><meta charset="utf-8"><title>Enlace inválido</title>'
   . '<body style="font-family:system-ui;background:#121110;color:#f3efe8;text-align:center;padding:60px">'
   . '<h1>' . pnl_esc($c['emoji']) . ' Enlace inválido o vencido</h1>'
   . '<p>Pide uno nuevo desde <a style="color:' . pnl_esc($c['accent']) . '" href="./">el panel</a>.</p>';
