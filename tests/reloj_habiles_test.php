<?php
// tests/reloj_habiles_test.php
declare(strict_types=1);

use Nucleo\Conexion;
use Nucleo\Habiles;
use Nucleo\Reloj;

// ---- Días hábiles: tabla de casos (§2, RN-06). Hábiles = lunes a sábado, sin feriados.
$feriados = ['2026-12-08'];

prueba('sábado es hábil', function () use ($feriados) {
    exigir(Habiles::esHabil(Reloj::desde('2026-10-31 10:00'), $feriados));
});
prueba('domingo no es hábil', function () use ($feriados) {
    exigir(!Habiles::esHabil(Reloj::desde('2026-11-01 10:00'), $feriados));
});
prueba('feriado no es hábil', function () use ($feriados) {
    exigir(!Habiles::esHabil(Reloj::desde('2026-12-08 10:00'), $feriados));
});
prueba('3 hábiles desde un viernes cuentan el sábado y saltan el domingo', function () use ($feriados) {
    exigirIgual('2026-11-03', Habiles::sumar(Reloj::desde('2026-10-30 09:00'), 3, $feriados)->format('Y-m-d'));
});
prueba('3 hábiles saltan un feriado entre semana', function () use ($feriados) {
    exigirIgual('2026-12-09', Habiles::sumar(Reloj::desde('2026-12-04 09:00'), 3, $feriados)->format('Y-m-d'));
});

// ---- Cobertura del calendario de feriados: la tabla debe llegar a hoy + 60 días
$calendario = ['2026-12-25', '2027-12-25'];

prueba('calendario vigente: el último feriado llega a hoy + 60 días', function () use ($calendario) {
    exigir(Habiles::calendarioVigente($calendario, Reloj::desde('2026-10-30 09:00')));
    exigir(Habiles::calendarioVigente($calendario, Reloj::desde('2027-10-26 09:00')), 'el borde (hoy + 60 = último feriado) es válido');
});
prueba('calendario vencido: el último feriado queda a menos de 60 días', function () use ($calendario) {
    exigir(!Habiles::calendarioVigente($calendario, Reloj::desde('2027-10-27 09:00')));
    exigir(!Habiles::calendarioVigente($calendario, Reloj::desde('2028-01-02 09:00')));
});
prueba('calendario vacío no es vigente', function () {
    exigir(!Habiles::calendarioVigente([], Reloj::desde('2026-10-30 09:00')));
});

pruebaBd('la tabla feriado cubre hoy + 60 días', function () {
    $feriados = Habiles::feriados();
    exigir(
        Habiles::calendarioVigente($feriados, Reloj::ahora()),
        'Último feriado cargado: ' . ($feriados === [] ? 'ninguno' : max($feriados))
        . '. Cargar el año siguiente en database/seed/0001_catalogos.sql y correr php bin/migrar.php --seed.'
    );
});
pruebaBd('3 hábiles desde un martes de Semana Santa 2027 saltan jueves y viernes santos y el domingo', function () {
    exigirIgual('2027-03-29', Habiles::sumar(Reloj::desde('2027-03-23 09:00'), 3)->format('Y-m-d'));
});

// ---- Reloj: último segundo del mes (RN-10)
prueba('23:59:59 del último día del mes sigue siendo ese mes en Lima', function () {
    Reloj::fijar('2026-10-31 23:59:59');
    try {
        exigirIgual('2026-10', Reloj::ahora()->format('Y-m'));
        exigir(str_ends_with(Reloj::paraBd(), '-05:00'), 'paraBd debe llevar el offset de Lima');
    } finally {
        Reloj::fijar(null);
    }
});
prueba('corte: mes cerrado = inicio del mes siguiente; mes en curso = ahora', function () {
    Reloj::fijar('2026-10-31 23:59:59');
    try {
        exigirIgual('2026-10-01 00:00:00', Reloj::corteDelMes(2026, 9)->format('Y-m-d H:i:s'));
        exigirIgual('2026-10-31 23:59:59', Reloj::corteDelMes(2026, 10)->format('Y-m-d H:i:s'));
    } finally {
        Reloj::fijar(null);
    }
});

pruebaBd('PostgreSQL y PHP coinciden en el mes a las 23:59:59 (04:59:59 UTC ya es el mes siguiente)', function () {
    Reloj::fijar('2026-10-31 23:59:59');
    try {
        $st = Conexion::obtener()->prepare("SELECT date_trunc('month', ?::timestamptz)::date::text");
        $st->execute([Reloj::paraBd()]);
        exigirIgual('2026-10-01', $st->fetchColumn());
    } finally {
        Reloj::fijar(null);
    }
});