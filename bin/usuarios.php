<?php
// bin/usuarios.php
declare(strict_types=1);

/**
 * Administración mínima desde consola. Sirve para crear el primer usuario (no hay credenciales
 * por defecto en el backend, RNF-01) y para desbloquear un login.
 *
 *   php bin/usuarios.php crear <usuario> <rol> "<Nombre completo>" [sede_id]
 *   php bin/usuarios.php desbloquear <usuario>
 *   php bin/usuarios.php sembrar-dev
 *
 * "crear" genera una clave temporal aleatoria, la muestra UNA vez y obliga a cambiarla al entrar.
 * "sembrar-dev" deja un usuario por rol con una clave conocida para trabajar en local. Se niega a
 * correr si APP_ENV no es "local" (en el servidor real los usuarios se crean con "crear"). Se puede
 * repetir: si el usuario ya existe, le restablece la clave y lo desbloquea. docker/backend/entrypoint.sh
 * lo ejecuta solo al arrancar en local.
 * Roles: docente, admin, soporte_tecnico, soporte_informatica, jefe_soporte, jefe_informatica.
 */
require __DIR__ . '/../backend/nucleo/autoload.php';

use Modulos\Auth\Servicio as AuthServicio;
use Nucleo\Aplicacion;
use Nucleo\Config;
use Nucleo\Conexion;
use Nucleo\Dominio;
use Nucleo\Reloj;

Aplicacion::iniciarCli();

/** SOLO DESARROLLO (sembrar-dev). Clave conocida de los usuarios de prueba: nunca existe fuera de APP_ENV=local. */
const CLAVE_DEV = 'dev-clave-12345';

/** Un usuario por rol: [usuario, rol, nombre]. Los roles son los de Nucleo\Dominio::ROLES. */
const USUARIOS_DEV = [
    ['docente', 'docente', 'Docente de prueba'],
    ['administrativo', 'admin', 'Administrativo de prueba'],
    ['soporte.tecnico', 'soporte_tecnico', 'Soporte técnico de prueba'],
    ['soporte.informatica', 'soporte_informatica', 'Soporte informática de prueba'],
    ['jefe.soporte', 'jefe_soporte', 'Jefe de soporte de prueba'],
    ['jefe.info', 'jefe_informatica', 'Jefe de informática de prueba'],
];

function salir(string $mensaje, int $codigo = 1): void
{
    fwrite(STDERR, $mensaje . "\n");
    exit($codigo);
}

$comando = $argv[1] ?? '';

try {
    $pdo = Conexion::obtener();

    if ($comando === 'crear') {
        $usuario = mb_strtolower(trim($argv[2] ?? ''));
        $rol = $argv[3] ?? '';
        $nombre = trim($argv[4] ?? '');
        $sede = isset($argv[5]) ? (int) $argv[5] : null;

        if ($usuario === '' || $nombre === '' || !in_array($rol, Dominio::ROLES, true)) {
            salir("Uso: php bin/usuarios.php crear <usuario> <rol> \"<Nombre>\" [sede_id]\nRoles: " . implode(', ', Dominio::ROLES), 2);
        }

        $temporal = AuthServicio::claveTemporal();
        $st = $pdo->prepare(
            'INSERT INTO usuario (nombre, usuario, clave_hash, rol, sede_id, debe_cambiar_clave, creado_en)
             VALUES (?, ?, ?, ?, ?, TRUE, ?) RETURNING id'
        );
        $st->execute([$nombre, $usuario, AuthServicio::hashear($temporal), $rol, $sede, Reloj::paraBd()]);

        echo "Usuario creado (id " . $st->fetchColumn() . ").\n";
        echo "Usuario: $usuario\nClave temporal: $temporal\n(se muestra solo ahora; deberá cambiarla al entrar)\n";
    } elseif ($comando === 'desbloquear') {
        $usuario = mb_strtolower(trim($argv[2] ?? ''));
        if ($usuario === '') {
            salir('Uso: php bin/usuarios.php desbloquear <usuario>', 2);
        }
        echo 'Bloqueos liberados: ' . (new AuthServicio())->desbloquear($usuario) . "\n";
    } elseif ($comando === 'sembrar-dev') {
        if (!Config::esLocal()) {
            salir('sembrar-dev solo corre con APP_ENV=local. En el servidor real crea los usuarios con "crear".', 3);
        }

        $sedeId = $pdo->query('SELECT id FROM sede ORDER BY id LIMIT 1')->fetchColumn();
        $sedeId = $sedeId === false ? null : (int) $sedeId;
        $hash = AuthServicio::hashear(CLAVE_DEV);

        $guardar = $pdo->prepare(
            'INSERT INTO usuario (nombre, usuario, clave_hash, rol, sede_id, activo, debe_cambiar_clave, creado_en)
             VALUES (?, ?, ?, ?, ?, TRUE, FALSE, ?)
             ON CONFLICT (usuario) DO UPDATE SET
                 nombre = EXCLUDED.nombre, clave_hash = EXCLUDED.clave_hash, rol = EXCLUDED.rol,
                 sede_id = EXCLUDED.sede_id, activo = TRUE, debe_cambiar_clave = FALSE'
        );
        $liberar = $pdo->prepare('UPDATE bloqueo_login SET fallos = 0, bloqueado_hasta = NULL WHERE usuario = ?');

        echo "Usuarios de desarrollo (clave de todos: " . CLAVE_DEV . ")\n";
        foreach (USUARIOS_DEV as [$usuario, $rol, $nombre]) {
            $guardar->execute([$nombre, $usuario, $hash, $rol, $sedeId, Reloj::paraBd()]);
            $liberar->execute([$usuario]);
            echo '  ' . str_pad($usuario, 22) . $rol . "\n";
        }
    } else {
        salir("Comandos: crear, desbloquear, sembrar-dev\nVer el encabezado de bin/usuarios.php", 2);
    }
} catch (PDOException $e) {
    if (Nucleo\Errores::sqlstate($e) === '23505') {
        salir('Ya existe un usuario con ese nombre de usuario.');
    }
    salir('ERROR: ' . $e->getMessage());
}