<?php
/**
 * NUCLEO PATO — punto de entrada unico.
 *
 *   require_once __DIR__ . '/core/pato.php';
 *
 * A partir de ahi el sitio tiene pedidos, folios, estados, medicion, acceso y avisos
 * SIN escribir una linea de infraestructura. Lo unico que cambia entre un negocio y
 * otro es `negocio.json`.
 *
 * Por que vendorizado (una copia del nucleo dentro de cada sitio) y no un servidor
 * central: los sitios viven en hosting compartido, cada uno con su dominio y su repo,
 * sin demonios ni composer. Una copia por sitio significa que (a) el deploy no cambia,
 * (b) un negocio no puede tumbar a otro, y (c) actualizar N sitios es volver a correr
 * el montador (`python -m pato_brain.core_build`). El dia que haya servidor propio,
 * este mismo contrato se puede mover sin tocar los sitios.
 *
 * Compatible con PHP 7.4+ (es lo que corre el hosting compartido).
 */
declare(strict_types=1);

define('PATO_CORE_VERSION', '0.3.0');
define('PATO_CORE_DIR', __DIR__);

/** Rechaza aliases de filesystem para que la config pertenezca al sitio real. */
function pato_archivo_local_seguro(string $path): bool
{
    if (!is_file($path) || is_link($path)) return false;
    $real = realpath($path);
    $parent = realpath(dirname($path));
    if ($real === false || $parent === false) return false;
    $expected = rtrim($parent, '/\\') . DIRECTORY_SEPARATOR . basename($path);
    if (PHP_OS_FAMILY === 'Windows') {
        return strcasecmp($real, $expected) === 0;
    }
    return $real === $expected;
}

/**
 * Config del negocio. Busca `negocio.json` junto al core y en el directorio padre
 * (que es donde vive cuando el core esta vendorizado dentro del sitio).
 */
function pato_cfg(string $clave = '', $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = null;
        $candidatos = [dirname(PATO_CORE_DIR) . '/negocio.json'];
        foreach ($candidatos as $c) {
            if (is_file($c)) {
                if (!pato_archivo_local_seguro($c)) {
                    throw new RuntimeException('configuracion PATO insegura');
                }
                $raw = @file_get_contents($c);
                if ($raw === false) {
                    throw new RuntimeException('configuracion PATO no disponible');
                }
                $d = json_decode((string) $raw, true);
                if (!is_array($d) || json_last_error() !== JSON_ERROR_NONE) {
                    throw new RuntimeException('configuracion PATO corrupta');
                }
                $cfg = $d;
                break;
            }
        }
        if (!is_array($cfg)) {
            throw new RuntimeException('configuracion PATO no disponible');
        }
        // Overrides locales que NUNCA van al repo. La allowlist impide cambiar
        // identidad, moneda, catálogo, folios, estados o módulos por accidente.
        $local = dirname(PATO_CORE_DIR) . '/negocio.local.json';
        if (is_file($local)) {
            if (!pato_archivo_local_seguro($local)) {
                throw new RuntimeException('configuracion local PATO insegura');
            }
            $raw = @file_get_contents($local);
            $o = $raw === false ? null : json_decode((string) $raw, true);
            if (!is_array($o) || json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('configuracion local PATO corrupta');
            }
            $allowed = [
                'admins' => true,
                'avisos' => true,
                'telegram' => true,
                'data_dir' => true,
                'panel_url' => true,
                'liga_por_telegram' => true,
            ];
            $cfg = array_merge($cfg, array_intersect_key($o, $allowed));
        }
    }
    if ($clave === '') return $cfg;
    return array_key_exists($clave, $cfg) ? $cfg[$clave] : $default;
}

/** ¿Este negocio tiene encendido tal modulo? */
function pato_modulo(string $nombre): bool
{
    $m = pato_cfg('modulos', []);
    return is_array($m) && in_array($nombre, $m, true);
}

/**
 * El perfil shadow es un runtime distinto y cerrado: sólo lectura de catálogo.
 * La ausencia de `perfil` conserva exactamente el runtime operacional legado.
 */
