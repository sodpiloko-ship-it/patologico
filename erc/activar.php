<?php
/**
 * ERC — ACTIVACION de primer uso (se auto-desactiva).
 *
 * Por que existe: por diseno, los correos de los administradores NUNCA viajan en el repo
 * (el contrato de alta los rechaza y `negocio.local.json` esta gitignoreado). Alguien tiene
 * que ponerlos en el servidor. En vez de pedir una subida manual de archivo, esta pagina lo
 * hace en un paso — y de paso resuelve la ruta REAL de datos, que solo se conoce corriendo
 * en el servidor.
 *
 * Seguridad:
 *   - Exige una llave aleatoria en la URL (se entrega por separado, nunca se publica).
 *   - Solo funciona MIENTRAS no exista `negocio.local.json`. En cuanto se activa, muere:
 *     una segunda visita responde 410 y ya no puede reclamar nada.
 *   - Deja los datos FUERA de public_html cuando el hosting lo permite.
 */
declare(strict_types=1);

const ERC_LLAVE = 'a7gB6HfztqmcVI_QYnByCLt1E9apQA5w';

require_once __DIR__ . '/core/pato.php';
require_once __DIR__ . '/panel/_tema.php';

$local = __DIR__ . '/negocio.local.json';

// Ya activado -> esta pagina deja de existir.
if (is_file($local)) {
    http_response_code(410);
    echo pato_panel_head('Activación cerrada');
    echo '<div class="wrap" style="max-width:520px;padding-top:60px">'
       . '<div class="msg ok">Este panel ya está activado. '
       . '<a href="panel/entrar.php" style="color:var(--chipfg)"><strong>Entrar al panel</strong></a>.</div>'
       . '</div></body></html>';
    exit;
}

// Llave equivocada -> ni confirmamos que la pagina existe.
if (!hash_equals(ERC_LLAVE, (string) ($_GET['k'] ?? ''))) {
    http_response_code(404);
    echo 'No encontrado';
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = strtolower(trim((string) ($_POST['correo'] ?? '')));
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $error = 'Escribe un correo válido.';
    } else {
        // Ruta de datos FUERA de la raiz web cuando el hosting lo permite.
        $cfg = ['admins' => [$correo], 'avisos' => [$correo]];
        $root = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($root !== '' && is_dir(dirname($root))) {
            $fuera = rtrim(str_replace('\\', '/', dirname($root)), '/') . '/.pato-data-ercinmuebles';
            if (@mkdir($fuera, 0700, true) || is_dir($fuera)) {
                @file_put_contents($fuera . '/.htaccess', "Require all denied\nDeny from all\n");
                $cfg['data_dir'] = $fuera;
            }
        }
        $json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmp = $local . '.tmp';
        if ($json !== false && @file_put_contents($tmp, $json, LOCK_EX) !== false && @rename($tmp, $local)) {
            // Entra ya y manda a crear su contrasena.
            pato_session();
            session_regenerate_id(true);
            $_SESSION['pato_user'] = $correo;
            $_SESSION['pato_neg']  = 'ercinmuebles';
            $_SESSION['pato_puede_crear_pass'] = true;
            header('Location: panel/entrar.php?activado=1');
            exit;
        }
        $error = 'No se pudo escribir la configuración en el servidor. Revisa permisos de escritura.';
    }
}

echo pato_panel_head('Activar tu panel');
?>
<header class="nav"><span class="brand">ERC Inmuebles</span><span class="pill">ACTIVACIÓN</span></header>
<div class="wrap" style="max-width:480px;padding-top:clamp(36px,7vw,72px)">
  <h1 style="font-size:clamp(24px,4vw,32px);margin-bottom:10px">Activa tu panel</h1>
  <p class="muted" style="margin-bottom:22px">
    Escribe el correo que será el dueño de este panel. Queda guardado <strong>solo en el
    servidor</strong> — nunca en el repositorio. Después de esto, esta página deja de existir.
  </p>
  <?php if ($error !== ''): ?><div class="msg err"><?= pato_esc($error) ?></div><?php endif; ?>
  <form method="post" class="card grid">
    <div>
      <label for="c">Tu correo</label>
      <input id="c" type="email" name="correo" required autofocus placeholder="tu@correo.com">
    </div>
    <button class="btn btn-acc" type="submit">Activar y crear mi contraseña</button>
  </form>
</div>
</body></html>
