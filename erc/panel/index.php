<?php
/**
 * NUCLEO PATO — tablero del negocio.
 *
 * Generico: el mismo archivo sirve a cualquier negocio. Los estados, el folio, la moneda y
 * el catalogo salen de `negocio.json`, asi que una inmobiliaria y una panaderia ven su
 * propio vocabulario sin tocar una linea de codigo.
 *
 * Regla de la casa: NADA de cifras inventadas. Si no hay datos, se dice que no hay datos.
 */
declare(strict_types=1);

$__pato = is_file(dirname(__DIR__) . '/core/pato.php')
    ? dirname(__DIR__) . '/core/pato.php'    // panel en la raiz del sitio: <sitio>/panel/
    : dirname(__DIR__) . '/pato.php';        // panel dentro del nucleo: <sitio>/core/panel/
require_once $__pato;
require_once __DIR__ . '/_tema.php';

// Guardia con redireccion RELATIVA: el panel puede estar montado bajo cualquier ruta o
// dominio (una ruta absoluta desde `dominio` mandaria al sitio equivocado).
$usuario = pato_actual();
if ($usuario === null) { header('Location: entrar.php'); exit; }

// --- acciones del tablero (mover estado, marcar pagado) ---
$aviso = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && pato_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
    $folio = (string) ($_POST['folio'] ?? '');
    if (($_POST['accion'] ?? '') === 'estado') {
        $aviso = pato_pedido_estado($folio, (string) ($_POST['estado'] ?? ''))
            ? "Movido {$folio}." : "No se pudo mover {$folio}.";
    } elseif (($_POST['accion'] ?? '') === 'pago') {
        $aviso = pato_pedido_pago($folio, ($_POST['pagado'] ?? '') === '1')
            ? "Actualizado el cobro de {$folio}." : "No se pudo actualizar {$folio}.";
    }
}

$resumen = pato_resumen();
$pedidos = array_reverse(pato_pedidos());
$estados = pato_estados();
$embudo  = pato_embudo(30);
$fuga    = pato_fuga(30);
$csrf    = pato_csrf();
$moneda  = pato_esc($resumen['moneda']);
$cat     = function_exists('pato_catalogo') ? pato_catalogo() : null;
$ofertas = [];
if (is_array($cat)) {
    if (!empty($cat['catalogo']['offers'])) $ofertas = $cat['catalogo']['offers'];
    elseif (!empty($cat['offers']))         $ofertas = $cat['offers'];
}
$bienvenida = isset($_GET['bienvenida']);

function pato_money($n, string $m): string { return '$' . number_format((float) $n, 2) . ' ' . $m; }

