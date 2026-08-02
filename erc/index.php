<?php
/**
 * ERC Inmuebles — sitio publico, montado sobre el NUCLEO PATO.
 *
 * El sitio no decide nada: los servicios salen del catalogo canonico y las propiedades de
 * data/propiedades.json. Cada visita queda medida en el embudo del propio servidor.
 */
declare(strict_types=1);
require_once __DIR__ . '/core/pato.php';
require_once __DIR__ . '/panel/_tema.php';

pato_evento('visita');

$cat = pato_catalogo();
$ofertas = [];
if (is_array($cat)) {
    if (!empty($cat['catalogo']['offers'])) $ofertas = $cat['catalogo']['offers'];
    elseif (!empty($cat['offers']))         $ofertas = $cat['offers'];
}
$pdata = json_decode((string) @file_get_contents(__DIR__ . '/data/propiedades.json'), true);
$propiedades = (is_array($pdata) && !empty($pdata['propiedades'])) ? $pdata['propiedades'] : [];
$marca = pato_cfg('marca', 'ERC Inmuebles');

echo pato_panel_head('Inmuebles con acompañamiento de principio a fin');
?>
<header class="nav">
  <span class="brand"><?= pato_esc($marca) ?></span>
  <div class="right">
    <a href="#propiedades" class="hide-sm">Propiedades</a>
    <a href="#servicios" class="hide-sm">Servicios</a>
    <a href="precalifica.php" class="btn btn-acc" style="padding:9px 16px">Precalifícate gratis</a>
  </div>
</header>

<div class="wrap" style="padding-top:clamp(40px,7vw,80px);padding-bottom:20px;max-width:820px">
  <span class="pill">COBRAMOS SI GANAMOS</span>
  <h1 style="font-size:clamp(30px,6vw,52px);margin:16px 0 14px;line-height:1.12">
    Sabe primero cuánto puedes comprar.<br>Después vemos casas.
  </h1>
  <p style="font-size:17.5px;line-height:1.7;color:#43443D;max-width:62ch">
    La mayoría se enamora de un inmueble y hasta el final descubre que el crédito no alcanzaba.
    Aquí el orden es al revés: te precalificamos sin costo, te decimos a qué puedes aspirar de
    verdad, y solo entonces te mostramos lo que sí está a tu alcance.
  </p>
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:26px">
    <a href="precalifica.php" class="btn btn-acc">Precalifícate en 2 minutos</a>
    <a href="#propiedades" class="btn">Ver propiedades</a>
  </div>
  <p class="muted" style="margin-top:14px">
    Sin costo y sin compromiso · Nuestros honorarios se pagan al cerrar la operación, no antes.
  </p>
</div>

