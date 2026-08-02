<?php
/**
 * NUCLEO PATO — puerta del panel.
 *
 * Tres caminos en una sola pagina, porque para el dueno del negocio es UNA cosa: entrar.
 *   1. `?t=<token>`  canjea la liga magica  -> deja crear contrasena y entra
 *   2. contrasena    entra si ya la creo
 *   3. correo        pide una liga nueva a los admins del negocio
 *
 * La liga magica es de un solo uso y vence en 15 minutos. La contrasena se guarda HASHEADA.
 * El freno por IP corta los intentos a ciegas.
 */
declare(strict_types=1);

$__pato = is_file(dirname(__DIR__) . '/core/pato.php')
    ? dirname(__DIR__) . '/core/pato.php'    // panel en la raiz del sitio: <sitio>/panel/
    : dirname(__DIR__) . '/pato.php';        // panel dentro del nucleo: <sitio>/core/panel/
require_once $__pato;
require_once __DIR__ . '/_tema.php';

pato_session();
$msg = '';
$tipo = 'ok';
$paso = 'entrar';                 // entrar | crear_pass
$token_correo = null;

// ---------------------------------------------------------------- 1) liga magica
if (isset($_GET['t']) && is_string($_GET['t'])) {
    $correo = pato_canjear((string) $_GET['t']);
    if ($correo === null) {
        $msg = 'Esa liga ya se usó o venció. Pide una nueva abajo.';
        $tipo = 'err';
    } else {
        pato_entrar($correo);
        $_SESSION['pato_puede_crear_pass'] = true;
        $paso = 'crear_pass';
        $token_correo = $correo;
    }
}

// ---------------------------------------------------------------- POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = isset($_POST['accion']) ? (string) $_POST['accion'] : '';

    if (!pato_csrf_ok(isset($_POST['csrf']) ? (string) $_POST['csrf'] : null)) {
        $msg = 'La sesión expiró. Intenta de nuevo.';
        $tipo = 'err';
    } elseif ($accion === 'crear_pass') {
        // Solo quien acaba de entrar por liga magica puede fijar la contrasena.
        if (empty($_SESSION['pato_puede_crear_pass']) || pato_actual() === null) {
            $msg = 'Para crear tu contraseña entra primero con la liga que te mandamos.';
            $tipo = 'err';
        } else {
            $p1 = (string) ($_POST['pass'] ?? '');
            $p2 = (string) ($_POST['pass2'] ?? '');
            if (strlen($p1) < 8) {
                $msg = 'La contraseña necesita al menos 8 caracteres.';
                $tipo = 'err'; $paso = 'crear_pass';
            } elseif ($p1 !== $p2) {
                $msg = 'Las dos contraseñas no coinciden.';
                $tipo = 'err'; $paso = 'crear_pass';
            } elseif (pato_pass_set($p1)) {
                unset($_SESSION['pato_puede_crear_pass']);
                header('Location: index.php?bienvenida=1');
                exit;
            } else {
                $msg = 'No se pudo guardar la contraseña. Vuelve a intentar.';
                $tipo = 'err'; $paso = 'crear_pass';
            }
        }
    } elseif ($accion === 'login') {
        if (pato_frenado()) {
            $msg = 'Demasiados intentos. Espera unos minutos.';
            $tipo = 'err';
        } else {
            $correo = strtolower(trim((string) ($_POST['correo'] ?? '')));
            $pass   = (string) ($_POST['pass'] ?? '');
            if (pato_permitido($correo) && pato_pass_ok($pass)) {
                pato_entrar($correo);
                header('Location: index.php');
                exit;
            }
            pato_fallo();
            $msg = 'Correo o contraseña incorrectos.';
            $tipo = 'err';
        }
    } elseif ($accion === 'liga') {
        $correo = strtolower(trim((string) ($_POST['correo'] ?? '')));
        pato_pedir_liga($correo);   // silencio deliberado: no revelamos quien es admin
        $msg = 'Si ese correo puede entrar, ya va en camino una liga de acceso. Vence en 15 minutos.';
        $tipo = 'ok';
    }
}

// Recien activado (o entro por liga en otra pestaña): toca crear contrasena, no ir al panel.
if ($paso === 'entrar' && pato_actual() !== null && !empty($_SESSION['pato_puede_crear_pass'])) {
    $paso = 'crear_pass';
    $token_correo = pato_actual();
    if (isset($_GET['activado'])) {
        $msg = 'Tu panel quedó activado. Elige una contraseña para entrar cuando quieras.';
        $tipo = 'ok';
    }
}

// Si ya hay sesion y no viene a crear contrasena, directo al panel.
if ($paso === 'entrar' && pato_actual() !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$csrf = pato_csrf();
$marca = pato_esc(pato_cfg('marca', 'PATO'));
echo pato_panel_head($paso === 'crear_pass' ? 'Crea tu contraseña' : 'Entrar');
echo pato_panel_nav(null);
?>
<div class="wrap" style="max-width:460px;padding-top:clamp(36px,7vw,72px)">
  <?php if ($msg !== ''): ?>
    <div class="msg <?= $tipo ?>"><?= pato_esc($msg) ?></div>
  <?php endif; ?>

  <?php if ($paso === 'crear_pass'): ?>
    <h1 style="font-size:clamp(24px,4vw,32px);margin-bottom:8px">Crea tu contraseña</h1>
    <p class="muted" style="margin-bottom:22px">
      Entraste como <strong><?= pato_esc($token_correo ?: (string) pato_actual()) ?></strong>.
      Elige una contraseña para volver a entrar cuando quieras, sin esperar otra liga.
    </p>
    <form method="post" class="card grid">
      <input type="hidden" name="csrf" value="<?= pato_esc($csrf) ?>">
      <input type="hidden" name="accion" value="crear_pass">
      <div>
        <label for="p1">Contraseña nueva</label>
        <input id="p1" type="password" name="pass" minlength="8" required autocomplete="new-password">
        <p class="muted" style="margin-top:6px">Mínimo 8 caracteres.</p>
      </div>
      <div>
        <label for="p2">Repítela</label>
        <input id="p2" type="password" name="pass2" minlength="8" required autocomplete="new-password">
      </div>
      <button class="btn btn-acc" type="submit">Guardar y entrar al panel</button>
    </form>
  <?php else: ?>
    <h1 style="font-size:clamp(24px,4vw,32px);margin-bottom:8px">Entrar a tu panel</h1>
    <p class="muted" style="margin-bottom:22px">Panel de control de <?= $marca ?>.</p>

    <form method="post" class="card grid" style="margin-bottom:18px">
      <input type="hidden" name="csrf" value="<?= pato_esc($csrf) ?>">
      <input type="hidden" name="accion" value="login">
      <div>
        <label for="c1">Correo</label>
        <input id="c1" type="email" name="correo" required autocomplete="username">
      </div>
      <div>
        <label for="pw">Contraseña</label>
        <input id="pw" type="password" name="pass" required autocomplete="current-password">
      </div>
      <button class="btn" type="submit">Entrar</button>
    </form>

    <form method="post" class="card grid">
      <input type="hidden" name="csrf" value="<?= pato_esc($csrf) ?>">
      <input type="hidden" name="accion" value="liga">
      <div>
        <label for="c2">¿Todavía no tienes contraseña?</label>
        <input id="c2" type="email" name="correo" placeholder="tu@correo.com" required>
        <p class="muted" style="margin-top:6px">Te mandamos una liga de acceso para crearla.</p>
      </div>
      <button class="btn btn-acc" type="submit">Mandarme una liga</button>
    </form>
  <?php endif; ?>
</div>
<?= pato_panel_pie() ?>
