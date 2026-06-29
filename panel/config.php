<?php
// Panel de Fundador — CONFIG (se commitea, SIN datos sensibles).
// Los correos del fundador y el token de sync van en data/config.local.php (gitignored),
// que David coloca UNA vez en el host. Aquí solo lo no-secreto.
declare(strict_types=1);

$cfg = [
    'project'  => 'patologicos',          // id usado en pieces / analytics
    'site_key' => 'patologicos',          // clave en secrets/analytics.json (la usa PATO al publicar)
    'brand'    => 'Patológicos',
    'emoji'    => '🥚',
    'base'     => 'https://patologicos.com/panel',
    'from'     => 'contacto@patologicos.com',
    'accent'   => '#df7a3c',
    'admins'   => [],                      // se llena desde data/config.local.php (correos del fundador)
    'sync_token' => '',                    // se llena desde data/config.local.php (igual a secrets/panel-tokens.json)
];

// Overrides locales del host (NO en git): ['admins'=>['correo@...'], 'sync_token'=>'...']
$local = __DIR__ . '/data/config.local.php';
if (is_file($local)) {
    $ov = require $local;
    if (is_array($ov)) $cfg = array_merge($cfg, $ov);
}
return $cfg;
