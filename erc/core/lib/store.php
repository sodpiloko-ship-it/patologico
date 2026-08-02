<?php
/**
 * NUCLEO PATO — almacenamiento portable.
 *
 * Hereda el patron ya probado en el piloto Lumin, quitandole lo que lo ataba a un solo
 * negocio: el directorio de datos se llamaba `.pato-data-lumin` (una constante con el
 * nombre del cliente). Aqui el nombre sale de negocio.json, asi que el MISMO codigo
 * sirve a N negocios con datos completamente aislados.
 *
 * Reglas que no se negocian:
 *  - Una entrega verificable exige datos FUERA de public_html.
 *  - Directorios 0700 y archivos 0600 en sistemas POSIX.
 *  - Toda escritura es ATOMICA (tmp + rename). Un panel de dinero no se corrompe a medias.
 *  - Nada se inventa: si no hay datos, se devuelve vacio. Cifras falsas en un panel de
 *    dinero son peores que un panel vacio.
 */
declare(strict_types=1);

/** Directorio persistente de ESTE negocio. Fuera de la raiz web si el hosting lo permite. */
function pato_data_dir(): string
{
    static $dir = null;
    if ($dir !== null) return $dir;

    $id = pato_cfg('id', 'negocio');
    $tries = [];

    // Override local explícito. Debe vivir en negocio.local.json para que
    // CLI, workers y web compartan la ubicación sin depender del shell.
    $configured = pato_data_override(pato_cfg('data_dir', ''));
    if ($configured !== null) $tries[] = $configured;

    // 2) hermano de public_html — lo ideal: ni siquiera es alcanzable por web
    // La raíz sale del core instalado, no de DOCUMENT_ROOT. Así CLI, workers
    // y requests web resuelven exactamente el mismo almacén.
    $raiz = defined('PATO_CORE_DIR')
        ? dirname(PATO_CORE_DIR)
        : dirname(dirname(__DIR__));
    $tries[] = dirname($raiz) . '/.pato-data-' . $id;

    // 3) ultimo recurso: en la RAIZ DEL SITIO, blindado con .htaccess.
    //    Ojo: la raiz es el padre de core/, no el padre de core/lib/ — los datos NUNCA
    //    deben caer dentro del directorio del nucleo (ahi los pisaria un re-montaje).
    $tries[] = $raiz . '/data-' . $id;

    foreach ($tries as $c) {
        // Dos primeros requests pueden intentar crear el directorio a la vez.
        // Si otro proceso ganó mkdir(), se vuelve a comprobar en vez de caer
        // a una segunda ubicación y partir el almacén del negocio.
        if (is_dir($c) || @mkdir($c, 0700, true) || is_dir($c)) {
            if (!pato_restringir_directorio($c)) {
                throw new RuntimeException('permisos inseguros en el almacén');
            }
            pato_bind_data_dir($c, (string) $id);
            pato_blindar($c);
            $dir = $c;
            return $dir;
        }
    }
    $dir = $raiz . '/data-' . $id;
    @mkdir($dir, 0700, true);
    if (!pato_restringir_directorio($dir)) {
        throw new RuntimeException('permisos inseguros en el almacén');
    }
    pato_bind_data_dir($dir, (string) $id);
    pato_blindar($dir);
    return $dir;
}

