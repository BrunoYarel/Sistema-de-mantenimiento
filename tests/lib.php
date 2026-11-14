<?php
// tests/lib.php
declare(strict_types=1);

/**
 * Mini marco de pruebas, sin Composer. Cada tests/*_test.php usa estas funciones:
 *
 *   prueba('nombre', function () { exigirIgual(1, 1); });
 *   pruebaBd('nombre', function () { ... });   // necesita PostgreSQL: se corre con --con-bd
 */
final class Prueba
{
    public static int $ok = 0;
    public static int $fallos = 0;
    public static int $omitidas = 0;
    public static bool $conBd = false;

    public static function resumen(): int
    {
        echo "\nOK: " . self::$ok . "   FALLAS: " . self::$fallos . "   OMITIDAS: " . self::$omitidas . "\n";
        if (self::$omitidas > 0 && !self::$conBd) {
            echo "Hay pruebas omitidas: corre también con --con-bd (necesita la base de datos).\n";
        }
        return self::$fallos > 0 ? 1 : 0;
    }
}

function prueba(string $nombre, callable $fn): void
{
    try {
        $fn();
        echo "  ok     $nombre\n";
        Prueba::$ok++;
    } catch (Throwable $e) {
        echo "  FALLA  $nombre\n         " . $e->getMessage() . "\n";
        Prueba::$fallos++;
    }
}

function pruebaBd(string $nombre, callable $fn): void
{
    if (!Prueba::$conBd) {
        echo "  omitida (requiere --con-bd)  $nombre\n";
        Prueba::$omitidas++;
        return;
    }
    prueba($nombre, $fn);
}

function exigir(bool $condicion, string $mensaje = 'La condición es falsa'): void
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
}

function exigirIgual(mixed $esperado, mixed $real, string $mensaje = ''): void
{
    if ($esperado !== $real) {
        throw new RuntimeException(trim($mensaje . ' esperado=' . json_encode($esperado, JSON_UNESCAPED_UNICODE)
            . ' real=' . json_encode($real, JSON_UNESCAPED_UNICODE)));
    }
}

/** Exige que $fn lance un ErrorApi con ese estado HTTP. */
function exigirError(callable $fn, int $estado): void
{
    try {
        $fn();
    } catch (Nucleo\ErrorApi $e) {
        exigirIgual($estado, $e->estado, 'estado HTTP');
        return;
    }
    throw new RuntimeException("Se esperaba un ErrorApi con estado $estado y no se lanzó nada");
}
