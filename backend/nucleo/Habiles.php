<?php
// backend/nucleo/Habiles.php
declare(strict_types=1);

namespace Nucleo;

use DateTimeImmutable;
use DateTimeInterface;
use PDO;

/**
 * Días hábiles = lunes a sábado, menos feriados (§2, RN-06).
 * Los feriados salen de la tabla `feriado` (una consulta por petición).
 * Las funciones reciben $feriados para poder probarlas sin base de datos.
 *
 * La tabla `feriado` debe cubrir hasta hoy + MARGEN_CALENDARIO_DIAS: pasada esa fecha, los feriados
 * faltantes se contarían como hábiles. feriados() deja un aviso en el log cuando no se cumple y
 * tests/reloj_habiles_test.php falla.
 */
final class Habiles
{
    public const MARGEN_CALENDARIO_DIAS = 60;

    /** @var list<string>|null fechas 'Y-m-d' */
    private static ?array $cache = null;

    /** @return list<string> */
    public static function feriados(): array
    {
        if (self::$cache === null) {
            $filas = Conexion::obtener()->query('SELECT fecha::text FROM feriado')->fetchAll(PDO::FETCH_COLUMN);
            self::$cache = array_map('strval', $filas);
            if (!self::calendarioVigente(self::$cache, Reloj::ahora())) {
                Errores::log('aviso', 'Calendario de feriados sin cobertura de ' . self::MARGEN_CALENDARIO_DIAS
                    . ' días (último feriado cargado: ' . (self::$cache === [] ? 'ninguno' : max(self::$cache))
                    . '). Cargar el año siguiente en database/seed/0001_catalogos.sql.');
            }
        }
        return self::$cache;
    }

    /**
     * ¿El último feriado cargado llega a hoy + MARGEN_CALENDARIO_DIAS?
     *
     * @param list<string> $feriados fechas 'Y-m-d'
     */
    public static function calendarioVigente(array $feriados, DateTimeImmutable $hoy): bool
    {
        if ($feriados === []) {
            return false;
        }
        $limite = $hoy->modify('+' . self::MARGEN_CALENDARIO_DIAS . ' days')->format('Y-m-d');
        return max($feriados) >= $limite;
    }

    /** @param list<string>|null $feriados */
    public static function esHabil(DateTimeInterface $dia, ?array $feriados = null): bool
    {
        if ((int) $dia->format('N') === 7) { // domingo
            return false;
        }
        return !in_array($dia->format('Y-m-d'), $feriados ?? self::feriados(), true);
    }

    /**
     * Mismo instante, $dias días hábiles después (el día de $desde no cuenta).
     * [P] Confirmar con el cliente si "3 días hábiles" vence a esa misma hora o al final del tercer día.
     *
     * @param list<string>|null $feriados
     */
    public static function sumar(DateTimeImmutable $desde, int $dias, ?array $feriados = null): DateTimeImmutable
    {
        $feriados ??= self::feriados();
        $fecha = $desde;
        $contados = 0;
        while ($contados < $dias) {
            $fecha = $fecha->modify('+1 day');
            if (self::esHabil($fecha, $feriados)) {
                $contados++;
            }
        }
        return $fecha;
    }

    /** Desde cuándo el jefe de soporte puede cerrar sin confirmación (RN-06, D9). */
    public static function vencimientoCierre(DateTimeImmutable $desde): DateTimeImmutable
    {
        return self::sumar($desde, 3);
    }
}