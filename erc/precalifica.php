<?php
/**
 * ERC — PRECALIFICACION del prospecto.
 *
 * Es la pieza que ordena el negocio al reves de como suele hacerse: primero sabemos cuanto
 * puede comprar la persona, despues le ensenamos inmuebles. Eso le ahorra visitas inutiles a
 * ella y sabados perdidos al vendedor.
 *
 * El resultado es ORIENTATIVO y se dice explicitamente: PATO no autoriza creditos ni promete
 * montos. La regla del nucleo manda — el prospecto se GUARDA antes de avisar a nadie.
 */
declare(strict_types=1);
require_once __DIR__ . '/core/pato.php';
require_once __DIR__ . '/panel/_tema.php';

pato_evento('visita');

$enviado = false;
$error = '';
$resultado = null;
$folio = '';

/** Capacidad orientativa: regla estandar de que el pago no pase el 30% del ingreso. */
function erc_capacidad(float $ingreso, float $deudas, float $enganche, int $anios): array
{
    $disponible = max(0.0, $ingreso * 0.30 - $deudas);
    $tasa = 0.115 / 12;                       // tasa hipotecaria de referencia
    $n = max(1, $anios * 12);
    // Valor presente de una anualidad
    $credito = $tasa > 0 ? $disponible * ((1 - pow(1 + $tasa, -$n)) / $tasa) : $disponible * $n;
    return [
        'pago_mensual' => round($disponible, 2),
        'credito'      => round(max(0.0, $credito), 2),
        'inmueble'     => round(max(0.0, $credito) + $enganche, 2),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre  = trim((string) ($_POST['nombre'] ?? ''));
    $correo  = strtolower(trim((string) ($_POST['correo'] ?? '')));
    $wa      = preg_replace('/[^0-9]/', '', (string) ($_POST['whatsapp'] ?? ''));
    $ingreso = (float) ($_POST['ingreso'] ?? 0);
    $deudas  = (float) ($_POST['deudas'] ?? 0);
    $enganche = (float) ($_POST['enganche'] ?? 0);
    $anios   = max(5, min(25, (int) ($_POST['anios'] ?? 20)));
    $busca   = trim((string) ($_POST['busca'] ?? ''));
    $cuando  = trim((string) ($_POST['cuando'] ?? ''));

    if ($nombre === '' || ($correo === '' && $wa === '')) {
        $error = 'Necesitamos tu nombre y al menos un correo o WhatsApp para devolverte el resultado.';
    } elseif ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $error = 'Ese correo no parece válido.';
    } elseif ($ingreso <= 0) {
        $error = 'Escribe tu ingreso mensual aproximado para poder calcular.';
    } else {
        $resultado = erc_capacidad($ingreso, $deudas, $enganche, $anios);

        // GUARDAR primero: el prospecto entra al panel con folio, pase lo que pase con el aviso.
        $r = pato_pedido_crear([
            'idempotency_key' => 'precalifica-' . bin2hex(random_bytes(8)),
            'cliente' => $nombre,
            'wa'      => $wa,
            'email'   => $correo,
            'items'   => [[
                'offer_id' => 'erc.precalificacion',
                'quantity' => 1,
                'selections' => [],
            ]],
            'notas' => sprintf(
                'Ingreso %s · deudas %s · enganche %s · %d años. Capacidad orientativa: '
                . 'pago %s/mes, crédito %s, inmueble hasta %s. Busca: %s. Cuándo: %s.',
                number_format($ingreso), number_format($deudas), number_format($enganche), $anios,
                number_format($resultado['pago_mensual']), number_format($resultado['credito']),
                number_format($resultado['inmueble']), ($busca ?: 'no dijo'), ($cuando ?: 'no dijo')
            ),
        ], [
            'business_id'  => (string) pato_cfg('id'),
            'channel_id'   => 'erc-web-precalifica',
            'principal_id' => 'web-anonimo',
            'scopes'       => ['pond.action'],
        ]);

        if (!empty($r['ok'])) {
            $folio = (string) ($r['pedido']['id'] ?? '');
            $enviado = true;
            pato_evento('pedido', ['folio' => $folio]);
            pato_avisar_pedido($r['pedido']);
        } else {
            $error = 'No pudimos guardar tu solicitud. Vuelve a intentarlo, por favor.';
        }
    }
}

$marca = pato_cfg('marca', 'ERC Inmuebles');
echo pato_panel_head('Precalifícate sin costo');
?>
<header class="nav">
  <a href="index.php" class="brand"><?= pato_esc($marca) ?></a>
  <div class="right"><a href="index.php">← Volver</a></div>
</header>

<div class="wrap" style="max-width:620px;padding-top:clamp(32px,6vw,60px);padding-bottom:60px">

