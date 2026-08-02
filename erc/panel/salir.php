<?php
/** NUCLEO PATO — cerrar sesion del panel. */
declare(strict_types=1);
$__pato = is_file(dirname(__DIR__) . '/core/pato.php')
    ? dirname(__DIR__) . '/core/pato.php'    // panel en la raiz del sitio: <sitio>/panel/
    : dirname(__DIR__) . '/pato.php';        // panel dentro del nucleo: <sitio>/core/panel/
require_once $__pato;
pato_salir();
header('Location: entrar.php');
exit;
