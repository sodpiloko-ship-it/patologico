<?php
/**
 * NUCLEO PATO — acceso al panel del negocio.
 *
 * Une los dos modos que los pilotos resolvieron por separado:
 *   - MAGIC-LINK (Lumin): sin contrasenas; se manda una liga de un solo uso al correo
 *     de la allowlist. Es el default: una fundadora no deberia administrar una password.
 *   - CONTRASENA (Picando Tabla): util cuando el correo no llega o hay varias personas
 *     en la cocina. Con freno por intentos fallidos.
 * El modo se elige en negocio.json ('acceso': 'magic' | 'password' | 'ambos').
 *
 * Reglas: solo entra quien esta en la allowlist; el token se guarda HASHEADO y expira;
 * la contrasena vive como hash y NUNCA en claro; el freno es por IP.
 */
declare(strict_types=1);

const PATO_TOKEN_TTL = 900;      // 15 min de vida para la liga magica
const PATO_MAX_FALLOS = 6;       // intentos antes de frenar
const PATO_FRENO = 900;          // 15 min de freno

function pato_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $secure = !empty($_SERVER['HTTPS'])
            && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/** Solo esta gente puede entrar al panel de este negocio. */
function pato_permitido(string $correo): bool
{
    $correo = strtolower(trim($correo));
    foreach ((array) pato_cfg('admins', []) as $a) {
        if (strtolower(trim((string) $a)) === $correo) return true;
    }
    return false;
}

/** URL HTTPS del panel, siempre en el mismo origen canónico del negocio. */
function pato_panel_base(): string
{
    $origin = rtrim((string) pato_cfg('dominio', ''), '/');
    $candidate = trim((string) pato_cfg('panel_url', ''));
    if ($candidate === '') $candidate = $origin . '/panel';
    if (strpos($candidate, '/') === 0
        && strpos($candidate, '//') !== 0) {
        $candidate = $origin . $candidate;
    }
    if (preg_match('/[\x00-\x20\x7f]/', $candidate)) {
        throw new RuntimeException('panel_url inválida');
    }
    $base = @parse_url($origin);
    $panel = @parse_url($candidate);
    if (!is_array($base)
        || !is_array($panel)
        || strtolower((string) ($base['scheme'] ?? '')) !== 'https'
        || strtolower((string) ($panel['scheme'] ?? '')) !== 'https'
        || empty($base['host'])
        || empty($panel['host'])
        || strcasecmp((string) $base['host'], (string) $panel['host']) !== 0
        || isset($panel['user'])
        || isset($panel['pass'])
        || isset($panel['query'])
        || isset($panel['fragment'])
        || (int) ($base['port'] ?? 443) !== (int) ($panel['port'] ?? 443)
        || (int) ($panel['port'] ?? 443) !== 443) {
        throw new RuntimeException('panel_url debe usar el origen HTTPS del negocio');
    }
    $path = (string) ($panel['path'] ?? '');
    if ($path === '' || strpos($path, '/') !== 0) {
        throw new RuntimeException('panel_url inválida');
    }
    return rtrim($candidate, '/');
}

// ------------------------------------------------------------------ freno por IP
function pato_ip(): string
{
    return (string) (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'cli');
}

function pato_frenado(): bool
{
    $f = pato_read('fallos.json', []);
    $k = hash('sha256', pato_ip());
    if (empty($f[$k])) return false;
    $e = $f[$k];
    return ($e['n'] ?? 0) >= PATO_MAX_FALLOS && (time() - ($e['at'] ?? 0)) < PATO_FRENO;
}

function pato_fallo(): void
{
    try {
        pato_with_lock('fallos.json', function () {
            $fallos = pato_read('fallos.json', []);
            $key = hash('sha256', pato_ip());
            $recent = isset($fallos[$key]['n'])
                && (time() - ($fallos[$key]['at'] ?? 0)) < PATO_FRENO;
            $fallos[$key] = [
                'n' => $recent ? $fallos[$key]['n'] + 1 : 1,
                'at' => time(),
            ];
            return pato_write('fallos.json', $fallos);
        });
    } catch (Throwable $error) {
        // El caller seguirá rechazando el acceso; nunca se abre por fallo.
    }
}

// ------------------------------------------------------------------ magic-link
/** Genera la liga y la manda. Devuelve true aunque el correo no este en la allowlist
 *  (no revelamos quien es admin), pero solo manda si lo esta. */
