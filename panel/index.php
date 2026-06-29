<?php
// Panel de Fundador — UI. Login por correo (magic-link) y, ya dentro: ver/aprobar contenido,
// dejar feedback y ver analíticas. Las acciones se encolan en data/inbox.jsonl (PATO las aplica).
declare(strict_types=1);
require __DIR__ . '/lib.php';
$c = pnl_cfg();

if (isset($_GET['logout'])) { pnl_logout(); header('Location: ./'); exit; }

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    if ($act === 'login') {
        $email = (string) ($_POST['email'] ?? '');
        $raw = pnl_request_login($email);
        if ($raw !== null) { pnl_send_magic($email, $raw); $flash = 'Te enviamos un enlace de acceso a tu correo (válido 30 min).'; }
        else { $flash = 'Ese correo no está autorizado para este panel.'; }
    } elseif ($act === 'review' && pnl_current() && pnl_csrf_ok($_POST['csrf'] ?? null)) {
        $pid = trim((string) ($_POST['piece_id'] ?? ''));
        $dec = (string) ($_POST['decision'] ?? '');
        $fb  = trim((string) ($_POST['feedback'] ?? ''));
        if ($pid && in_array($dec, ['approve', 'reject', 'feedback'], true)) {
            pnl_inbox_append(['action' => $dec, 'piece_id' => $pid, 'feedback' => $fb, 'by' => pnl_current(), 'at' => date('c')]);
            $flash = $dec === 'approve' ? '✓ Aprobado. Se publicará según el calendario.'
                   : ($dec === 'reject' ? '✕ Rechazado. No saldrá.' : 'Feedback enviado al equipo.');
        }
    }
}