/** Normaliza una ruta absoluta y bloquea destinos ambiguos o de credenciales. */
function pato_data_override($value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') return null;
    if (strlen($raw) > 512 || strpos($raw, "\0") !== false) {
        throw new RuntimeException('ruta de datos local inválida');
    }
    $normal = str_replace('\\', '/', $raw);
    $absolute = $normal[0] === '/' || preg_match('/^[A-Za-z]:\//', $normal);
    if (!$absolute
        || $normal === '/'
        || preg_match('/^[A-Za-z]:\/?$/', $normal)) {
        throw new RuntimeException('ruta de datos local inválida');
    }
    foreach (explode('/', $normal) as $part) {
        $lower = strtolower($part);
        if ($part === '.' || $part === '..'
            || $lower === 'secrets'
            || strpos($lower, '.env') === 0) {
            throw new RuntimeException('ruta de datos local inválida');
        }
    }
    $parent = realpath(dirname($raw));
    $leaf = basename($raw);
    if ($parent === false
        || !is_dir($parent)
        || $leaf === ''
        || $leaf === '.'
        || $leaf === '..') {
        throw new RuntimeException('ruta de datos local inválida');
    }
    $candidate = rtrim($parent, '/\\') . DIRECTORY_SEPARATOR . $leaf;
    if (file_exists($candidate)) {
        $resolved = realpath($candidate);
        if ($resolved === false
            || !is_dir($resolved)
            || pato_path_key($resolved) !== pato_path_key($candidate)) {
            throw new RuntimeException('ruta de datos local inválida');
        }
        $candidate = $resolved;
    }
    $site = realpath(dirname(PATO_CORE_DIR));
    $core = realpath(PATO_CORE_DIR);
    if ($site === false
        || $core === false
        || pato_path_key($candidate) === pato_path_key($site)
        || pato_path_key($candidate) === pato_path_key($core)
        || pato_path_inside($candidate, $core)
        || pato_path_inside($core, $candidate)
        || pato_path_inside($site, $candidate)) {
        throw new RuntimeException('ruta de datos local inválida');
    }
    return $candidate;
}

/** Clave de comparación portable para rutas ya canonicalizadas. */
function pato_path_key(string $path): string
{
    $value = str_replace('\\', '/', rtrim($path, '/\\'));
    return PHP_OS_FAMILY === 'Windows' ? strtolower($value) : $value;
}

/** Verdadero si child vive estrictamente dentro de parent. */
function pato_path_inside(string $child, string $parent): bool
{
    $candidate = pato_path_key($child);
    $root = pato_path_key($parent);
    return $candidate !== $root
        && strpos($candidate, $root . '/') === 0;
}

/** Ata un almacén a un solo business_id antes de leer o escribir datos. */
function pato_bind_data_dir(string $dir, string $businessId): void
{
    if (!preg_match('/\A[a-z0-9][a-z0-9-]{0,62}\z/D', $businessId)) {
        throw new RuntimeException('identidad PATO inválida');
    }
    // Serializa la adopcion inicial. Sin este lock, dos procesos podian
    // observar el marker ausente y uno veia los archivos creados por el otro
    // como un almacen legado.
    $bind_lock = rtrim($dir, '/\\') . '/.pato-bind.lock';
    if (is_link($bind_lock)) {
        throw new RuntimeException('lock de binding inseguro');
    }
    $bind_handle = @fopen($bind_lock, 'c+');
    if (!$bind_handle || !@flock($bind_handle, LOCK_EX)) {
        if ($bind_handle) @fclose($bind_handle);
        throw new RuntimeException('no se pudo adquirir el lock de binding');
    }
    if (!pato_restringir_archivo($bind_lock)) {
        @flock($bind_handle, LOCK_UN);
        @fclose($bind_handle);
        throw new RuntimeException('permisos inseguros en el lock de binding');
    }
    $marker = rtrim($dir, '/\\') . '/.pato-tenant.json';
    $marker_created = !is_file($marker);
    if (is_link($marker)) {
        throw new RuntimeException('marcador de tenant inseguro');
    }
    if ($marker_created) {
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            throw new RuntimeException('almacén de tenant ilegible');
        }
        $legacy = array_values(array_filter(
            $entries,
            static function ($entry): bool {
                return $entry !== '.'
                    && $entry !== '..'
                    && $entry !== '.htaccess'
                    && $entry !== '.pato-bind.lock';
            }
        ));
        if (count($legacy) > 0) {
            throw new RuntimeException(
                'almacén existente sin binding; requiere migración explícita'
            );
        }
    }
    $handle = @fopen($marker, 'c+');
    if (!$handle || !@flock($handle, LOCK_EX)) {
        if ($handle) @fclose($handle);
        throw new RuntimeException('no se pudo ligar el almacén al tenant');
    }
    try {
        if (!pato_restringir_archivo($marker)) {
            throw new RuntimeException('permisos inseguros en el marcador');
        }
        rewind($handle);
        $raw = stream_get_contents($handle);
        if ($raw === false) {
            throw new RuntimeException('marcador de tenant ilegible');
        }
        if ($raw === '') {
            if (!$marker_created) {
                throw new RuntimeException('marcador de tenant corrupto');
            }
            $payload = json_encode([
                'schema_version' => 'pato.data-tenant.v1',
                'business_id' => $businessId,
            ], JSON_UNESCAPED_SLASHES);
            if ($payload === false
                || ftruncate($handle, 0) === false
                || rewind($handle) === false
                || !pato_fwrite_all($handle, $payload . "\n")
                || fflush($handle) === false) {
                throw new RuntimeException('no se pudo crear el marcador de tenant');
            }
            if (!pato_restringir_archivo($marker)) {
                throw new RuntimeException('permisos inseguros en el marcador');
            }
            $raw = $payload;
        }
        $binding = json_decode((string) $raw, true);
        if (!is_array($binding)
            || json_last_error() !== JSON_ERROR_NONE
            || array_keys($binding) !== ['schema_version', 'business_id']
            || ($binding['schema_version'] ?? null) !== 'pato.data-tenant.v1'
            || ($binding['business_id'] ?? null) !== $businessId) {
            throw new RuntimeException('almacén ligado a otro tenant');
        }
    } finally {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
    @flock($bind_handle, LOCK_UN);
    @fclose($bind_handle);
}

