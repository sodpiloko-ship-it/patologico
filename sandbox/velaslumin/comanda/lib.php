<?php
// Comanda de Lumin Candele — núcleo (heredado del patrón Picando Tabla; datos de LUMIN).
// Login con contraseña compartida: el hash vive SOLO en el server (data/clave.txt, runtime,
// denegado por web + gitignored) porque el repo es público. Bootstrap de un solo uso.
declare(strict_types=1);

date_default_timezone_set('America/Mexico_City');

const CMD_DATA   = __DIR__ . '/data';
const CMD_ESTADO = __DIR__ . '/data/estado.json';
const CMD_CLAVE  = __DIR__ . '/data/clave.txt';
const CMD_ORDERS = __DIR__ . '/../data/orders.jsonl';

function cmd_cfg(): array {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $c;
}

function cmd_boot(): void {
    if (!is_dir(CMD_DATA)) @mkdir(CMD_DATA, 0750, true);
    $h = CMD_DATA . '/.htaccess';
    if (!is_file($h)) @file_put_contents($h, "Require all denied\nDeny from all\n");
}

function cmd_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_set_cookie_params(['lifetime' => 0, 'path' => '/sandbox/velaslumin/comanda', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        @session_start();
    }
}

function cmd_is_allowed(string $email): bool {
    $email = strtolower(trim($email));
    return in_array($email, array_map('strtolower', cmd_cfg()['admins'] ?? []), true);
}
function cmd_pass_hash(): ?string {
    if (is_file(CMD_CLAVE)) {
        $h = trim((string) @file_get_contents(CMD_CLAVE));
        if ($h !== '') return $h;
    }
    return null;
}
function cmd_pass_set(string $raw): bool {
    if (strlen($raw) < 8) return false;
    cmd_boot();
    return @file_put_contents(CMD_CLAVE, password_hash($raw, PASSWORD_BCRYPT) . "\n", LOCK_EX) !== false;
}
function cmd_pass_check(string $raw): bool {
    $h = cmd_pass_hash();
    return $h !== null && password_verify($raw, $h);
}

function cmd_throttled(): bool {
    $f = CMD_DATA . '/fallos.log';
    if (!is_file($f)) return false;
    $n = 0;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        if ((int) $ln > time() - 600) $n++;
    }
    return $n > 10;
}
function cmd_fail(): void {
    cmd_boot();
    @file_put_contents(CMD_DATA . '/fallos.log', time() . "\n", FILE_APPEND | LOCK_EX);
    usleep(500000);
}

function cmd_login(string $email): void {
    cmd_session();
    @session_regenerate_id(true);
    $_SESSION['lmn_email'] = strtolower(trim($email));
}
function cmd_current(): ?string {
    cmd_session();
    $e = $_SESSION['lmn_email'] ?? '';
    return ($e && cmd_is_allowed($e)) ? $e : null;
}
function cmd_logout(): void { cmd_session(); $_SESSION = []; @session_destroy(); }

function cmd_csrf(): string {
    cmd_session();
    if (empty($_SESSION['lmn_csrf'])) $_SESSION['lmn_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['lmn_csrf'];
}
function cmd_csrf_ok(?string $t): bool {
    cmd_session();
    return !empty($_SESSION['lmn_csrf']) && is_string($t) && hash_equals($_SESSION['lmn_csrf'], $t);
}

// ---------- pedidos + estado ----------
function cmd_jsonl(string $file): array {
    if (!is_file($file)) return [];
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (is_array($r)) $out[] = $r;
    }
    return $out;
}
function cmd_key(array $r): string { return substr(hash('sha256', ($r['at'] ?? '') . '|' . ($r['nombre'] ?? '')), 0, 16); }

function cmd_estados(): array {
    if (!is_file(CMD_ESTADO)) return [];
    $d = json_decode((string) @file_get_contents(CMD_ESTADO), true);
    return is_array($d) ? $d : [];
}
function cmd_set_estado(string $key, string $estado, string $por): void {
    cmd_boot();
    $all = cmd_estados();
    if ($estado === 'nueva') unset($all[$key]);
    else $all[$key] = ['estado' => $estado, 'por' => $por, 'at' => date('c')];
    @file_put_contents(CMD_ESTADO, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}
function cmd_estado_de(array $estados, string $key): string {
    return $estados[$key]['estado'] ?? 'nueva';
}
function cmd_confirmados(): array {
    $estados = cmd_estados();
    $out = [];
    foreach (cmd_jsonl(CMD_ORDERS) as $o) {
        if (cmd_estado_de($estados, cmd_key($o)) === 'confirmada') $out[] = $o;
    }
    return $out;
}

// Botón directo a WhatsApp del cliente: normaliza el número a 52XXXXXXXXXX y arma el wa.me.
function cmd_wa_link(array $o): ?string {
    $tel = preg_replace('/\D+/', '', (string) ($o['whatsapp'] ?? ''));
    if (strlen($tel) === 10) $tel = '52' . $tel;
    if (strlen($tel) < 12) return null;
    $msg = '¡Hola ' . (($o['nombre'] ?? '') !== '' ? $o['nombre'] : '') . '! Soy ' . (cmd_cfg()['wa_default_from'] ?? 'Lumin Candele')
         . ' 🕯️ Recibimos tu pedido por $' . number_format((float) ($o['total'] ?? 0), 0)
         . '. Te comparto los datos para la transferencia y en cuanto se refleje tu pago empezamos a hacer tus velas (enviamos dentro de los 2 días posteriores al pago). ¡Gracias!';
    return 'https://wa.me/' . $tel . '?text=' . rawurlencode($msg);
}

// Día de producción: mañana (las velas son sobre pedido, envío a 2 días del pago).
function cmd_dia_produccion(): DateTime {
    return new DateTime('tomorrow');
}

function cmd_esc(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
