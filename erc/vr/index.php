<?php
/**
 * ERC — EXPERIENCIA INMERSIVA DE ESPACIO.
 *
 * A pantalla completa a proposito: el espacio ES la pagina, no una tarjeta dentro de una.
 * Los controles flotan encima y se desvanecen solos cuando el visitante deja de tocarlos,
 * para que quede el inmueble y nada mas.
 *
 * Lo que se vende no es la foto: es el POTENCIAL. Por eso el visitante mueve el sol y
 * repinta los muros el mismo. Cada exploracion queda medida en el embudo del propio
 * servidor (nucleo PATO): el vendedor sabe que espacio se explora y cual no.
 */
declare(strict_types=1);
require_once __DIR__ . '/../core/pato.php';

pato_evento('visita');
pato_evento('producto');

$data = json_decode((string) @file_get_contents(__DIR__ . '/../data/espacios.json'), true);
$espacios = (is_array($data) && !empty($data['espacios'])) ? $data['espacios'] : [];
$muros = $data['muros']['opciones'] ?? [];
$pisos = $data['pisos']['opciones'] ?? [];
$marca = pato_cfg('marca', 'ERC Inmuebles');
?><!DOCTYPE html>
<html lang="es-MX">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#0b1023">
<title>Recorre el espacio · <?= pato_esc($marca) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Hanken+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  :root{ --acc:#C8ED4B; --tinta:#F4F3EE; --vidrio:rgba(18,19,16,.62) }
  html,body{ height:100%; overflow:hidden; background:#0b1023;
    font-family:'Hanken Grotesk',system-ui,'Segoe UI',sans-serif; color:var(--tinta) }
  #visor{ position:fixed; inset:0; z-index:0 }
  #visor canvas{ display:block; width:100%; height:100%; touch-action:none }

  /* --- capa de controles: flota sobre el espacio y se desvanece sola --- */
  .hud{ position:fixed; z-index:2; transition:opacity .45s ease, transform .45s ease }
  body.reposo .hud.desvanece{ opacity:0; transform:translateY(6px); pointer-events:none }

  .vidrio{ background:var(--vidrio); backdrop-filter:blur(14px);
    -webkit-backdrop-filter:blur(14px); border:1px solid rgba(255,255,255,.10);
    border-radius:14px }

  .arriba{ top:0; left:0; right:0; display:flex; align-items:center; gap:12px;
    padding:max(14px,env(safe-area-inset-top)) 16px 14px;
    background:linear-gradient(180deg,rgba(8,9,7,.72),transparent) }
  .marca{ font-family:'Space Grotesk',sans-serif; font-weight:700; font-size:17px;
    letter-spacing:-.02em; text-decoration:none; color:var(--tinta) }
  .etiqueta{ font-size:11px; font-weight:700; letter-spacing:.06em; color:var(--acc);
    border:1px solid rgba(200,237,75,.4); border-radius:99px; padding:3px 9px }
  .arriba .der{ margin-left:auto; display:flex; gap:8px; align-items:center }

  .espacios{ display:flex; gap:7px; overflow-x:auto; scrollbar-width:none; max-width:52vw }
  .espacios::-webkit-scrollbar{ display:none }
  .chip{ background:var(--vidrio); backdrop-filter:blur(14px); color:var(--tinta);
    border:1px solid rgba(255,255,255,.12); border-radius:99px; padding:8px 15px;
    font:inherit; font-size:13.5px; white-space:nowrap; cursor:pointer }
  .chip[aria-pressed="true"]{ background:var(--acc); color:#11120f; border-color:var(--acc);
    font-weight:600 }

  .abajo{ left:0; right:0; bottom:0; display:flex; justify-content:center;
    padding:16px 16px max(18px,env(safe-area-inset-bottom)) }
  .barra{ display:flex; align-items:center; gap:16px; flex-wrap:wrap; justify-content:center;
    padding:12px 18px; max-width:min(96vw,860px) }
  .bloque{ display:flex; align-items:center; gap:9px }
  .rotulo{ font-size:11.5px; letter-spacing:.05em; opacity:.62; text-transform:uppercase }
  #hora{ width:min(34vw,230px); accent-color:var(--acc) }
  #reloj{ font-family:'Space Grotesk',sans-serif; font-variant-numeric:tabular-nums;
    font-size:14px; min-width:104px }
  .muestra{ width:26px; height:26px; border-radius:7px; cursor:pointer; padding:0;
    border:2px solid rgba(255,255,255,.42) }
  .muestra[aria-pressed="true"]{ border-color:var(--acc); transform:scale(1.12) }

  .btn{ background:rgba(255,255,255,.10); color:var(--tinta); border:1px solid rgba(255,255,255,.16);
    border-radius:99px; padding:8px 14px; font:inherit; font-size:13.5px; cursor:pointer;
    text-decoration:none; display:inline-flex; align-items:center; gap:6px; white-space:nowrap }
  .btn:hover{ background:rgba(255,255,255,.18) }
  .btn.lima{ background:var(--acc); color:#11120f; border-color:var(--acc); font-weight:600 }

  /* --- ficha lateral --- */
  #ficha{ position:fixed; z-index:3; top:0; right:0; bottom:0; width:min(400px,88vw);
    background:rgba(14,15,12,.94); backdrop-filter:blur(18px); padding:26px 24px;
    border-left:1px solid rgba(255,255,255,.10); overflow-y:auto;
    transform:translateX(100%); transition:transform .4s cubic-bezier(.4,0,.2,1) }
  #ficha.abierta{ transform:none }
  #ficha h2{ font-family:'Space Grotesk',sans-serif; font-size:22px; font-weight:600;
    letter-spacing:-.02em; margin:14px 0 10px; line-height:1.2 }
  #ficha p{ font-size:14.5px; line-height:1.75; color:#C9C9C0 }
  .dato{ display:flex; justify-content:space-between; padding:11px 0;
    border-bottom:1px solid rgba(255,255,255,.09); font-size:14px }
  .dato span:last-child{ font-family:'Space Grotesk',sans-serif; font-weight:600 }
  .nota{ background:rgba(255,214,120,.12); border:1px solid rgba(255,214,120,.28);
    color:#F0D9A8; border-radius:11px; padding:12px 14px; font-size:13px; line-height:1.65;
    margin-top:18px }

  #cargando{ position:fixed; inset:0; z-index:5; display:grid; place-items:center;
    background:#0b1023; font-size:14.5px; letter-spacing:.02em }
  .pista{ position:fixed; z-index:1; left:50%; transform:translateX(-50%); bottom:96px;
    font-size:12.5px; opacity:.5; pointer-events:none; transition:opacity .5s }
  body.tocado .pista{ opacity:0 }
  @media(max-width:720px){
    .espacios{ max-width:40vw } .rotulo{ display:none } #reloj{ min-width:88px }
  }