$me = pnl_current();
$snap = $me ? pnl_snapshot() : [];
$accent = $c['accent'];
?>
<!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Panel · <?= pnl_esc($c['brand']) ?></title>
<style>
  :root{--accent:<?= pnl_esc($accent) ?>;--bg:#14110f;--card:#1d1813;--line:#322a22;--ink:#f3efe8;--soft:#b8ab97;--mute:#8a7d6c;--ok:#8ea596;--danger:#cf6a4f}
  *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
  a{color:var(--accent)}.wrap{max-width:920px;margin:0 auto;padding:22px}
  header.top{display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line);padding:18px 0;margin-bottom:8px}
  .brand{font:700 22px Georgia,serif}.brand small{display:block;font:600 11px system-ui;letter-spacing:.14em;text-transform:uppercase;color:var(--mute)}
  .who{font-size:13px;color:var(--soft);text-align:right}
  .flash{background:#241d16;border:1px solid var(--accent);border-radius:12px;padding:12px 16px;margin:16px 0;color:#f6e7d6}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:14px 0 26px}
  .stat{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px}
  .stat b{font:700 26px Georgia,serif;display:block}.stat span{font-size:12px;color:var(--mute);text-transform:uppercase;letter-spacing:.08em}
  h2{font:700 15px system-ui;letter-spacing:.06em;text-transform:uppercase;color:var(--soft);margin:26px 0 10px;border-bottom:1px solid var(--line);padding-bottom:6px}
  .piece{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px 20px;margin-bottom:14px}
  .piece .k{font:600 10.5px system-ui;letter-spacing:.08em;text-transform:uppercase;color:var(--accent)}
  .piece h3{margin:6px 0 8px;font:700 19px Georgia,serif}
  .piece .body{color:var(--soft);font-size:14.5px;white-space:pre-wrap;max-height:200px;overflow:auto;border-left:2px solid var(--line);padding-left:12px;margin:8px 0}
  .acts{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px}
  textarea,input[type=email]{width:100%;background:#0f0c0a;border:1px solid var(--line);border-radius:10px;color:var(--ink);padding:11px 13px;font:15px system-ui}
  textarea{min-height:54px;resize:vertical}
  .btn{border:0;border-radius:10px;padding:10px 16px;font:600 14px system-ui;cursor:pointer}
  .btn.ok{background:var(--accent);color:#1a120b}.btn.no{background:#2a221c;color:var(--soft);border:1px solid var(--line)}
  .btn.send{background:#2a221c;color:var(--ink);border:1px solid var(--line)}
  .pp{display:flex;justify-content:space-between;font-size:13px;color:var(--soft);padding:7px 0;border-bottom:1px solid var(--line)}
  .empty{color:var(--mute);padding:26px;text-align:center;background:var(--card);border:1px dashed var(--line);border-radius:14px}
  .login{max-width:420px;margin:8vh auto;text-align:center}.login form{margin-top:18px;display:flex;gap:8px}
  .foot{color:var(--mute);font-size:12px;text-align:center;margin:34px 0 10px}
</style></head>
<body>
<?php if (!$me): ?>
  <div class="wrap login">
    <div class="brand"><?= pnl_esc($c['emoji']) ?> <?= pnl_esc($c['brand']) ?><small>Panel de fundador</small></div>
    <p style="color:var(--soft);margin-top:14px">Entra con tu correo. Te mandamos un enlace mágico, sin contraseña.</p>
    <?php if ($flash): ?><div class="flash"><?= pnl_esc($flash) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="action" value="login">
      <input type="email" name="email" placeholder="tu@correo.com" required autofocus>
      <button class="btn ok" type="submit">Entrar</button>
    </form>
    <div class="foot">Acceso restringido a fundadores del proyecto.</div>
  </div>
<?php else:
  $a = $snap['analytics'] ?? [];
  $pending = $snap['pending'] ?? [];
  $recent = $snap['recent'] ?? [];
  $top = $snap['top_pages'] ?? [];
  $csrf = pnl_csrf();
?>
  <div class="wrap">
    <header class="top">
      <div class="brand"><?= pnl_esc($c['emoji']) ?> <?= pnl_esc($c['brand']) ?><small>Panel de fundador</small></div>
      <div class="who"><?= pnl_esc($me) ?><br><a href="?logout">salir</a></div>
    </header>
    <?php if ($flash): ?><div class="flash"><?= pnl_esc($flash) ?></div><?php endif; ?>

    <h2>Analíticas <?= isset($a['period']) ? '· ' . pnl_esc($a['period']) : '' ?></h2>
    <?php if ($a && (($a['sessions'] ?? null) !== null)): ?>
      <div class="grid">
        <div class="stat"><b><?= pnl_esc((string)($a['sessions'] ?? '—')) ?></b><span>Sesiones</span></div>
        <div class="stat"><b><?= pnl_esc((string)($a['activeUsers'] ?? '—')) ?></b><span>Usuarios</span></div>
        <div class="stat"><b><?= pnl_esc((string)($a['screenPageViews'] ?? '—')) ?></b><span>Vistas</span></div>
      </div>
      <?php if ($top): ?>
        <?php foreach (array_slice($top, 0, 6) as $p): ?>
          <div class="pp"><span><?= pnl_esc($p['path'] ?? '') ?></span><span><?= (int)($p['views'] ?? 0) ?> vistas</span></div>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php else: ?>
      <div class="empty">Las analíticas aparecerán aquí en cuanto se conecten los datos del sitio.</div>
    <?php endif; ?>

    <h2>Contenido por aprobar <?= $pending ? '· ' . count($pending) : '' ?></h2>
    <?php if (!$pending): ?>
      <div class="empty">Nada pendiente ahora mismo. Cuando el equipo prepare contenido, lo verás aquí para aprobar.</div>
    <?php else: foreach ($pending as $p): ?>
      <div class="piece">
        <div class="k"><?= pnl_esc($p['kind'] ?? 'pieza') ?><?= isset($p['platform']) ? ' · ' . pnl_esc($p['platform']) : '' ?></div>
        <h3><?= pnl_esc($p['title'] ?? 'Sin título') ?></h3>
        <div class="body"><?= pnl_esc(mb_substr((string)($p['body'] ?? ''), 0, 1200)) ?></div>
        <form method="post" class="acts">
          <input type="hidden" name="action" value="review">
          <input type="hidden" name="csrf" value="<?= pnl_esc($csrf) ?>">
          <input type="hidden" name="piece_id" value="<?= pnl_esc($p['id'] ?? '') ?>">
          <textarea name="feedback" placeholder="Feedback (opcional)…"></textarea>
          <button class="btn ok" name="decision" value="approve" type="submit">✓ Aprobar</button>
          <button class="btn no" name="decision" value="reject" type="submit">✕ Rechazar</button>
          <button class="btn send" name="decision" value="feedback" type="submit">Enviar feedback</button>
        </form>
      </div>
    <?php endforeach; endif; ?>

    <?php if ($recent): ?>
      <h2>Reciente</h2>
      <?php foreach (array_slice($recent, 0, 8) as $r): ?>
        <div class="pp"><span><?= pnl_esc($r['title'] ?? '') ?></span><span><?= pnl_esc($r['status'] ?? '') ?></span></div>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="foot">Actualizado: <?= pnl_esc($snap['generated_at'] ?? '—') ?> · Patológicos</div>
  </div>
<?php endif; ?>
</body></html>
