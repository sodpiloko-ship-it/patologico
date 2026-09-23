<?php
declare(strict_types=1);

// Regreso desde Mercado Pago (back_urls). El acceso SOLO se da si el pago se verifica contra la API:
// los parámetros de la URL (status, external_reference) no bastan para descargar.
require dirname(__DIR__) . '/pago/lib.php';

$pagoId = (string)($_GET['payment_id'] ?? $_GET['collection_id'] ?? '');
$folioUrl = (string)($_GET['external_reference'] ?? '');
$estado = 'desconocido';
$compra = null;
$verificado = false;

if (preg_match(PTLG_PAGO_RE, $pagoId) === 1) {
  $r = ptlg_procesar_pago($pagoId);
  if (isset($r['compra'])) {
    $compra = $r['compra'];
    $estado = (string)$compra['estado'];
    $verificado = true;
  } elseif (($r['error'] ?? '') === 'api') {
    $estado = 'verificando';
  }
} elseif (preg_match(PTLG_FOLIO_RE, $folioUrl) === 1) {
  $compra = ptlg_leer_compra($folioUrl);
  $estado = $compra ? (string)$compra['estado'] : 'desconocido';
}

$folio = $compra ? (string)$compra['folio'] : '';
$dudas = '/kit-ventas-ia/dudas/';
$html = '';

if ($estado === 'pagado' && $verificado) {
  $link = ptlg_link_descarga($folio);
  $correo = (string)($compra['correo'] ?? '');
  $html = '<h1>¡Listo! Tu playbook está aquí.</h1>'
    . '<p>Gracias por tu compra. Descárgalo ahora; el enlace funciona ' . PTLG_DESCARGA_DIAS . ' días'
    . ($correo !== '' ? ' y también te lo mandamos a <b>' . ptlg_h($correo) . '</b>' : '') . '.</p>'
    . '<a class="btn" href="' . ptlg_h($link) . '">Descargar el playbook</a>'
    . '<div class="box"><b>Empieza en 2 minutos</b><ol>'
    . '<li>Descomprime el archivo y abre ChatGPT, Claude o Gemini.</li>'
    . '<li>Adjunta <b>Oferta-Trafico-WhatsApp.pdf</b> a una conversación nueva.</li>'
    . '<li>Pega este mensaje y envíalo:</li></ol>'
    . '<div class="prompt">' . ptlg_h(ptlg_prompt_arranque()) . '</div></div>'
    . '<p>¿Dudas? Tienes ' . PTLG_DIAS_DUDAS . ' días de dudas por WhatsApp: <a href="' . $dudas . '">escríbenos aquí</a>.</p>'
    . '<p class="muted">Folio de compra: ' . ptlg_h($folio) . '</p>'
    . '<script async src="https://www.googletagmanager.com/gtag/js?id=G-XESKPMDFSR"></script>'
    . '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config","G-XESKPMDFSR");'
    . 'try{var k="ptlg_compra_' . ptlg_h($folio) . '";if(!localStorage.getItem(k)){gtag("event","purchase",{transaction_id:"' . ptlg_h($folio) . '",value:' . PTLG_PRECIO . ',currency:"' . PTLG_MONEDA . '",items:[{item_id:"' . PTLG_PRODUCTO . '",item_name:"Oferta, Tráfico y WhatsApp",price:' . PTLG_PRECIO . '}]});try{!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version=\'2.0\';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,\'script\',\'https://connect.facebook.net/en_US/fbevents.js\');fbq(\'init\',\'1476266257879276\');fbq(\'track\',\'Purchase\',{value:' . PTLG_PRECIO . ',currency:\'' . PTLG_MONEDA . '\',content_ids:[\'' . PTLG_PRODUCTO . '\'],content_type:\'product\'},{eventID:\'' . ptlg_h($folio) . '\'});}catch(e){}localStorage.setItem(k,"1");}}catch(e){}</script>';
} elseif ($estado === 'pagado') {
  $html = '<h1>Tu compra está confirmada.</h1><p>Te mandamos el enlace de descarga a tu correo. Si no lo encuentras (revisa también spam), '
    . '<a href="' . $dudas . '">escríbenos</a> con tu folio ' . ptlg_h($folio) . '.</p>';
} elseif ($estado === 'pendiente') {
  $html = '<h1>Tu pago está en proceso.</h1><p>Si pagaste en OXXO o por transferencia, Mercado Pago tarda en acreditarlo. '
    . 'En cuanto se confirme te mandamos el enlace de descarga a tu correo.</p>'
    . '<p>¿Dudas? <a href="' . $dudas . '">Escríbenos</a>' . ($folio !== '' ? ' con tu folio ' . ptlg_h($folio) : '') . '.</p>';
} elseif ($estado === 'verificando') {
  $html = '<h1>Estamos confirmando tu pago.</h1><p>Recarga esta página en un par de minutos. Si ya pasó un rato, revisa tu correo '
    . 'o <a href="' . $dudas . '">escríbenos</a>.</p>';
} elseif ($estado === 'reembolsado') {
  $html = '<h1>Este pago fue reembolsado.</h1><p>Si crees que es un error, <a href="' . $dudas . '">escríbenos</a>.</p>';
} elseif ($estado === 'rechazado' || $estado === 'creado') {
  $html = '<h1>El pago no se completó.</h1><p>No se hizo ningún cargo por esta compra. Puedes intentarlo otra vez con otra tarjeta, '
    . 'en OXXO o por transferencia.</p><a class="btn" href="/kit-ventas-ia/#comprar">Intentar de nuevo</a>'
    . '<p>¿Algo falló? <a href="' . $dudas . '">Escríbenos</a>.</p>';
} else {
  $html = '<h1>No encontramos esta compra.</h1><p>Si ya pagaste, revisa tu correo: ahí está tu enlace de descarga. '
    . 'Si no lo encuentras, <a href="' . $dudas . '">escríbenos</a>.</p>';
}

ptlg_pagina('Tu compra', $html);