echo pato_panel_head('Panel');
echo pato_panel_nav($usuario);
?>
<div class="wrap" style="padding-top:28px;padding-bottom:20px">

  <?php if ($bienvenida): ?>
    <div class="msg ok" style="margin-bottom:22px">
      <strong>Tu contraseña quedó lista.</strong> Ya puedes entrar cuando quieras desde
      <em>entrar.php</em> con tu correo y contraseña — sin esperar otra liga.
    </div>
  <?php endif; ?>
  <?php if ($aviso !== ''): ?>
    <div class="msg ok" style="margin-bottom:22px"><?= pato_esc($aviso) ?></div>
  <?php endif; ?>

  <h1 style="font-size:clamp(22px,3.4vw,30px);margin-bottom:4px">Tu negocio, de un vistazo</h1>
  <p class="muted" style="margin-bottom:22px">Últimos 30 días. Todo lo que ves aquí sale de tu propia operación.</p>

  <!-- KPIs -->
  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:24px">
    <div class="card"><div class="kpi"><?= (int) $resumen['pedidos'] ?></div>
      <div class="kpi-l">operaciones registradas</div></div>
    <div class="card"><div class="kpi"><?= pato_money($resumen['vendido'], $moneda) ?></div>
      <div class="kpi-l">valor registrado</div></div>
    <div class="card"><div class="kpi"><?= pato_money($resumen['cobrado'], $moneda) ?></div>
      <div class="kpi-l">cobrado</div></div>
    <div class="card"><div class="kpi"><?= pato_money($resumen['por_cobrar'], $moneda) ?></div>
      <div class="kpi-l">por cobrar</div></div>
  </div>

  <!-- Embudo -->
  <div class="card" style="margin-bottom:24px">
    <h2 style="font-size:18px;margin-bottom:4px">Tu embudo</h2>
    <p class="muted" style="margin-bottom:16px">De la visita al cierre, medido en tu servidor — no depende de nadie más.</p>
    <?php
      $etapas = pato_etapas();
      $tope = 1;
      foreach ($etapas as $e) { $tope = max($tope, (int) ($embudo[$e] ?? 0)); }
      $hay = false;
      foreach ($etapas as $e) { if ((int) ($embudo[$e] ?? 0) > 0) { $hay = true; break; } }
    ?>
    <?php if (!$hay): ?>
      <p class="muted">Todavía no hay eventos registrados. En cuanto tu sitio reciba visitas y
      solicitudes, esto se llena solo.</p>
    <?php else: ?>
      <div class="grid" style="gap:12px">
      <?php foreach ($etapas as $e):
        $v = (int) ($embudo[$e] ?? 0); $pct = (int) round($v / $tope * 100); ?>
        <div>
          <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:5px">
            <span style="font-weight:600"><?= pato_esc(ucfirst($e)) ?></span>
            <span class="muted"><?= number_format($v) ?></span>
          </div>
          <div class="bar"><i style="width:<?= max($pct, 2) ?>%"></i></div>
        </div>
      <?php endforeach; ?>
      </div>
      <?php if (!empty($fuga['de'])): ?>
        <p class="muted" style="margin-top:16px">
          Donde más se cae la gente: de <strong><?= pato_esc($fuga['de']) ?></strong> a
          <strong><?= pato_esc($fuga['a']) ?></strong> (<?= pato_esc((string) $fuga['caida_pct']) ?>%).
        </p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- Operaciones -->
  <div class="card" style="margin-bottom:24px">
    <h2 style="font-size:18px;margin-bottom:4px">Operaciones</h2>
    <p class="muted" style="margin-bottom:16px">Cada una con su folio. Mueve el estado conforme avanza.</p>
    <?php if (!$pedidos): ?>
      <p class="muted">Todavía no hay operaciones. La primera que entre por tu sitio aparece aquí
      con su folio, y te llega un aviso.</p>
    <?php else: ?>
      <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Folio</th><th>Contacto</th><th>Valor</th><th>Estado</th><th>Cobro</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($pedidos, 0, 60) as $p): ?>
          <tr>
            <td><strong><?= pato_esc($p['id'] ?? '') ?></strong>
                <div class="muted"><?= pato_esc(substr((string) ($p['at'] ?? ''), 0, 10)) ?></div></td>
            <td><?= pato_esc($p['cliente'] ?? '') ?>
                <div class="muted"><?= pato_esc($p['wa'] ?? ($p['email'] ?? '')) ?></div></td>
            <td><?= pato_money($p['total'] ?? 0, $moneda) ?></td>
            <td>
              <form method="post" style="display:flex;gap:6px;align-items:center">
                <input type="hidden" name="csrf" value="<?= pato_esc($csrf) ?>">
                <input type="hidden" name="accion" value="estado">
                <input type="hidden" name="folio" value="<?= pato_esc($p['id'] ?? '') ?>">
                <select name="estado" onchange="this.form.submit()">
                  <?php foreach ($estados as $e): ?>
                    <option value="<?= pato_esc($e) ?>" <?= (($p['estado'] ?? '') === $e ? 'selected' : '') ?>>
                      <?= pato_esc($e) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= pato_esc($csrf) ?>">
                <input type="hidden" name="accion" value="pago">
                <input type="hidden" name="folio" value="<?= pato_esc($p['id'] ?? '') ?>">
                <input type="hidden" name="pagado" value="<?= empty($p['pagado']) ? '1' : '0' ?>">
                <button class="tag" type="submit" style="border:0;cursor:pointer">
                  <?= empty($p['pagado']) ? 'marcar cobrado' : '✓ cobrado' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Catalogo -->
  <?php if ($ofertas): ?>
  <div class="card">
    <h2 style="font-size:18px;margin-bottom:4px">Lo que ofreces</h2>
    <p class="muted" style="margin-bottom:16px">
      Esta es la fuente que manda: tu sitio y cualquier cotización leen de aquí, así que un precio
      nunca se inventa del lado del navegador.
    </p>
    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(240px,1fr))">
      <?php foreach ($ofertas as $o): ?>
        <div style="border:1px solid var(--line);border-radius:12px;padding:14px 16px;background:#fff">
          <div style="font-weight:600;font-size:15px;margin-bottom:5px"><?= pato_esc($o['name'] ?? '') ?></div>
          <div class="muted" style="line-height:1.55"><?= pato_esc(mb_substr((string) ($o['description'] ?? ''), 0, 120)) ?></div>
          <div style="margin-top:10px">
            <span class="tag"><?= pato_esc(($o['pricing']['type'] ?? '') === 'custom_quote' ? 'cotización' : 'precio fijo') ?></span>
            <span class="tag"><?= pato_esc($o['availability']['status'] ?? '') ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>
<?= pato_panel_pie() ?>
