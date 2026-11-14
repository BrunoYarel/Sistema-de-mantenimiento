<?php
// backend/nucleo/autoload.php
declare(strict_types=1);

/**
 * Autoload mínimo, sin Composer.
 *
 *   Nucleo\Reloj                  -> backend/nucleo/Reloj.php
 *   Modulos\Incidencias\Servicio  -> backend/modulos/incidencias/Servicio.php
 *
 * Regla: una clase por archivo y el archivo se llama igual que la clase.
 */
spl_autoload_register(static function (string $clase): void {
    $partes = explode('\\', $clase);
    $raiz = dirname(__DIR__);

    if ($partes[0] === 'Nucleo' && count($partes) >= 2) {
        $ruta = $raiz . '/nucleo/' . implode('/', array_slice($partes, 1)) . '.php';
    } elseif ($partes[0] === 'Modulos' && count($partes) >= 3) {
        $ruta = $raiz . '/modulos/' . strtolower($partes[1]) . '/' . implode('/', array_slice($partes, 2)) . '.php';
    } else {
        return;
    }

    if (is_file($ruta)) {
        require $ruta;
    }
});
