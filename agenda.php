<?php
// Manejador del formulario "Auditoría de Proceso con IA" (PROCA P0, puerta de entrada).
// Valida -> guarda en data/agenda.jsonl (gitignored) -> avisa por correo -> gracias.
// El formulario vive en index.html (#contacto) y postea aquí. GET directo -> vuelve a la landing.
declare(strict_types=1);

const AG_TO   = 'hola@patologicos.com';        // bandeja que recibe las solicitudes
const AG_FROM = 'contacto@patologicos.com';
const AG_DATA = __DIR__ . '/data';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ./#contacto'); exit; }
if (!empty($_POST['website'])) { header('Location: ./#contacto'); exit; }  // honeypot anti-spam

function ag_clean(string $s, int $max): string {
    return mb_substr(trim(str_replace(["\r"], '', $s)), 0, $max);
}

$nombre    = ag_clean((string) ($_POST['nombre'] ?? ''), 120);
$empresa   = ag_clean((string) ($_POST['empresa'] ?? ''), 160);
$correo    = ag_clean((string) ($_POST['correo'] ?? ''), 160);
$telefono  = ag_clean((string) ($_POST['telefono'] ?? ''), 60);
$industria = ag_clean((string) ($_POST['industria'] ?? ''), 120);
$volumen   = ag_clean((string) ($_POST['volumen'] ?? ''), 160);
$proceso   = ag_clean((string) ($_POST['proceso'] ?? ''), 4000);
$interes   = ag_clean((string) ($_POST['interes'] ?? ''), 80) ?: 'No especificado';

$ok = ($nombre !== '' && $empresa !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL) && $proceso !== '');
if ($ok) {
    if (!is_dir(AG_DATA)) @mkdir(AG_DATA, 0750, true);
    $h = AG_DATA . '/.htaccess';
    if (!is_file($h)) @file_put_contents($h, "Require all denied\nDeny from all\n");
    $rec = ['at' => date('c'), 'interes' => $interes, 'nombre' => $nombre, 'empresa' => $empresa, 'correo' => $correo,
            'telefono' => $telefono, 'industria' => $industria, 'volumen' => $volumen,
            'proceso' => $proceso, 'ip' => ($_SERVER['REMOTE_ADDR'] ?? '')];
    @file_put_contents(AG_DATA . '/agenda.jsonl', json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    $body = "Nueva solicitud — $interes\n\n"
          . "Nombre: $nombre\nEmpresa: $empresa\nCorreo: $correo\nWhatsApp/tel: $telefono\n"
          . "Industria: $industria\nTamaño/volumen: $volumen\n\nSobre su negocio / qué quiere mejorar:\n$proceso\n";
    @mail(AG_TO, "Lead [$interes] — $empresa ($nombre)", $body, "From: " . AG_FROM . "\r\nReply-To: $correo\r\n");
}
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="es"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex">
<title><?= $ok ? 'Solicitud recibida' : 'Revisa el formulario' ?> · Patológicos</title>
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<script async src="https://www.googletagmanager.com/gtag/js?id=G-XESKPMDFSR"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-XESKPMDFSR');<?= $ok ? "gtag('event','generate_lead',{form:'auditoria'});" : '' ?></script>
<style>
:root{--bg:#0f1115;--surface:#1c2029;--line:rgba(255,255,255,.09);--ink:#eef1f6;--soft:#c7cdd8;--accent:#ffcf4d;--serif:Georgia,serif}
*{box-sizing:border-box;margin:0}body{background:radial-gradient(120% 120% at 80% -20%,#23283a,#0f1115 60%);color:var(--ink);font:16px/1.6 -apple-system,'Segoe UI',Roboto,Arial,sans-serif;min-height:100vh;display:grid;place-items:center;padding:24px}
.card{max-width:540px;background:var(--surface);border:1px solid var(--line);border-radius:18px;padding:40px;text-align:center}
h1{font-family:var(--serif);font-size:30px;margin-bottom:12px}.ic{font-size:46px}
p{color:var(--soft);margin:10px 0}.btn{display:inline-block;margin-top:18px;background:var(--accent);color:#0f1115;border-radius:999px;padding:12px 26px;font-weight:700;text-decoration:none}
a{color:var(--accent)}
</style></head><body>
<div class="card">
<?php if ($ok): ?>
  <div class="ic">✅</div>
  <h1>¡Gracias, <?= $e($nombre) ?>!</h1>
  <p>Recibimos tu solicitud — <b><?= $e($interes) ?></b> para <b><?= $e($empresa) ?></b>.</p>
  <p>Te respondemos en <b>1 día hábil</b> a <?= $e($correo) ?> con los próximos pasos.</p>
  <a class="btn" href="./">Volver a Patológicos</a>
<?php else: ?>
  <div class="ic">⚠️</div>
  <h1>Faltó algún dato</h1>
  <p>Necesitamos al menos tu <b>nombre</b>, <b>empresa</b>, un <b>correo válido</b> y el <b>proceso a auditar</b>.</p>
  <a class="btn" href="./#contacto">Volver al formulario</a>
<?php endif; ?>
</div>
</body></html>
