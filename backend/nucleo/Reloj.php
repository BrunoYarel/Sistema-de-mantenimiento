<?php
// backend/nucleo/Reloj.php
declare(strict_types=1);

namespace Nucleo;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * ÚNICO lugar donde nace una fecha (RN-10, B4). Hora de Lima, un solo punto del núcleo.
 *
 * - Prohibido en SQL: las funciones de hora de PostgreSQL y DEFAULT con fecha (lo comprueba bin/verificar.sh).
 * - Prohibido en PHP fuera de esta clase: time(), date(), strtotime(), new DateTime...
 * - Las pruebas y el dataset de oro controlan el tiempo con Reloj::fijar().
 */
final class Reloj
{
    public const ZONA = 'America/Lima';

    private static ?DateTimeImmutable $fijo = null;
    private static ?DateTimeZone $zona = null;

    public static function configurar(): void
    {
        date_default_timezone_set(self::ZONA);
    }

    public static function zona(): DateTimeZone
    {
        return self::$zona ??= new DateTimeZone(self::ZONA);
    }

    public static function ahora(): DateTimeImmutable
    {
        return self::$fijo ?? new DateTimeImmutable('now', self::zona());
    }

    /** Solo pruebas y dataset de oro. Con null vuelve al reloj real. */
    public static function fijar(DateTimeInterface|string|null $momento): void
    {
        self::$fijo = $momento === null ? null : self::desde($momento);
    }

    /** Convierte texto (también lo que devuelve PDO) o un DateTime a DateTimeImmutable en hora de Lima. */
    public static function desde(DateTimeInterface|string $valor): DateTimeImmutable
    {
        $fecha = $valor instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($valor)
            : new DateTimeImmutable($valor, self::zona());
        return $fecha->setTimezone(self::zona());
    }

    /** Valor para enviar a PostgreSQL como parámetro de una columna TIMESTAMPTZ. */
    public static function paraBd(?DateTimeInterface $fecha = null): string
    {
        $f = $fecha === null ? self::ahora() : self::desde($fecha);
        return $f->format('Y-m-d H:i:s.uP');
    }

    /** ISO 8601 con offset -05:00, el formato de fechas del contrato de la API. */
    public static function iso(DateTimeInterface|string|null $valor): ?string
    {
        return $valor === null ? null : self::desde($valor)->format('c');
    }

    /**
     * Límite superior (exclusivo) para los indicadores de un mes (RF-11, RN-10):
     * un mes cerrado fija el inicio del mes siguiente; el mes en curso corta en "ahora".
     * Los eventos del mes cumplen: fecha < límite.
     */
    public static function corteDelMes(int $anio, int $mes): DateTimeImmutable
    {
        $inicio = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $anio, $mes), self::zona());
        $siguiente = $inicio->modify('first day of next month');
        $ahora = self::ahora();
        return $siguiente <= $ahora ? $siguiente : $ahora;
    }
}