<?php if ($enviado && $resultado): ?>
  <span class="pill">RESULTADO ORIENTATIVO</span>
  <h1 style="font-size:clamp(24px,4.5vw,36px);margin:14px 0 10px">Esto es lo que hoy podrías comprar</h1>
  <p class="muted" style="margin-bottom:24px">Tu folio es <strong><?= pato_esc($folio) ?></strong>.
     Ya quedó registrado y un asesor te contacta.</p>

  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:22px">
    <div class="card"><div class="kpi">$<?= number_format($resultado['inmueble']) ?></div>
      <div class="kpi-l">valor de inmueble alcanzable</div></div>
    <div class="card"><div class="kpi">$<?= number_format($resultado['credito']) ?></div>
      <div class="kpi-l">crédito estimado</div></div>
    <div class="card"><div class="kpi">$<?= number_format($resultado['pago_mensual']) ?></div>
      <div class="kpi-l">pago mensual cómodo</div></div>
  </div>

  <div class="msg err" style="background:#FFF6E5;color:#8a5a00">
    <strong>Esto es una orientación, no una autorización.</strong> El cálculo usa la regla estándar
    de que tu pago no pase del 30% de tu ingreso, con una tasa de referencia. El monto real lo
    define el banco con tu historial y tus documentos. No lo tomes como crédito aprobado.
  </div>

  <div class="card" style="margin-top:18px">
    <h2 style="font-size:17px;margin-bottom:8px">Qué sigue</h2>
    <p class="muted" style="line-height:1.7">
      Un asesor revisa tu caso y te manda las propiedades que sí caben en ese rango — no las que
      te harían perder el tiempo. Y recuerda: nuestros honorarios se pagan al cerrar la operación.
      Si no cierras, no pagas.
    </p>
    <a href="index.php" class="btn" style="margin-top:14px">Volver al inicio</a>
  </div>

<?php else: ?>
  <span class="pill">SIN COSTO · 2 MINUTOS</span>
  <h1 style="font-size:clamp(24px,4.5vw,36px);margin:14px 0 10px">Precalifícate</h1>
  <p class="muted" style="margin-bottom:24px">
    Con esto sabes a qué inmueble puedes aspirar <em>antes</em> de enamorarte de uno que no
    alcanzabas. No pedimos documentos ni consultamos tu buró.
  </p>

  <?php if ($error !== ''): ?><div class="msg err"><?= pato_esc($error) ?></div><?php endif; ?>

  <form method="post" class="card grid">
    <div>
      <label for="n">Tu nombre</label>
      <input id="n" type="text" name="nombre" required value="<?= pato_esc($_POST['nombre'] ?? '') ?>">
    </div>
    <div class="grid" style="grid-template-columns:1fr 1fr;gap:14px">
      <div><label for="c">Correo</label>
        <input id="c" type="email" name="correo" value="<?= pato_esc($_POST['correo'] ?? '') ?>"></div>
      <div><label for="w">WhatsApp</label>
        <input id="w" type="text" name="whatsapp" value="<?= pato_esc($_POST['whatsapp'] ?? '') ?>"></div>
    </div>
    <div class="grid" style="grid-template-columns:1fr 1fr;gap:14px">
      <div><label for="i">Ingreso mensual (MXN)</label>
        <input id="i" type="number" name="ingreso" min="0" step="1000" required
               value="<?= pato_esc($_POST['ingreso'] ?? '') ?>"></div>
      <div><label for="d">Pagos mensuales que ya tienes</label>
        <input id="d" type="number" name="deudas" min="0" step="500"
               value="<?= pato_esc($_POST['deudas'] ?? '0') ?>"></div>
    </div>
    <div class="grid" style="grid-template-columns:1fr 1fr;gap:14px">
      <div><label for="e">Enganche disponible</label>
        <input id="e" type="number" name="enganche" min="0" step="10000"
               value="<?= pato_esc($_POST['enganche'] ?? '0') ?>"></div>
      <div><label for="a">Plazo del crédito</label>
        <select id="a" name="anios">
          <?php foreach ([10, 15, 20, 25] as $y): ?>
            <option value="<?= $y ?>" <?= $y === 20 ? 'selected' : '' ?>><?= $y ?> años</option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div>
      <label for="b">¿Qué buscas?</label>
      <input id="b" type="text" name="busca" placeholder="Casa o depa, zona, recámaras…"
             value="<?= pato_esc($_POST['busca'] ?? '') ?>">
    </div>
    <div>
      <label for="cu">¿Para cuándo?</label>
      <select id="cu" name="cuando">
        <option>Este mes</option><option>En 3 meses</option>
        <option selected>En 6 meses</option><option>Solo estoy explorando</option>
      </select>
    </div>
    <button class="btn btn-acc" type="submit">Ver cuánto puedo comprar</button>
    <p class="muted">Tus datos quedan solo con nosotros. El resultado es orientativo y no es una
       autorización bancaria.</p>
  </form>
<?php endif; ?>
</div>
</body></html>
