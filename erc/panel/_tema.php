<?php
/**
 * NUCLEO PATO — tema del panel.
 *
 * El panel es GENERICO: el mismo codigo sirve a cualquier negocio y toma su identidad de
 * `negocio.json`. Aqui vive solo la presentacion, para que las paginas queden legibles.
 */
declare(strict_types=1);

function pato_panel_css(): string
{
    return "*{box-sizing:border-box;margin:0;padding:0}
:root{--ink:#1B1C18;--paper:#F4F3EE;--card:#FBFAF7;--acc:#C8ED4B;--dark:#191A16;
--muted:#86867C;--line:#E4E2DA;--soft:#EEEDE6;--chipbg:#EAF1D2;--chipfg:#5c7a12;
--display:'Space Grotesk',system-ui,sans-serif;--body:'Hanken Grotesk',system-ui,'Segoe UI',sans-serif}
body{background:var(--paper);color:var(--ink);font-family:var(--body);font-size:16px;line-height:1.6;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
h1,h2,h3{font-family:var(--display);font-weight:600;letter-spacing:-.02em;line-height:1.2}
.wrap{max-width:1080px;margin:0 auto;padding:0 clamp(18px,4vw,32px)}
header.nav{position:sticky;top:0;z-index:50;background:rgba(244,243,238,.94);backdrop-filter:blur(8px);
border-bottom:1px solid var(--line);display:flex;align-items:center;gap:14px;padding:0 clamp(18px,4vw,32px);height:62px}
.brand{font-family:var(--display);font-size:19px;font-weight:700;letter-spacing:-.02em}
.pill{background:var(--chipbg);color:var(--chipfg);font-size:11.5px;font-weight:700;border-radius:999px;padding:4px 11px}
.nav .right{margin-left:auto;display:flex;align-items:center;gap:14px;font-size:14px;color:#43443D}
.btn{display:inline-block;background:var(--dark);color:var(--paper);font-weight:600;font-size:14.5px;
padding:12px 20px;border-radius:999px;border:0;cursor:pointer;font-family:var(--body)}
.btn:hover{background:#000}
.btn-acc{background:var(--acc);color:var(--dark)}
.btn-acc:hover{background:#d8f76b}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:22px 24px}
.grid{display:grid;gap:16px}
label{display:block;font-size:13.5px;font-weight:600;color:#43443D;margin-bottom:6px}
input[type=email],input[type=password],input[type=text],select,textarea{
width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:10px;background:#fff;
font-family:var(--body);font-size:15px;color:var(--ink)}
input:focus,select:focus{outline:2px solid var(--acc);outline-offset:1px}
.msg{border-radius:10px;padding:12px 14px;font-size:14.5px;margin-bottom:16px;line-height:1.55}
.ok{background:var(--chipbg);color:var(--chipfg)}
.err{background:#FBE9E7;color:#A33A28}
.muted{color:var(--muted);font-size:13px}
table{width:100%;border-collapse:collapse;font-size:14.5px}
th{text-align:left;font-size:12px;font-weight:700;letter-spacing:.03em;color:var(--muted);
padding:0 10px 10px;border-bottom:1px solid var(--line)}
td{padding:12px 10px;border-bottom:1px solid var(--soft);vertical-align:top}
.kpi{font-family:var(--display);font-weight:600;font-size:28px;letter-spacing:-.02em}
.kpi-l{font-size:12.5px;color:var(--muted);margin-top:3px}
.bar{height:8px;background:var(--soft);border-radius:99px;overflow:hidden}
.bar>i{display:block;height:100%;background:var(--acc)}
.tag{display:inline-block;background:var(--soft);color:#43443D;font-size:12px;font-weight:600;
border-radius:999px;padding:3px 10px}
@media(max-width:640px){.hide-sm{display:none}}";
}

function pato_panel_head(string $titulo): string
{
    $marca = pato_esc(pato_cfg('marca', 'PATO'));
    return '<!DOCTYPE html><html lang="es-MX"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . pato_esc($titulo) . ' · ' . $marca . '</title>'
        . '<link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700'
        . '&family=Hanken+Grotesk:wght@400;500;600;700;800&display=swap" rel="stylesheet">'
        . '<style>' . pato_panel_css() . '</style></head><body>';
}

function pato_panel_nav(?string $usuario): string
{
    $marca = pato_esc(pato_cfg('marca', 'PATO'));
    $h = '<header class="nav"><span class="brand">' . $marca . '</span>'
       . '<span class="pill">PANEL</span><div class="right">';
    if ($usuario) {
        $h .= '<span class="hide-sm muted">' . pato_esc($usuario) . '</span>'
            . '<a href="salir.php">Salir</a>';
    }
    return $h . '</div></header>';
}

function pato_panel_pie(): string
{
    return '<div class="wrap" style="padding-top:36px;padding-bottom:36px">'
         . '<p class="muted">Operado con PATO · los datos de este negocio viven aislados y fuera de la web.</p>'
         . '</div></body></html>';
}