<!-- PROPIEDADES -->
<div class="wrap" id="propiedades" style="padding-top:clamp(30px,5vw,56px)">
  <h2 style="font-size:clamp(21px,3vw,28px);margin-bottom:6px">Propiedades</h2>
  <?php if (!$propiedades): ?>
    <p class="muted" style="margin-bottom:18px">
      Todavía no hay fichas publicadas. En cuanto se carguen desde el panel, cada propiedad se
      arma sola aquí con su ficha, su precio y su botón de visita.
    </p>
    <div class="card" style="border-style:dashed">
      <h3 style="font-size:16px;margin-bottom:6px">¿Tienes un inmueble que vender?</h3>
      <p class="muted" style="margin-bottom:14px">
        Lo publicamos con su ficha, lo difundimos y te mandamos únicamente prospectos ya
        precalificados — para que no pierdas sábados enseñando la casa a quien no puede comprarla.
      </p>
      <a href="precalifica.php?tipo=vendedor" class="btn">Quiero vender mi inmueble</a>
    </div>
  <?php else: ?>
    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(280px,1fr));margin-top:18px">
      <?php foreach ($propiedades as $p): if (($p['estado'] ?? '') === 'cerrada') continue; ?>
        <article class="card">
          <?php if (!empty($p['foto'])): ?>
            <div style="aspect-ratio:4/3;border-radius:10px;overflow:hidden;margin:-4px -6px 14px;
                        background:#EEEDE6 url('<?= pato_esc($p['foto']) ?>') center/cover"></div>
          <?php endif; ?>
          <div style="display:flex;gap:8px;margin-bottom:8px">
            <span class="tag"><?= pato_esc($p['operacion'] ?? 'venta') ?></span>
            <span class="tag"><?= pato_esc($p['zona'] ?? '') ?></span>
          </div>
          <h3 style="font-size:17px;margin-bottom:6px"><?= pato_esc($p['titulo'] ?? '') ?></h3>
          <div class="kpi" style="font-size:22px">
            $<?= number_format((float) ($p['precio'] ?? 0)) ?>
            <span class="muted" style="font-size:13px"><?= pato_esc($p['moneda'] ?? 'MXN') ?></span>
          </div>
          <p class="muted" style="margin:10px 0 12px">
            <?= (int) ($p['recamaras'] ?? 0) ?> rec · <?= (int) ($p['banos'] ?? 0) ?> baños ·
            <?= (int) ($p['m2'] ?? 0) ?> m²
          </p>
          <a href="precalifica.php?propiedad=<?= urlencode((string) ($p['id'] ?? '')) ?>"
             class="btn btn-acc" style="width:100%;text-align:center">Me interesa</a>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- SERVICIOS (del catalogo canonico) -->
<div class="wrap" id="servicios" style="padding-top:clamp(36px,5vw,60px)">
  <h2 style="font-size:clamp(21px,3vw,28px);margin-bottom:6px">Cómo trabajamos</h2>
  <p class="muted" style="margin-bottom:20px">
    Nuestros honorarios se pagan al cerrar. Si no cierras, no pagas.
  </p>
  <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(250px,1fr))">
    <?php foreach ($ofertas as $o): if (empty($o['active'])) continue; ?>
      <div class="card">
        <h3 style="font-size:16px;margin-bottom:8px"><?= pato_esc($o['name'] ?? '') ?></h3>
        <p class="muted" style="line-height:1.6"><?= pato_esc($o['description'] ?? '') ?></p>
        <div style="margin-top:12px">
          <?php if (($o['pricing']['type'] ?? '') === 'fixed' && (int) ($o['price_minor'] ?? -1) === 0): ?>
            <span class="tag" style="background:var(--chipbg);color:var(--chipfg)">sin costo</span>
          <?php else: ?>
            <span class="tag">se cotiza</span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- MARCAS ALIADAS -->
<div class="wrap" style="padding-top:clamp(36px,5vw,60px);padding-bottom:clamp(40px,6vw,72px)">
  <div class="card" style="background:var(--dark);color:var(--paper);border:0">
    <span class="pill" style="background:var(--acc);color:var(--dark)">MARCAS ALIADAS</span>
    <h2 style="font-size:clamp(20px,3vw,28px);margin:14px 0 12px;color:var(--paper)">
      La casa y lo que va dentro, en el mismo lugar.
    </h2>
    <p style="color:#C9C9C0;line-height:1.7;max-width:66ch">
      Cuando cierras con nosotros, el equipamiento no empieza de cero: cotizamos mobiliario de
      marcas aliadas dentro de la misma ficha del inmueble. Los precios y la existencia los
      confirma cada marca — nosotros no los inventamos.
    </p>
    <a href="precalifica.php?tipo=equipamiento" class="btn btn-acc" style="margin-top:18px">
      Quiero cotizar equipamiento</a>
  </div>
</div>

<div class="wrap" style="padding-bottom:40px">
  <p class="muted">
    <?= pato_esc($marca) ?> · Operado con PATO — cada solicitud queda registrada antes de avisarnos,
    así ninguna se pierde. <a href="panel/entrar.php" style="color:var(--chipfg)">Panel</a>
  </p>
</div>
</body></html>