/** Comprueba el binding sin revelar la ruta del almacén. */
function pato_data_tenant_bound(string $dir, string $businessId): bool
{
    $marker = rtrim($dir, '/\\') . '/.pato-tenant.json';
    if (is_link($marker) || !pato_archivo_restringido($marker)) return false;
    $raw = @file_get_contents($marker);
    if ($raw === false) return false;
    $binding = json_decode((string) $raw, true);
    return is_array($binding)
        && json_last_error() === JSON_ERROR_NONE
        && array_keys($binding) === ['schema_version', 'business_id']
        && ($binding['schema_version'] ?? null) === 'pato.data-tenant.v1'
        && ($binding['business_id'] ?? null) === $businessId;
}

/** Deja el directorio fuera del alcance del navegador (por si quedo bajo la raiz web). */
function pato_blindar(string $dir): void
{
    $h = $dir . '/.htaccess';
    if (is_link($h)) {
        throw new RuntimeException('protección del almacén insegura');
    }
    if (!is_file($h)) {
        $written = @file_put_contents(
            $h,
            "Require all denied\nDeny from all\n",
            LOCK_EX
        );
        if ($written === false) {
            throw new RuntimeException('no se pudo proteger el almacén');
        }
    }
    if (!pato_restringir_archivo($h)) {
        throw new RuntimeException('permisos inseguros en la protección');
    }
}

/** En POSIX, ningún permiso de grupo/otros es aceptable para PII. */
function pato_restringir_directorio(string $dir): bool
{
    if (!is_dir($dir) || is_link($dir)) return false;
    @chmod($dir, 0700);
    if (PHP_OS_FAMILY === 'Windows') return true;
    $mode = @fileperms($dir);
    return $mode !== false && (($mode & 0077) === 0);
}

function pato_restringir_archivo(string $path): bool
{
    if (!is_file($path) || is_link($path)) return false;
    @chmod($path, 0600);
    return pato_archivo_restringido($path);
}

function pato_archivo_restringido(string $path): bool
{
    if (!is_file($path) || is_link($path)) return false;
    if (PHP_OS_FAMILY === 'Windows') return true;
    $mode = @fileperms($path);
    return $mode !== false && (($mode & 0077) === 0);
}

function pato_data_permissions_secure(string $dir): bool
{
    if (!pato_restringir_directorio($dir)) return false;
    $entries = @scandir($dir);
    if (!is_array($entries)) return false;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path) || !pato_restringir_archivo($path)) return false;
    }
    return true;
}

/** fwrite() puede ser parcial; este helper exige persistir todo el payload. */
function pato_fwrite_all($handle, string $payload): bool
{
    $offset = 0;
    $length = strlen($payload);
    while ($offset < $length) {
        $written = @fwrite($handle, substr($payload, $offset));
        if ($written === false || $written === 0) return false;
        $offset += $written;
    }
    return true;
}

/** Ruta a un archivo de datos de este negocio. */
function pato_path(string $nombre): string
{
    if ($nombre === ''
        || strlen($nombre) > 128
        || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $nombre)) {
        throw new InvalidArgumentException('nombre de archivo de datos inválido');
    }
    return pato_data_dir() . '/' . $nombre;
}