function pato_perfil_runtime(): string
{
    static $perfilValidado = null;
    if ($perfilValidado !== null) return $perfilValidado;

    // El selector sólo lee la configuración pública administrada. Así una
    // avería en overrides locales conserva el mismo punto de fallo legado
    // cuando el consumidor llama pato_cfg(), sin ampliar privilegios.
    $path = dirname(PATO_CORE_DIR) . '/negocio.json';
    if (!pato_archivo_local_seguro($path)) {
        throw new RuntimeException('configuracion PATO insegura');
    }
    $raw = @file_get_contents($path);
    $cfg = $raw === false ? null : json_decode((string) $raw, true);
    if (!is_array($cfg) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('configuracion PATO corrupta');
    }
    $perfil = trim((string) ($cfg['perfil'] ?? ''));
    if ($perfil === '') {
        $perfilValidado = 'operational';
        return $perfilValidado;
    }
    if ($perfil !== 'shadow_discovery') {
        throw new RuntimeException('perfil PATO no permitido');
    }
    if (($cfg['modulos'] ?? null) !== ['catalog']
        || ($cfg['estados'] ?? null) !== ['shadow', 'closed']
        || ($cfg['folio_prefijo'] ?? null) !== 'SH'
        || ($cfg['folio_inicial'] ?? null) !== 900000000
        || ($cfg['acceso'] ?? null) !== 'magic') {
        throw new RuntimeException('perfil shadow PATO inválido');
    }
    $perfilValidado = 'shadow_discovery';
    return $perfilValidado;
}

if (pato_perfil_runtime() === 'shadow_discovery') {
    // No carga store, notify, auth, orders ni track; tampoco crea almacenes.
    require_once __DIR__ . '/lib/catalog.php';
} else {
    require_once __DIR__ . '/lib/store.php';
    require_once __DIR__ . '/lib/notify.php';
    require_once __DIR__ . '/lib/auth.php';
    require_once __DIR__ . '/lib/catalog.php';
    require_once __DIR__ . '/lib/orders.php';
    require_once __DIR__ . '/lib/track.php';
}

/** Diagnostico del montaje: lo usa el verificador del montador. */
function pato_diagnostico(): array
{
    $perfil = pato_perfil_runtime();
    $catalogo = pato_catalogo_diagnostico();
    if ($perfil === 'shadow_discovery') {
        return [
            'core'          => PATO_CORE_VERSION,
            'negocio'       => pato_cfg('id'),
            'marca'         => pato_cfg('marca'),
            'perfil'        => $perfil,
            'solo_lectura'  => true,
            'almacenamiento_inicializado' => false,
            'modulos'       => pato_cfg('modulos', []),
            'catalogo'      => $catalogo,
            'php'           => PHP_VERSION,
        ];
    }
    $dir = pato_data_dir();
    $tenantBound = pato_data_tenant_bound($dir, (string) pato_cfg('id'));
    $site_root = realpath(dirname(PATO_CORE_DIR));
    $data_root = realpath($dir);
    $prefix = rtrim(
        $site_root !== false ? $site_root : dirname(PATO_CORE_DIR),
        '/\\'
    ) . DIRECTORY_SEPARATOR;
    $candidate = $data_root !== false ? $data_root : $dir;
    $inside_site = PHP_OS_FAMILY === 'Windows'
        ? strncasecmp($candidate, $prefix, strlen($prefix)) === 0
        : strncmp($candidate, $prefix, strlen($prefix)) === 0;
    $outside_web = !$inside_site;
    $deny_file = $dir . '/.htaccess';
    $fallback_protected = is_file($deny_file)
        && strpos((string) @file_get_contents($deny_file), 'Require all denied') !== false;
    return [
        'core'          => PATO_CORE_VERSION,
        'negocio'       => pato_cfg('id'),
        'marca'         => pato_cfg('marca'),
        'perfil'        => $perfil,
        'modulos'       => pato_cfg('modulos', []),
        'data_dir'      => $dir,
        'data_escribible' => is_writable($dir),
        'fuera_de_web'  => $outside_web,
        'data_protegida' => $outside_web || $fallback_protected,
        'data_permisos_restringidos' => pato_data_permissions_secure($dir),
        'tenant_bound'   => $tenantBound,
        'catalogo'      => $catalogo,
        'php'           => PHP_VERSION,
    ];
}
