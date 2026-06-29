<?php
// Endpoint de SINCRONIZACIÓN con PATO (autenticado por token compartido, gitignored).
//   POST ?action=push   body=snapshot.json         -> PATO publica contenido pendiente + analíticas
//   GET  ?action=inbox                              -> PATO lee las acciones del fundador
//   POST ?action=ack    body={"n": <líneas>}        -> PATO confirma; se truncan esas líneas de la bandeja
// El token va en data/sync-token.txt (mismo valor que secrets/panel-tokens.json del lado PATO).
declare(strict_types=1);
require __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');

$token = $_SERVER['HTTP_X_PANEL_TOKEN'] ?? ($_GET['token'] ?? '');
if (!pnl_sync_ok(is_string($token) ? $token : '')) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'token']);
    exit;
}
pnl_boot();
$action = (string) ($_GET['action'] ?? '');

if ($action === 'push' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'json']); exit; }
    @file_put_contents(PNL_SNAP, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'inbox' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $lines = is_file(PNL_INBOX) ? file(PNL_INBOX, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $items = [];
    foreach ($lines as $l) { $r = json_decode($l, true); if (is_array($r)) $items[] = $r; }
    echo json_encode(['ok' => true, 'count' => count($items), 'items' => $items]);
    exit;
}

if ($action === 'ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $n = (int) ($body['n'] ?? 0);
    $lines = is_file(PNL_INBOX) ? file(PNL_INBOX, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $rest = ($n > 0 && $n <= count($lines)) ? array_slice($lines, $n) : [];
    @file_put_contents(PNL_INBOX, $rest ? implode("\n", $rest) . "\n" : '', LOCK_EX);
    echo json_encode(['ok' => true, 'remaining' => count($rest)]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'action']);