/**
 * Serializa una operación read-modify-write mediante un lock independiente.
 * El lock sobrevive a los renames atómicos del JSON principal.
 */
function pato_with_lock(string $nombre, callable $fn)
{
    $lock = pato_path($nombre . '.lock');
    if (is_link($lock)) {
        throw new RuntimeException('lock de datos inseguro');
    }
    $fh = @fopen($lock, 'c+');
    if (!$fh || !@flock($fh, LOCK_EX)) {
        if ($fh) @fclose($fh);
        throw new RuntimeException('no se pudo adquirir el lock de datos');
    }
    try {
        if (!pato_restringir_archivo($lock)) {
            throw new RuntimeException('permisos inseguros en el lock');
        }
        return $fn();
    } finally {
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }
}

/**
 * Lee un JSON de datos.
 *
 * Un archivo ausente representa "sin datos". Un archivo existente pero
 * ilegible/corrupto representa una falla operacional y se rechaza: devolver
 * el default permitiria que el siguiente read-modify-write reemplazara datos
 * reales por un almacen vacio.
 */
function pato_read(string $nombre, $default = [])
{
    $f = pato_path($nombre);
    if (!is_file($f)) return $default;
    if (is_link($f) || !pato_archivo_restringido($f)) {
        throw new RuntimeException('archivo de datos inseguro');
    }
    $raw = @file_get_contents($f);
    if ($raw === false) {
        throw new RuntimeException('no se pudo leer el almacen de datos');
    }
    $d = json_decode($raw, true);
    if (!is_array($d) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('almacen de datos corrupto');
    }
    return $d;
}

/** Escribe un JSON de datos de forma ATOMICA. */
function pato_write(string $nombre, array $data): bool
{
    pato_data_dir();
    if ($nombre === '') return false;
    $f = pato_path($nombre);
    $data['updated'] = date('c');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    try {
        $tmp = $f . '.tmp.' . bin2hex(random_bytes(6));
    } catch (Throwable $e) {
        return false;
    }
    if (is_link($f) || is_link($tmp)) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    if (!pato_restringir_archivo($tmp)) {
        @unlink($tmp);
        return false;
    }
    if (is_link($f)) {
        @unlink($tmp);
        return false;
    }
    $ok = @rename($tmp, $f);
    if (!$ok && is_file($tmp)) @unlink($tmp);
    return $ok && pato_restringir_archivo($f);
}

/** Agrega una linea a un JSONL (bitacoras: pedidos crudos, eventos, leads). */
function pato_append(string $nombre, array $rec): bool
{
    pato_data_dir();
    $rec['at'] = isset($rec['at']) ? $rec['at'] : date('c');
    $linea = json_encode($rec, JSON_UNESCAPED_UNICODE);
    if ($linea === false) return false;
    $path = pato_path($nombre);
    if (is_link($path)) return false;
    $handle = @fopen($path, 'c+');
    if (!$handle || !@flock($handle, LOCK_EX)) {
        if ($handle) @fclose($handle);
        return false;
    }
    try {
        if (!pato_restringir_archivo($path) || fseek($handle, 0, SEEK_END) !== 0) {
            return false;
        }
        return pato_fwrite_all($handle, $linea . "\n")
            && fflush($handle);
    } finally {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

/** Lee un JSONL completo (o los ultimos $limite registros). */
function pato_read_jsonl(string $nombre, int $limite = 0): array
{
    $f = pato_path($nombre);
    if (!is_file($f)) return [];
    if (is_link($f) || !pato_archivo_restringido($f)) return [];
    $out = [];
    $fh = @fopen($f, 'r');
    if (!$fh) return [];
    while (($l = fgets($fh)) !== false) {
        $l = trim($l);
        if ($l === '') continue;
        $r = json_decode($l, true);
        if (is_array($r)) $out[] = $r;
    }
    fclose($fh);
    if ($limite > 0 && count($out) > $limite) $out = array_slice($out, -$limite);
    return $out;
}

/** Escapado para HTML. Todo lo que venga del cliente pasa por aqui. */
function pato_esc($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
