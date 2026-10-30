<?php
// bin/migrar.php
declare(strict_types=1);

/**
 * Aplica las migraciones pendientes de database/migraciones/NNNN_*.sql, cada una en su transacción
 * (PostgreSQL permite DDL transaccional: o se aplica entera o no se aplica).
 *
 *   php bin/migrar.php                  migraciones pendientes
 *   php bin/migrar.php --grants         ... y después database/grants.sql
 *   php bin/migrar.php --seed           ... y después database/seed/*.sql (re-ejecutables)
 *   php bin/migrar.php --grants --seed
 *
 * Una migración ya aplicada NO se edita (B5): si su contenido cambió, este script se detiene.
 * Se conecta como dueño del esquema (BD_MIGRACION_*), nunca como el usuario de la aplicación.
 */
require __DIR__ . '/../backend/nucleo/autoload.php';

use Nucleo\Aplicacion;
use Nucleo\Conexion;
use Nucleo\Reloj;

Aplicacion::iniciarCli();

$conGrants = in_array('--grants', $argv, true);
$conSeed = in_array('--seed', $argv, true);
$raiz = dirname(__DIR__);

/** Huella estable aunque Git cambie los finales de línea (Windows). */
function huella(string $archivo): string
{
    return hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($archivo)));
}

try {
    $pdo = Conexion::obtener('migracion');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migraciones (
            version TEXT PRIMARY KEY,
            sha256 TEXT NOT NULL,
            aplicada_en TIMESTAMPTZ NOT NULL
        )'
    );
    $aplicadas = $pdo->query('SELECT version, sha256 FROM schema_migraciones')->fetchAll(PDO::FETCH_KEY_PAIR);

    $archivos = glob($raiz . '/database/migraciones/[0-9][0-9][0-9][0-9]_*.sql') ?: [];
    sort($archivos);

    $nuevas = 0;
    foreach ($archivos as $archivo) {
        $version = basename($archivo, '.sql');
        $sha = huella($archivo);

        if (isset($aplicadas[$version])) {
            if ($aplicadas[$version] !== $sha) {
                fwrite(STDERR, "ERROR: $version ya estaba aplicada y su contenido cambió. No se edita: agrega otra migración.\n");
                exit(1);
            }
            continue;
        }

        echo "Aplicando $version ... ";
        $pdo->beginTransaction();
        try {
            $pdo->exec((string) file_get_contents($archivo));
            $registro = $pdo->prepare('INSERT INTO schema_migraciones (version, sha256, aplicada_en) VALUES (?, ?, ?)');
            $registro->execute([$version, $sha, Reloj::paraBd()]);
            $pdo->commit();
            echo "ok\n";
            $nuevas++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fwrite(STDERR, "FALLÓ\n" . $e->getMessage() . "\n");
            exit(1);
        }
    }
    echo $nuevas === 0 ? "Sin migraciones pendientes.\n" : "$nuevas migración(es) aplicada(s).\n";

    if ($conGrants) {
        $pdo->exec((string) file_get_contents($raiz . '/database/grants.sql'));
        echo "grants.sql aplicado.\n";
    }

    if ($conSeed) {
        $semillas = glob($raiz . '/database/seed/*.sql') ?: [];
        sort($semillas);
        foreach ($semillas as $semilla) {
            $pdo->exec((string) file_get_contents($semilla));
            echo 'seed ' . basename($semilla) . " aplicado.\n";
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