</style>
</head>
<body>

<div id="visor"></div>
<div id="cargando">Armando el espacio…</div>
<div class="pista">Arrastra para mirar alrededor · pellizca para acercarte</div>

<div class="hud arriba desvanece">
  <a class="marca" href="../index.php"><?= pato_esc($marca) ?></a>
  <span class="etiqueta">RECORRIDO</span>
  <div class="der">
    <div class="espacios" id="espacios"></div>
    <button class="btn" id="btnFicha" aria-label="Ver detalle del espacio">Detalle</button>
    <button class="btn" id="btnPantalla" aria-label="Pantalla completa">⛶</button>
  </div>
</div>

<div class="hud abajo desvanece">
  <div class="barra vidrio">
    <div class="bloque">
      <span id="reloj">13:00 · mediodía</span>
      <input type="range" id="hora" min="5" max="21" step="0.25" value="13" aria-label="Hora del día">
    </div>
    <div class="bloque">
      <span class="rotulo">Muros</span>
      <span id="muros" style="display:flex;gap:6px"></span>
    </div>
    <div class="bloque">
      <span class="rotulo">Piso</span>
      <span id="pisos" style="display:flex;gap:6px"></span>
    </div>
  </div>
</div>

<aside id="ficha">
  <button class="btn" id="cerrarFicha" style="float:right">Cerrar</button>
  <span class="etiqueta" id="zonaFicha"></span>
  <h2 id="tituloFicha"></h2>
  <p id="descFicha"></p>
  <div style="margin-top:20px">
    <div class="dato"><span>Superficie</span><span id="dSup"></span></div>
    <div class="dato"><span>Altura de techo</span><span id="dAlto"></span></div>
    <div class="dato"><span>Ventanas</span><span id="dVent"></span></div>
  </div>
  <div class="nota" id="notaDemo" style="display:none">
    <strong>Espacio de demostración.</strong> Modelo con medidas de ejemplo para mostrar la
    tecnología — no es un inmueble en venta. Cuando entra una propiedad real se carga con
    sus medidas verdaderas y queda navegable igual.
  </div>
  <a href="../precalifica.php?tipo=vendedor" class="btn lima" style="margin-top:22px;width:100%;justify-content:center">
    Quiero levantar mi inmueble</a>
  <a href="../precalifica.php" class="btn" style="margin-top:10px;width:100%;justify-content:center">
    Precalifícate para comprar</a>
