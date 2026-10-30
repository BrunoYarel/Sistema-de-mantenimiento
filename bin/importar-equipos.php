<?php
// bin/importar-equipos.php
declare(strict_types=1);

/**
 * Carga inicial de equipos desde un CSV (D11). Sin pantalla de carga.
 *
 *   php bin/importar-equipos.php equipos.csv --dry-run          revisa y reporta, no escribe
 *   php bin/importar-equipos.php equipos.csv                    escribe (se detiene si hay filas rechazadas)
 *   php bin/importar-equipos.php equipos.csv --omitir-rechazadas   escribe las válidas y reporta el resto
 *   opción: --crear-ubicaciones   crea las ubicaciones que no existan en esa sede
 *
 * Columnas obligatorias (cualquier orden; coma o punto y coma): codigo, tipo, sede, ubicacion, criticidad
 * "Fuera de servicio" no se carga: se deriva de las incidencias abiertas.
 *
 * El código se normaliza con Modulos\Equipos\Servicio::normalizarCodigo (mayúsculas, espacios -> guion). [P] Confirmar la regla con el cliente: sin rótulo
 * en cada equipo, los reportes caen en el equipo equivocado y se contamina el historial.
 * TODO: importar docentes con clave temporal aleatoria y debe_cambiar_clave (misma idea, otro script).
 */
require __DIR__ . '/../backend/nucleo/autoload.php';

use Modulos\Equipos\Servicio as EquiposServicio;
use Nucleo\Aplicacion;
use Nucleo\Conexion;
use Nucleo\Dominio;
use Nucleo\Reloj;

Aplicacion::iniciarCli();

$archivo = null;
$simular = false;
$crearUbicaciones = false;
$omitir = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $simular = true;
    } elseif ($arg === '--crear-ubicaciones') {
        $crearUbicaciones = true;
    } elseif ($arg === '--omitir-rechazadas') {
        $omitir = true;
    } elseif ($archivo === null && !str_starts_with($arg, '--')) {
        $archivo = $arg;
    } else {
        fwrite(STDERR, "Argumento desconocido: $arg\n");
        exit(2);
    }
}
if ($archivo === null || !is_file($archivo)) {
    fwrite(STDERR, "Uso: php bin/importar-equipos.php <archivo.csv> [--dry-run] [--crear-ubicaciones] [--omitir-rechazadas]\n");
    exit(2);
}

$pdo = Conexion::obtener();

// ---------------------------------------------------------------- catálogos de la base
$sedes = [];
foreach ($pdo->query('SELECT id, nombre FROM sede') as $f) {
    $sedes[mb_strtolower($f['nombre'])] = (int) $f['id'];
}
$ubicaciones = [];
foreach ($pdo->query('SELECT id, sede_id, nombre FROM ubicacion') as $f) {
    $ubicaciones[$f['sede_id'] . '|' . mb_strtolower($f['nombre'])] = (int) $f['id'];
}
$codigosExistentes = array_flip($pdo->query('SELECT codigo FROM equipo')->fetchAll(PDO::FETCH_COLUMN));

// ---------------------------------------------------------------- leer el CSV
$h = fopen($archivo, 'rb');
$primera = (string) fgets($h);
rewind($h);
$sep = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ','; // Excel en español exporta con ;
$cabecera = fgetcsv($h, 0, $sep, '"', '');
if (!$cabecera) {
    fwrite(STDERR, "El archivo está vacío.\n");
    exit(1);
}
$cabecera[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cabecera[0]); // BOM de Excel
$cabecera = array_map(static fn ($c) => mb_strtolower(trim((string) $c)), $cabecera);
$faltan = array_diff(['codigo', 'tipo', 'sede', 'ubicacion', 'criticidad'], $cabecera);
if ($faltan !== []) {
    fwrite(STDERR, 'Faltan columnas: ' . implode(', ', $faltan) . "\n");
    exit(1);
}

