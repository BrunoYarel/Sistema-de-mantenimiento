<?php
// tests/invariantes_test.php
declare(strict_types=1);

use Nucleo\Conexion;
use Nucleo\Errores;
use Nucleo\Reloj;

/**
 * Invariantes que la base hace cumplir. Todo dentro de una transacción que SIEMPRE se deshace.
 * TODO: invariantes de indicadores (columnas actuales = estado reconstruido desde eventos).
 */
pruebaBd('estado asignada sin técnico es rechazado por la base (RN-02, 23514)', function () {
    $pdo = Conexion::obtener();
    $pdo->beginTransaction();
    try {
        $sufijo = strtoupper(bin2hex(random_bytes(4))); // el código de equipo debe ir en mayúsculas (CHECK equipo_codigo_check)
        $fecha = Reloj::paraBd();

        $sede = $pdo->prepare('INSERT INTO sede (nombre) VALUES (?) RETURNING id');
        $sede->execute(["__prueba_$sufijo"]);
        $sedeId = (int) $sede->fetchColumn();

        $ubi = $pdo->prepare('INSERT INTO ubicacion (sede_id, nombre) VALUES (?, ?) RETURNING id');
        $ubi->execute([$sedeId, 'aula']);
        $ubiId = (int) $ubi->fetchColumn();

        $usu = $pdo->prepare("INSERT INTO usuario (nombre, usuario, rol, creado_en) VALUES ('Prueba', ?, 'docente', ?) RETURNING id");
        $usu->execute(["prueba_" . strtolower($sufijo), $fecha]);
        $usuId = (int) $usu->fetchColumn();

        $eq = $pdo->prepare(
            "INSERT INTO equipo (codigo, tipo, sede_id, ubicacion_id, criticidad, creado_en)
             VALUES (?, 'computadora', ?, ?, 'baja', ?) RETURNING id"
        );
        $eq->execute(["PRUEBA-$sufijo", $sedeId, $ubiId, $fecha]);
        $eqId = (int) $eq->fetchColumn();

        $codigo = null;
        try {
            $inc = $pdo->prepare(
                "INSERT INTO incidencia (equipo_id, sede_id, reportante_id, registrador_id, canal, descripcion,
                                         prioridad, estado, fecha_registro, fecha_ultimo_cambio)
                 VALUES (?, ?, ?, ?, 'sistema', 'prueba', 'baja', 'asignada', ?, ?)"
            );
            $inc->execute([$eqId, $sedeId, $usuId, $usuId, $fecha, $fecha]);
        } catch (PDOException $e) {
            $codigo = Errores::sqlstate($e);
        }
        exigirIgual('23514', $codigo, 'la restricción incidencia_tecnico_requerido debía rechazar la fila');
    } finally {
        $pdo->rollBack();
    }
});
