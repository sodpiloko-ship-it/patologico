<?php
// Panel de Fundador — núcleo reusable (mismo código en cada dominio; lo distingue config.php).
// Sin DB: todo en archivos protegidos bajo data/ (gitignored, jamás accesible por web).
// - Auth: magic-link por correo (sin password), allowlist de fundadores.
// - Datos: PATO publica data/snapshot.json (contenido pendiente + analíticas); el panel lo muestra.
// - Acciones: aprobar/rechazar/feedback -> data/inbox.jsonl; PATO lo lee, aplica y republica.
declare(strict_types=1);

const PNL_DATA  = __DIR__ . '/data';
const PNL_ACC   = __DIR__ . '/data/cuentas.jsonl';
const PNL_SNAP  = __DIR__ . '/data/snapshot.json';
const PNL_INBOX = __DIR__ . '/data/inbox.jsonl';
const PNL_TOKEN = __DIR__ . '/data/sync-token.txt';   // secreto compartido con PATO (gitignored)

function pnl_cfg(): array {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $c;
}

function pnl_boot(): void {
    if (!is_dir(PNL_DATA)) @mkdir(PNL_DATA, 0750, true);
    $h = PNL_DATA . '/.htaccess';
    if (!is_file($h)) @file_put_contents($h, "Require all denied\nDeny from all\n");
}

function pnl_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        @session_start();
    }
}

// ---------- cuentas + magic-link (adaptado del patrón probado de Fanatia) ----------
function pnl_accounts(): array {
    if (!is_file(PNL_ACC)) return [];
    $out = [];
    foreach (file(PNL_ACC, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (is_array($r)) $out[] = $r;
    }
    return $out;
}
function pnl_find(string $email): ?array {
    $email = strtolower(trim($email));
    foreach (pnl_accounts() as $r) if (($r['email'] ?? '') === $email) return $r;
    return null;
}
function pnl_write_all(array $rows): void {
    pnl_boot();
    $lines = array_map(fn($r) => json_encode($r, JSON_UNESCAPED_UNICODE), $rows);
    @file_put_contents(PNL_ACC, implode("\n", $lines) . "\n", LOCK_EX);
}
function pnl_is_allowed(string $email): bool {
    $email = strtolower(trim($email));
    return in_array($email, array_map('strtolower', pnl_cfg()['admins'] ?? []), true);
}

// Genera token de un solo uso (guarda el hash). Devuelve rawToken|null. Solo para fundadores permitidos.
function pnl_request_login(string $email): ?string {
    pnl_boot();
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !pnl_is_allowed($email)) return null;
    $raw = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    $exp = time() + 1800; // 30 min
    $rows = pnl_accounts();
    $found = false;
    foreach ($rows as &$r) {
        if (($r['email'] ?? '') === $email) { $r['token_hash'] = $hash; $r['token_exp'] = $exp; $found = true; break; }
    }
    unset($r);
    if (!$found) $rows[] = ['email' => $email, 'created' => date('c'), 'token_hash' => $hash, 'token_exp' => $exp];
    pnl_write_all($rows);
    return $raw;
}
function pnl_verify_token(string $email, string $raw): bool {
    $email = strtolower(trim($email));
    $rows = pnl_accounts();
    $ok = false;
    foreach ($rows as &$r) {
        if (($r['email'] ?? '') === $email) {
            if (($r['token_exp'] ?? 0) >= time() && hash_equals($r['token_hash'] ?? '', hash('sha256', $raw))) {
                $r['token_hash'] = ''; $r['token_exp'] = 0; $r['last_login'] = date('c'); $ok = true;
            }
            break;
        }
    }
    unset($r);
    if ($ok) pnl_write_all($rows);
    return $ok;
}
function pnl_login(string $email): void {
    pnl_session();
    @session_regenerate_id(true);
    $_SESSION['pnl_email'] = strtolower(trim($email));
}
function pnl_current(): ?string {
    pnl_session();
    $e = $_SESSION['pnl_email'] ?? '';
    return ($e && pnl_is_allowed($e)) ? $e : null;
}
function pnl_logout(): void { pnl_session(); $_SESSION = []; @session_destroy(); }

function pnl_send_magic(string $email, string $raw): void {
    $c = pnl_cfg();
    $link = $c['base'] . '/entrar.php?e=' . urlencode($email) . '&t=' . urlencode($raw);
    $body = "Tu acceso al panel de {$c['brand']}\n\n"
          . "Entra con este enlace (válido 30 minutos, un solo uso):\n$link\n\n"
          . "Desde aquí puedes ver y aprobar contenido, dejar feedback y ver tus analíticas.\n\n"
          . "Si no solicitaste esto, ignora el correo.\n";
    @mail($email, "Panel {$c['brand']} — tu enlace de acceso", $body, "From: " . $c['from'] . "\r\n");
}

function pnl_csrf(): string {
    pnl_session();
    if (empty($_SESSION['pnl_csrf'])) $_SESSION['pnl_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['pnl_csrf'];
}
function pnl_csrf_ok(?string $t): bool {
    pnl_session();
    return !empty($_SESSION['pnl_csrf']) && is_string($t) && hash_equals($_SESSION['pnl_csrf'], $t);
}

// ---------- datos publicados por PATO + bandeja de acciones ----------
function pnl_snapshot(): array {
    if (!is_file(PNL_SNAP)) return [];
    $d = json_decode((string) @file_get_contents(PNL_SNAP), true);
    return is_array($d) ? $d : [];
}
function pnl_inbox_append(array $action): void {
    pnl_boot();
    @file_put_contents(PNL_INBOX, json_encode($action, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

// ---------- sync con PATO (token compartido, gitignored) ----------
function pnl_sync_token(): string {
    $c = pnl_cfg();
    if (!empty($c['sync_token'])) return trim((string) $c['sync_token']);
    return is_file(PNL_TOKEN) ? trim((string) @file_get_contents(PNL_TOKEN)) : '';
}
function pnl_sync_ok(?string $t): bool {
    $real = pnl_sync_token();
    return $real !== '' && is_string($t) && hash_equals($real, $t);
}

function pnl_esc(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
