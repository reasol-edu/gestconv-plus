<?php
// Router del servidor de capturas con el reloj de la aplicación fijado (ver README.md de esta carpeta).
// Sirve los ficheros estáticos y delega el resto en index-clock.php (copia de public/index.php + Clock::set).
$public = dirname(__DIR__, 2) . '/public';
$path   = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($path !== '/' && is_file($public . $path)) {
    return false;
}

// OJO: no cargar vendor/autoload.php aquí. El runtime de Symfony hace require_once de él y, si ya estaba
// cargado, cree que se usa como librería y no arranca la aplicación (respuesta 200 vacía).
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index-clock.php';
require __DIR__ . '/index-clock.php';