function pato_pedir_liga(string $correo): bool
{
    $correo = strtolower(trim($correo));
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) return false;
    if (!pato_permitido($correo)) return true;   // silencio deliberado

    try {
        $base = pato_panel_base();
    } catch (Throwable $error) {
        return false;
    }
    $raw = bin2hex(random_bytes(24));
    try {
        $saved = pato_with_lock('tokens.json', function () use ($raw, $correo) {
            $tokens = pato_read('tokens.json', []);
            $tokens[hash('sha256', $raw)] = [
                'correo' => $correo,
                'exp' => time() + PATO_TOKEN_TTL,
            ];
            foreach ($tokens as $hash => $token) {
                if (($token['exp'] ?? 0) < time()) unset($tokens[$hash]);
            }
            return pato_write('tokens.json', $tokens);
        });
    } catch (Throwable $error) {
        $saved = false;
    }
    if (!$saved) return false;

    $liga = $base . '/entrar.php?t=' . $raw;
    $marca = pato_cfg('marca', 'Pato');
    $cuerpo = "Tu acceso a {$marca}\n\n{$liga}\n\nVence en 15 minutos. Si no lo pediste, ignora este correo.";

    $from = (string) pato_cfg('from', 'no-reply@localhost');
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $from = 'no-reply@localhost';
    }
    $ok = @mail($correo, "Tu acceso a {$marca}", $cuerpo,
        "From: {$from}\r\nContent-Type: text/plain; charset=utf-8");

    // Respaldo por Telegram: en hosting compartido el correo falla en silencio.
    if (!$ok || pato_cfg('liga_por_telegram', false)) {
        pato_telegram("{$marca} — acceso al panel para {$correo}:\n{$liga}", $correo);
    }
    return true;
}

/** Canjea la liga. Devuelve el correo o null. Un token sirve UNA vez. */
function pato_canjear(string $raw): ?string
{
    if ($raw === '' || strlen($raw) > 128) return null;
    try {
        return pato_with_lock('tokens.json', function () use ($raw) {
            $tokens = pato_read('tokens.json', []);
            $hash = hash('sha256', $raw);
            if (empty($tokens[$hash])) return null;
            $token = $tokens[$hash];
            unset($tokens[$hash]);
            if (!pato_write('tokens.json', $tokens)) return null;
            if (($token['exp'] ?? 0) < time()) return null;
            $email = isset($token['correo']) ? (string) $token['correo'] : '';
            return pato_permitido($email) ? $email : null;
        });
    } catch (Throwable $error) {
        return null;
    }
}

// ------------------------------------------------------------------ contrasena
function pato_pass_set(string $plano): bool
{
    if (strlen($plano) < 12 || strlen($plano) > 256) return false;
    return pato_write('acceso.json', ['hash' => password_hash($plano, PASSWORD_DEFAULT)]);
}

function pato_pass_ok(string $plano): bool
{
    $a = pato_read('acceso.json', []);
    return !empty($a['hash']) && password_verify($plano, (string) $a['hash']);
}

// ------------------------------------------------------------------ sesion
function pato_entrar(string $correo): void
{
    pato_session();
    session_regenerate_id(true);
    $_SESSION['pato_user'] = strtolower(trim($correo));
    $_SESSION['pato_neg']  = pato_cfg('id');
}

/** Correo de quien esta dentro, o null. Aislado POR NEGOCIO. */
function pato_actual(): ?string
{
    pato_session();
    if (empty($_SESSION['pato_user'])) return null;
    // Una sesion de otro negocio no vale aqui.
    if (($_SESSION['pato_neg'] ?? null) !== pato_cfg('id')) return null;
    $email = (string) $_SESSION['pato_user'];
    return pato_permitido($email) ? $email : null;
}

function pato_salir(): void
{
    pato_session();
    unset($_SESSION['pato_user'], $_SESSION['pato_neg']);
}

/** Corta la ejecucion si no hay sesion. Para poner al inicio de cada pagina del panel. */
function pato_exigir_acceso(): string
{
    $u = pato_actual();
    if ($u === null) {
        $base = pato_panel_base();
        header('Location: ' . $base . '/entrar.php');
        exit;
    }
    return $u;
}

// ------------------------------------------------------------------ CSRF
function pato_csrf(): string
{
    pato_session();
    if (empty($_SESSION['pato_csrf'])) $_SESSION['pato_csrf'] = bin2hex(random_bytes(16));
    return (string) $_SESSION['pato_csrf'];
}

function pato_csrf_ok(?string $t): bool
{
    pato_session();
    return !empty($_SESSION['pato_csrf']) && is_string($t)
        && hash_equals((string) $_SESSION['pato_csrf'], $t);
}
