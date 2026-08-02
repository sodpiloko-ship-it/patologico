<?php
/**
 * ERC Inmuebles — LANDING.
 *
 * Deliberadamente minima: la marca y una sola accion. Nada mas.
 * El resto del sistema (recorrido 3D, precalificador, panel) sigue construido y
 * accesible por su ruta, pero NO se anuncia aqui todavia.
 *
 * El numero de WhatsApp sale de negocio.json. Si no esta configurado, el boton
 * NO se pinta: mas vale una landing limpia que un boton que no lleva a ningun lado.
 */
declare(strict_types=1);
require_once __DIR__ . '/core/pato.php';

pato_evento('visita');

$marca = pato_cfg('marca', 'ERC Inmuebles');
$wa = preg_replace('/[^0-9]/', '', (string) pato_cfg('whatsapp', ''));
$mensaje = 'Hola, me interesa saber más sobre ERC.';
?><!DOCTYPE html>
<html lang="es-MX">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= pato_esc($marca) ?></title>
<meta name="description" content="ERC — la nueva forma de vender una propiedad.">
<meta name="theme-color" content="#F7F6F3">
<meta property="og:title" content="<?= pato_esc($marca) ?>">
<meta property="og:description" content="La nueva forma de vender una propiedad.">
<meta property="og:type" content="website">
<link rel="canonical" href="https://ercinmuebles.com/">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  :root{ --tinta:#1A1A1A; --papel:#F7F6F3; --oro:#B08D57 }
  html,body{ height:100% }
  body{
    background:var(--papel); color:var(--tinta);
    font-family:'Jost',system-ui,-apple-system,'Segoe UI',sans-serif;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:clamp(38px,7vh,72px);
    padding:max(28px,env(safe-area-inset-top)) 24px max(28px,env(safe-area-inset-bottom));
    -webkit-font-smoothing:antialiased;
  }
  .marca{ width:min(420px,72vw); height:auto; display:block }
  .marca rect{ fill:var(--tinta) }
  .marca .trazo{ fill:none; stroke:var(--tinta); stroke-width:11; stroke-linecap:butt }

  .wa{
    display:inline-flex; align-items:center; gap:11px;
    border:1px solid var(--tinta); border-radius:2px;
    padding:15px 30px; text-decoration:none; color:var(--tinta);
    font-size:13.5px; font-weight:400; letter-spacing:.16em; text-transform:uppercase;
    transition:background .25s ease, color .25s ease;
  }
  .wa:hover{ background:var(--tinta); color:var(--papel) }
  .wa svg{ width:18px; height:18px; flex:none }
  .wa svg path{ fill:currentColor }

  @media (max-width:520px){
    .wa{ padding:14px 24px; font-size:12px; letter-spacing:.12em }
  }
</style>
</head>
<body>

  <!-- La marca: E de tres barras, R y C de trazo fino. -->
  <svg class="marca" viewBox="0 0 372 104" role="img" aria-label="<?= pato_esc($marca) ?>">
    <rect x="0" y="0"  width="104" height="16"/>
    <rect x="0" y="44" width="104" height="16"/>
    <rect x="0" y="88" width="104" height="16"/>
    <path class="trazo" d="M146 104 V6 h44 a25 25 0 0 1 0 50 h-44 M182 56 L230 104"/>
    <path class="trazo" d="M357 22 A44 44 0 1 0 357 82"/>
  </svg>

  <?php if ($wa !== ''): ?>
    <a class="wa" href="https://wa.me/<?= pato_esc($wa) ?>?text=<?= rawurlencode($mensaje) ?>"
       target="_blank" rel="noopener">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.64.07-.3-.15-1.25-.46-2.39-1.47-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.07-.15-.67-1.62-.92-2.22-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.06 2.88 1.21 3.08c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.69.63.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2.01-1.41.25-.7.25-1.29.17-1.42-.07-.13-.27-.2-.57-.35zM12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.87 9.87 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91C21.96 6.45 17.5 2 12.04 2zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.17 8.17 0 0 1-1.25-4.38c0-4.54 3.7-8.23 8.24-8.23 2.2 0 4.27.86 5.83 2.41a8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.24 8.23z"/></svg>
      Escríbenos
    </a>
  <?php endif; ?>

</body>
</html>
