<?php
/**
 * ERC — VITRINA DE DEMOS.
 *
 * "Somos tecnologia para real estate": el blog interno donde se muestran los experimentos,
 * no solo se anuncian. Cada entrada declara su ORIGEN: si esta construida en este repositorio
 * (verificable) o si se detecto en el dominio sin tener su codigo fuente (se marca
 * pendiente_confirmar — no se inventa autoria de algo que no controlamos).
 *
 * Fuente: data/demos.json. Agregar un demo nuevo = agregar una entrada ahi.
 */
declare(strict_types=1);
require_once __DIR__ . '/core/pato.php';
require_once __DIR__ . '/panel/_tema.php';

pato_evento('visita');
pato_evento('producto', ['pagina' => 'demos']);

$data = json_decode((string) @file_get_contents(__DIR__ . '/data/demos.json'), true);
$demos = (is_array($data) && !empty($data['demos'])) ? $data['demos'] : [];
$marca = pato_cfg('marca', 'ERC Inmuebles');

$badges = [
    'vivo'                 => ['texto' => 'VIVO',        'bg' => '#EAF1D2', 'fg' => '#5c7a12'],
    'en_desarrollo'        => ['texto' => 'EN DESARROLLO','bg' => '#FFF6E5', 'fg' => '#8a5a00'],
    'pendiente_confirmar'  => ['texto' => 'POR CONFIRMAR','bg' => '#EEEDE6', 'fg' => '#55564E'],
];

echo pato_panel_head('Demos');
echo pato_panel_nav(null);
?>
<div class="wrap" style="padding-top:clamp(28px,4vw,44px);padding-bottom:56px">
  <span class="pill">TECNOLOGÍA PARA REAL ESTATE</span>
  <h1 style="font-size:clamp(24px,4.2vw,36px);margin:14px 0 10px;line-height:1.2">
    Los experimentos, no solo el anuncio.
  </h1>
  <p class="muted" style="max-width:64ch;line-height:1.7;margin-bottom:28px">
    Aquí vive lo que vamos construyendo para vender el potencial de un espacio. Cada demo dice
    de dónde viene: lo que construimos nosotros lleva su código a la vista; lo que aparece en el
    dominio y no reconocemos, lo decimos también.
  </p>

  <div class="grid" style="gap:16px">
    <?php foreach ($demos as $d):
      $b = $badges[$d['estado'] ?? ''] ?? $badges['pendiente_confirmar'];
      $externo = empty($d['interno']);
      $target = $externo ? ' target="_blank" rel="noopener"' : '';
    ?>
    <article class="card" style="<?= $externo ? 'border-style:dashed' : '' ?>">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px">
        <span style="display:inline-block;font-size:11px;font-weight:700;letter-spacing:.04em;
          background:<?= $b['bg'] ?>;color:<?= $b['fg'] ?>;border-radius:99px;padding:3px 10px">
          <?= pato_esc($b['texto']) ?></span>
        <?php if ($externo): ?>
          <span class="muted" style="font-size:12px">no está en nuestro repositorio</span>
        <?php endif; ?>
      </div>
      <h2 style="font-size:19px;margin-bottom:8px"><?= pato_esc((string) $d['titulo']) ?></h2>
      <p class="muted" style="line-height:1.7;margin-bottom:10px"><?= pato_esc((string) $d['descripcion']) ?></p>
      <p style="font-size:12.5px;color:var(--muted);line-height:1.6;margin-bottom:16px">
        <strong>Origen:</strong> <?= pato_esc((string) ($d['origen'] ?? '')) ?>
      </p>
      <a href="<?= pato_esc((string) $d['url']) ?>"<?= $target ?> class="btn <?= $externo ? '' : 'btn-acc' ?>">
        <?= $externo ? 'Ver en el dominio ↗' : 'Explorar el demo' ?></a>
    </article>
    <?php endforeach; ?>
  </div>

  <?php if (!$demos): ?>
    <p class="muted">Todavía no hay demos publicados aquí.</p>
  <?php endif; ?>

  <div class="card" style="margin-top:28px;background:var(--dark);color:#F4F3EE;border:0">
    <h2 style="font-size:17px;margin-bottom:8px;color:#F4F3EE">¿Tienes un inmueble?</h2>
    <p style="color:#C9C9C0;line-height:1.7;margin-bottom:14px">
      Esto es lo que construimos cuando lo levantamos: navegable, con la luz de cualquier hora
      y los acabados que el comprador quiera probar.
    </p>
    <a href="precalifica.php?tipo=vendedor" class="btn btn-acc">Quiero levantar mi inmueble</a>
  </div>
</div>
<?= pato_panel_pie() ?>