</aside>

<script type="importmap">
{ "imports": {
    "three": "../vendor/three.module.js",
    "three/addons/controls/OrbitControls.js": "../vendor/OrbitControls.js"
} }
</script>
<script type="module">
import { Espacio3D } from './espacio3d.js';

const DATOS = <?= json_encode([
    'espacios' => $espacios, 'muros' => $muros, 'pisos' => $pisos,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const $ = (id) => document.getElementById(id);
let visor = null;

const nombreHora = (h) => {
  const hh = Math.floor(h), mm = Math.round((h - hh) * 60);
  const reloj = `${String(hh).padStart(2,'0')}:${String(mm).padStart(2,'0')}`;
  const etq = h < 7 ? 'amanecer' : h < 11 ? 'mañana' : h < 15 ? 'mediodía'
            : h < 18 ? 'tarde' : h < 19.8 ? 'atardecer' : 'noche';
  return `${reloj} · ${etq}`;
};

function muestras(cont, opciones, aplicar, activo = 0) {
  cont.innerHTML = '';
  opciones.forEach((o, i) => {
    const b = document.createElement('button');
    b.className = 'muestra'; b.style.background = o.hex;
    b.title = o.nombre; b.setAttribute('aria-label', o.nombre);
    b.setAttribute('aria-pressed', String(i === activo));
    b.onclick = () => {
      [...cont.children].forEach(c => c.setAttribute('aria-pressed','false'));
      b.setAttribute('aria-pressed','true');
      aplicar(o.hex);
    };
    cont.appendChild(b);
  });
}

function abrirFicha(e) {
  $('zonaFicha').textContent = e.zona || '';
  $('tituloFicha').textContent = e.titulo;
  $('descFicha').textContent = e.descripcion;
  $('dSup').textContent = `${(e.largo * e.ancho).toFixed(1)} m²`;
  $('dAlto').textContent = `${e.alto} m`;
  $('dVent').textContent = (e.huecos || []).filter(h => h.tipo === 'ventana').length;
  $('notaDemo').style.display = e.demo ? 'block' : 'none';
}

function cargar(i) {
  const e = DATOS.espacios[i];
  [...$('espacios').children].forEach((t, k) => t.setAttribute('aria-pressed', String(k === i)));
  abrirFicha(e);
  if (visor) visor.cargar(e);
}

DATOS.espacios.forEach((e, i) => {
  const b = document.createElement('button');
  b.className = 'chip'; b.textContent = e.titulo;
  b.setAttribute('aria-pressed', String(i === 0));
  b.onclick = () => cargar(i);
  $('espacios').appendChild(b);
});

// --- reposo: los controles se van solos para dejar el espacio limpio ---
let temporizador;
const despertar = () => {
  document.body.classList.remove('reposo');
  document.body.classList.add('tocado');
  clearTimeout(temporizador);
  temporizador = setTimeout(() => document.body.classList.add('reposo'), 3800);
};
['pointermove','pointerdown','keydown','wheel','touchstart'].forEach(
  ev => window.addEventListener(ev, despertar, { passive: true }));

try {
  visor = new Espacio3D($('visor'), DATOS.espacios[0]);
  $('cargando').remove();
  window.ERC = { visor, espacios: DATOS.espacios, cargar };

  $('hora').addEventListener('input', (ev) => {
    const h = parseFloat(ev.target.value);
    $('reloj').textContent = nombreHora(h);
    visor.setHora(h);
  });
  muestras($('muros'), DATOS.muros, (hex) => visor.setColorMuro(hex));
  muestras($('pisos'), DATOS.pisos, (hex) => visor.setColorPiso(hex));

  $('btnFicha').onclick  = () => $('ficha').classList.toggle('abierta');
  $('cerrarFicha').onclick = () => $('ficha').classList.remove('abierta');
  $('btnPantalla').onclick = () => {
    if (document.fullscreenElement) document.exitFullscreen();
    else document.documentElement.requestFullscreen?.();
  };

  cargar(0);
  despertar();
} catch (err) {
  $('cargando').textContent = 'Tu navegador no pudo abrir el recorrido (necesita WebGL).';
  console.error(err);
}
</script>
</body>
</html>