$validas = [];
$rechazadas = [];
$vistos = [];
$linea = 1;
while (($fila = fgetcsv($h, 0, $sep, '"', '')) !== false) {
    $linea++;
    if ($fila === [null] || count(array_filter($fila, static fn ($c) => trim((string) $c) !== '')) === 0) {
        continue; // fila vacía
    }
    $celdas = array_slice(array_pad(array_map('trim', $fila), count($cabecera), ''), 0, count($cabecera));
    $d = array_combine($cabecera, $celdas);
    $errores = [];

    $codigo = EquiposServicio::normalizarCodigo((string) $d['codigo']);
    if ($codigo === '') {
        $errores[] = 'código vacío';
    } elseif (isset($vistos[$codigo])) {
        $errores[] = "código repetido en el archivo (línea {$vistos[$codigo]})";
    } elseif (isset($codigosExistentes[$codigo])) {
        $errores[] = 'el código ya existe en la base';
    }
    $vistos[$codigo] ??= $linea;

    $tipo = mb_strtolower((string) $d['tipo']);
    if (!in_array($tipo, Dominio::TIPOS_EQUIPO, true)) {
        $errores[] = 'tipo no válido (' . implode(', ', Dominio::TIPOS_EQUIPO) . ')';
    }
    $criticidad = mb_strtolower((string) $d['criticidad']);
    if (!in_array($criticidad, Dominio::CRITICIDADES, true)) {
        $errores[] = 'criticidad no válida (' . implode(', ', Dominio::CRITICIDADES) . ')';
    }

    $sedeId = $sedes[mb_strtolower((string) $d['sede'])] ?? null;
    if ($sedeId === null) {
        $errores[] = 'la sede no existe';
    }
    $clave = $sedeId . '|' . mb_strtolower((string) $d['ubicacion']);
    if ($sedeId !== null && (string) $d['ubicacion'] === '') {
        $errores[] = 'ubicación vacía';
    } elseif ($sedeId !== null && !isset($ubicaciones[$clave]) && !$crearUbicaciones) {
        $errores[] = 'la ubicación no existe en esa sede (usa --crear-ubicaciones)';
    }

    if ($errores !== []) {
        $rechazadas[] = ['linea' => $linea, 'codigo' => $codigo, 'motivo' => implode('; ', $errores)];
        continue;
    }
    $validas[] = [
        'codigo' => $codigo,
        'tipo' => $tipo,
        'sede_id' => $sedeId,
        'ubicacion' => (string) $d['ubicacion'],
        'clave_ubicacion' => $clave,
        'criticidad' => $criticidad,
    ];
}
fclose($h);

// ---------------------------------------------------------------- informe
echo "Filas válidas: " . count($validas) . "\nFilas rechazadas: " . count($rechazadas) . "\n";
foreach ($rechazadas as $r) {
    echo "  línea {$r['linea']} ({$r['codigo']}): {$r['motivo']}\n";
}
if ($rechazadas !== []) {
    $dir = dirname(__DIR__) . '/backend/storage';
    $salida = $dir . '/importacion-rechazadas-' . Reloj::ahora()->format('Ymd-His') . '.csv';
    $o = fopen($salida, 'wb');
    fputcsv($o, ['linea', 'codigo', 'motivo'], ';', '"', '');
    foreach ($rechazadas as $r) {
        fputcsv($o, $r, ';', '"', '');
    }
    fclose($o);
    echo "Informe de rechazadas: $salida\n";
}

if ($simular) {
    echo "--dry-run: no se escribió nada.\n";
    exit($rechazadas === [] ? 0 : 1);
}
if ($rechazadas !== [] && !$omitir) {
    echo "No se escribió nada: corrige el archivo o usa --omitir-rechazadas.\n";
    exit(1);
}

// ---------------------------------------------------------------- escritura (todo o nada)
Conexion::transaccion(static function (PDO $pdo) use ($validas, &$ubicaciones): void {
    $nuevaUbicacion = $pdo->prepare('INSERT INTO ubicacion (sede_id, nombre) VALUES (?, ?) RETURNING id');
    $nuevoEquipo = $pdo->prepare(
        'INSERT INTO equipo (codigo, tipo, sede_id, ubicacion_id, criticidad, creado_en)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($validas as $e) {
        if (!isset($ubicaciones[$e['clave_ubicacion']])) {
            $nuevaUbicacion->execute([$e['sede_id'], $e['ubicacion']]);
            $ubicaciones[$e['clave_ubicacion']] = (int) $nuevaUbicacion->fetchColumn();
        }
        $nuevoEquipo->execute([
            $e['codigo'],
            $e['tipo'],
            $e['sede_id'],
            $ubicaciones[$e['clave_ubicacion']],
            $e['criticidad'],
            Reloj::paraBd(),
        ]);
    }
});
echo count($validas) . " equipo(s) importado(s).\n";