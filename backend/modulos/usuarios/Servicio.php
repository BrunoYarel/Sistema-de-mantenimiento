<?php
// backend/modulos/usuarios/Servicio.php
declare(strict_types=1);

namespace Modulos\Usuarios;

use Modulos\Auth\Servicio as AuthServicio;
use Modulos\Catalogos\Servicio as CatalogosServicio;
use Modulos\Incidencias\Servicio as IncidenciasServicio;
use Nucleo\Auditoria;
use Nucleo\Cambios;
use Nucleo\Conexion;
use Nucleo\Errores;
use Nucleo\Idempotencia;
use Nucleo\Paginacion;
use Nucleo\Politica;
use Nucleo\Reloj;
use Nucleo\Texto;
use PDOException;

/**
 * Reglas del módulo (B1). Los demás módulos NO leen la tabla `usuario`: piden técnicos y reportantes
 * a estas funciones (B2).
 *
 * Quién atiende y quién reporta sale de la matriz de permisos (Politica::rolesCon), no de una lista aparte:
 * si la matriz cambia, estas listas cambian solas. Lo mismo vale para "quién gestiona usuarios".
 *
 * Gestión de usuarios (RF-12): todo lo que cambia algo deja su registro en auditoría, dentro de la misma
 * transacción. Las claves se piden a auth (AuthServicio): aquí nunca se guarda ni se registra una clave en claro.
 */
final class Servicio
{
    /** Tope de la búsqueda de reportantes: es un autocompletado, no una lista de gestión. */
    public const MAX_REPORTANTES = 20;
    /** Tope de técnicos: son pocos; el límite solo evita una respuesta sin fin. */
    private const MAX_TECNICOS = 200;

    private const USUARIO_REPETIDO = 'Ya existe un usuario con ese nombre de usuario.';

    /**
     * Restricciones de la base que este módulo sabe explicar (nombre => [campo, mensaje]). Normalmente no llegan:
     * las comprobaciones previas las atajan. Quedan por si dos peticiones se cruzan. El resto lo traduce Errores::desdePdo.
     */
    private const RESTRICCIONES = [
        'usuario_usuario_key' => ['usuario', self::USUARIO_REPETIDO],
        'usuario_sede_id_fkey' => ['sede_id', 'La sede no existe.'],
    ];

    private Repositorio $repo;

    public function __construct()
    {
        $this->repo = new Repositorio();
    }

    /**
     * Nombre de usuario: sin espacios en los bordes y en minúscula (como lo escribe quien inicia sesión). ÚNICA función
     * que lo normaliza: la usan el alta y la comparación del PATCH.
     */
    public static function normalizarUsuario(string $usuario): string
    {
        return mb_strtolower(trim($usuario));
    }

    /** Nombre completo: un solo espacio entre palabras ("  Ana   Pérez " -> "Ana Pérez"). Mayúsculas y tildes se respetan. */
    public static function normalizarNombre(string $nombre): string
    {
        return Texto::espacios($nombre);
    }

    // ------------------------------------------------------------------ lecturas para otros módulos

    /**
     * Técnicos activos a quienes se puede asignar una incidencia (los roles que atienden).
     *
     * @return list<array{id:int,nombre:string,rol:string}>
     */
    public function tecnicos(): array
    {
        $filas = $this->repo->activosConRol(Politica::rolesCon('incidencia.atender'), null, self::MAX_TECNICOS);
        return array_map([self::class, 'aApi'], $filas);
    }

    /**
     * Personas activas a cuyo nombre se puede registrar una incidencia (los roles que reportan por sí mismos),
     * filtradas por nombre o nombre de usuario. Máximo MAX_REPORTANTES.
     *
     * @return list<array{id:int,nombre:string,rol:string}>
     */
    public function reportantes(string $q): array
    {
        $filas = $this->repo->activosConRol(Politica::rolesCon('incidencia.registrar_propia'), Texto::patronBusqueda($q), self::MAX_REPORTANTES);
        return array_map([self::class, 'aApi'], $filas);
    }

    /**
     * Nombre de cada persona (activa o no), para mostrar quién hizo algo (p. ej. el técnico en el historial de un equipo).
     * Solo el nombre: nada más de la tabla `usuario` sale de este módulo.
     *
     * @param list<int> $ids
     * @return array<int,string> id => nombre
     */
    public function nombres(array $ids): array
    {
        return $this->repo->nombresPorId(array_values(array_unique($ids)));
    }

    // ------------------------------------------------------------------ gestión (usuario.gestionar, lo exige la ruta)

    /**
     * Lista de gestión: activos e inactivos, paginada (Paginacion), por nombre. No incluye los
     * usuarios de sistema (sin credenciales, p. ej. carga_inicial).
     *
     * @param array{q:?string,rol:?string,sede:?int,activo:?bool} $filtros q es el texto tal cual lo escribió la persona
     * @return array{items:list<array<string,mixed>>,total:int,pagina:int,por_pagina:int}
     */
    public function usuarios(array $filtros, int $pagina): array
    {
        if ($filtros['q'] !== null) {
            $filtros['q'] = Texto::patronBusqueda($filtros['q']);
        }
        $filas = $this->repo->buscar($filtros, Paginacion::POR_PAGINA, Paginacion::desplazamiento($pagina));

        return Paginacion::respuesta($this->aApiLista($filas), $this->repo->contar($filtros), $pagina);
    }

    /**
     * Un usuario para la pantalla de edición, en la misma forma que la lista. 404 si no existe o es de sistema.
     *
     * @return array<string,mixed>
     */
    public function usuario(int $id): array
    {
        return $this->usuarioPorId($id);
    }

    /**
     * Alta de un usuario (RF-12). El nombre de usuario se normaliza y es único. Nace activo, con una clave temporal
     * aleatoria que se devuelve UNA sola vez y obliga a cambiarla al entrar. Queda en auditoría.
     *
     * Reintento con la misma clave de idempotencia (falla de red): devuelve el mismo usuario con `clave_temporal` VACÍA,
     * porque la clave no se guarda en claro; quien reintenta usa restablecerClave.
     *
     * @param array{id:int} $actor
     * @return array<string,mixed> el usuario más `clave_temporal`
     */
    public function crear(array $actor, string $nombre, string $usuario, string $rol, ?int $sedeId, string $claveIdempotencia): array
    {
        $nombre = Texto::exigir('nombre', $nombre);
        $usuario = self::usuarioValido($usuario);
        $temporal = ''; // solo se llena si ESTA petición crea al usuario; un reintento la deja vacía

        try {
            return Idempotencia::ejecutar(
                $actor['id'],
                $claveIdempotencia,
                'usuario.crear',
                function () use ($actor, $nombre, $usuario, $rol, $sedeId, &$temporal): int {
                    $this->exigirSedeSiHay($sedeId);
                    if ($this->repo->existeUsuario($usuario)) {
                        throw Errores::validacion(['usuario' => self::USUARIO_REPETIDO]);
                    }

                    $clave = AuthServicio::claveTemporal();
                    $id = $this->repo->insertar($nombre, $usuario, AuthServicio::hashear($clave), $rol, $sedeId, Reloj::paraBd());
                    Auditoria::registrar($actor['id'], 'usuario', $id, 'crear', null, [
                        'usuario' => $usuario,
                        'rol' => $rol,
                        'sede_id' => $sedeId,
                    ]);
                    $temporal = $clave;
                    return $id;
                },
                // Función normal con `use (&$temporal)`: una flecha (fn) copiaría el valor vacío de ahora.
                function (int $id) use (&$temporal): array {
                    return $this->usuarioPorId($id) + ['clave_temporal' => $temporal];
                }
            );
        } catch (PDOException $e) {
            throw Errores::deRestriccion($e, self::RESTRICCIONES) ?? $e;
        }
    }

    /**
     * Edita nombre, rol y sede por defecto (RF-12). El nombre de usuario no se edita. Cada cambio real deja su registro
     * en auditoría; lo que llega igual que está no escribe nada. Un usuario desactivado también se puede editar.
     *
     * Cambiar el rol se rechaza (409) si:
     *   - es el último gestor de usuarios activo y el rol nuevo ya no gestiona (nadie podría volver a gestionar);
     *   - es un técnico y el rol nuevo ya no atiende, mientras tenga incidencias abiertas (RN-02).
     *
     * @param array{id:int} $actor
     * @param array{nombre?:string,rol?:string,sede_id?:?int} $cambios solo lo que llegó; sede_id null = sin sede por defecto
     * @param ?string $usuarioEnviado si el cliente lo manda, debe ser el actual
     * @return array<string,mixed>
     */
    public function editar(array $actor, int $id, array $cambios, ?string $usuarioEnviado): array
    {
        try {
            return Conexion::transaccion(function () use ($actor, $id, $cambios, $usuarioEnviado): array {
                $gestores = $this->bloquearGestores(); // primero los gestores, después el usuario: sin trabas entre jefes
                $actual = $this->usuarioBloqueado($id);

                if ($usuarioEnviado !== null && self::normalizarUsuario($usuarioEnviado) !== (string) $actual['usuario']) {
                    throw Errores::validacion(['usuario' => 'El nombre de usuario no se puede cambiar.']);
                }

                $c = new Cambios();
                if (isset($cambios['nombre'])) {
                    $c->campo('nombre', (string) $actual['nombre'], Texto::exigir('nombre', $cambios['nombre']), 'cambiar_nombre');
                }
                if (isset($cambios['rol']) && $cambios['rol'] !== (string) $actual['rol']) {
                    $this->exigirRolCambiable($actual, $cambios['rol'], $gestores);
                    $c->campo('rol', (string) $actual['rol'], $cambios['rol'], 'cambiar_rol');
                }
                if (array_key_exists('sede_id', $cambios)) {
                    $sedeActual = $actual['sede_id'] === null ? null : (int) $actual['sede_id'];
                    if ($cambios['sede_id'] !== $sedeActual) {
                        $this->exigirSedeSiHay($cambios['sede_id']);
                        $c->campo('sede_id', $sedeActual, $cambios['sede_id'], 'cambiar_sede');
                    }
                }

                if (!$c->hay()) {
                    return $this->aApiLista([$actual])[0];
                }
                if ($this->repo->actualizar($id, $c->nuevos()) !== 1) {
                    throw Errores::modificadoPorOtro('el usuario');
                }
                $c->auditar($actor['id'], 'usuario', $id);

                return $this->usuarioPorId($id);
            });
        } catch (PDOException $e) {
            throw Errores::deRestriccion($e, self::RESTRICCIONES) ?? $e;
        }
    }

    /**
     * Desactiva (RF-12, RN-07: no se borra). La sesión de esa persona deja de valer en su próxima petición.
     *   403 si es uno mismo; 409 si ya estaba desactivado, si es el último gestor activo o si es un técnico con
     *   incidencias abiertas (RN-02).
     *
     * @param array{id:int} $actor
     * @return array<string,mixed>
     */
    public function desactivar(array $actor, int $id): array
    {
        return Conexion::transaccion(function () use ($actor, $id): array {
            $gestores = $this->bloquearGestores();
            $actual = $this->usuarioBloqueado($id);

            if ($id === $actor['id']) {
                throw Errores::prohibido('no_puedes_desactivarte', 'No puedes desactivar tu propia cuenta.');
            }
            if (!Conexion::aBool($actual['activo'])) {
                throw Errores::conflicto('El usuario ya estaba desactivado.', $this->aApiLista([$actual])[0], 'transicion_invalida');
            }
            if (self::esGestor((string) $actual['rol'])) {
                self::exigirOtroGestor($gestores, $id);
            }
            if (self::esTecnico((string) $actual['rol'])) {
                $this->exigirSinIncidenciasAbiertas($id);
            }

            if ($this->repo->cambiarActivo($id, false) !== 1) {
                throw Errores::modificadoPorOtro('el usuario');
            }
            Auditoria::registrar($actor['id'], 'usuario', $id, 'desactivar');

            return $this->usuarioPorId($id);
        });
    }

    /**
     * Reactiva a un usuario desactivado. 409 si ya estaba activo.
     *
     * @param array{id:int} $actor
     * @return array<string,mixed>
     */
    public function reactivar(array $actor, int $id): array
    {
        return Conexion::transaccion(function () use ($actor, $id): array {
            $actual = $this->usuarioBloqueado($id);
            if (Conexion::aBool($actual['activo'])) {
                throw Errores::conflicto('El usuario ya estaba activo.', $this->aApiLista([$actual])[0], 'transicion_invalida');
            }

            if ($this->repo->cambiarActivo($id, true) !== 1) {
                throw Errores::modificadoPorOtro('el usuario');
            }
            Auditoria::registrar($actor['id'], 'usuario', $id, 'reactivar');

            return $this->usuarioPorId($id);
        });
    }

    /**
     * Deja una clave temporal nueva (la anterior deja de valer) y obliga a cambiarla al entrar. La clave se devuelve
     * UNA vez, en `clave_temporal`; la auditoría registra el hecho, nunca la clave. No es un alta: no lleva
     * Idempotency-Key, y repetir la petición genera otra clave (vale la última que se recibió).
     *
     * @param array{id:int} $actor
     * @return array<string,mixed> el usuario más `clave_temporal`
     */
    public function restablecerClave(array $actor, int $id): array
    {
        return Conexion::transaccion(function () use ($actor, $id): array {
            $this->usuarioBloqueado($id);

            $temporal = (new AuthServicio())->restablecerClave($id);
            Auditoria::registrar($actor['id'], 'usuario', $id, 'restablecer_clave');

            return $this->usuarioPorId($id) + ['clave_temporal' => $temporal];
        });
    }

    /**
     * Libera el bloqueo de login y los fallos acumulados de ese usuario (en todas las IP). Si no había nada que liberar,
     * devuelve el usuario tal cual y no deja registro.
     *
     * @param array{id:int} $actor
     * @return array<string,mixed>
     */
    public function desbloquear(array $actor, int $id): array
    {
        return Conexion::transaccion(function () use ($actor, $id): array {
            $actual = $this->usuarioBloqueado($id);

            $liberados = (new AuthServicio())->desbloquear((string) $actual['usuario']);
            if ($liberados > 0) {
                Auditoria::registrar($actor['id'], 'usuario', $id, 'desbloquear', null, ['liberados' => $liberados]);
            }

            return $this->usuarioPorId($id);
        });
    }

    // ------------------------------------------------------------------ interno

    /**
     * Solo letras minúsculas sin tildes, números, punto, guion, guion bajo y @ (para usar un correo), de 3 a 60.
     * Más estricto que el CHECK de la base a propósito: lower() de PostgreSQL y mb_strtolower() de PHP no coinciden
     * con todos los caracteres, y un nombre que PHP aprueba y la base rechaza sería un 500.
     */
    private static function usuarioValido(string $usuario): string
    {
        $usuario = self::normalizarUsuario($usuario);
        if (preg_match('/^[a-z0-9][a-z0-9._@-]{2,59}$/', $usuario) !== 1) {
            throw Errores::validacion(['usuario' => 'De 3 a 60 caracteres: letras sin tildes, números, punto, guion, guion bajo o @.']);
        }
        return $usuario;
    }

    /**
     * 422 si la sede no existe. Las sedes son del módulo catalogos (B2): se le pregunta a su Servicio, no a su tabla.
     * Sin sede (null) no hay nada que comprobar: la sede por defecto puede quedar vacía.
     */
    private function exigirSedeSiHay(?int $sedeId): void
    {
        if ($sedeId !== null) {
            (new CatalogosServicio())->exigirSede($sedeId);
        }
    }

    private static function esGestor(string $rol): bool
    {
        return in_array($rol, Politica::rolesCon('usuario.gestionar'), true);
    }

    private static function esTecnico(string $rol): bool
    {
        return in_array($rol, Politica::rolesCon('incidencia.atender'), true);
    }

    /**
     * Bloquea a los gestores activos (hasta el fin de la transacción) y devuelve sus ids. Toda escritura que pueda
     * quitar a un gestor llama a esto ANTES de bloquear al usuario, para comprobar sin carreras que quede otro.
     *
     * @return list<int>
     */
    private function bloquearGestores(): array
    {
        return $this->repo->bloquearActivosConRol(Politica::rolesCon('usuario.gestionar'));
    }

    /**
     * Sin gestores activos nadie podría crear usuarios ni arreglar un rol: la puerta se cierra desde dentro.
     *
     * @param list<int> $gestores ids de los gestores activos, ya bloqueados
     */
    private static function exigirOtroGestor(array $gestores, int $idQuePierde): void
    {
        if (array_diff($gestores, [$idQuePierde]) === []) {
            throw Errores::conflicto(
                'Es el último jefe de informática activo: sin él nadie podría gestionar usuarios.',
                null,
                'ultimo_gestor'
            );
        }
    }

    /**
     * @param array<string,mixed> $actual
     * @param list<int> $gestores
     */
    private function exigirRolCambiable(array $actual, string $rolNuevo, array $gestores): void
    {
        $id = (int) $actual['id'];
        $rolActual = (string) $actual['rol'];

        if (Conexion::aBool($actual['activo']) && self::esGestor($rolActual) && !self::esGestor($rolNuevo)) {
            self::exigirOtroGestor($gestores, $id);
        }
        if (self::esTecnico($rolActual) && !self::esTecnico($rolNuevo)) {
            $this->exigirSinIncidenciasAbiertas($id);
        }
    }

    /** RN-02: un técnico con incidencias abiertas no se desactiva ni deja de ser técnico. 409 con la lista, para reasignarlas. */
    private function exigirSinIncidenciasAbiertas(int $tecnicoId): void
    {
        $abiertas = $this->incidenciasAbiertasDe($tecnicoId);
        if ($abiertas !== []) {
            throw Errores::conflicto(
                count($abiertas) === 1
                    ? 'El técnico tiene 1 incidencia abierta: reasígnala antes de continuar (RN-02).'
                    : 'El técnico tiene ' . count($abiertas) . ' incidencias abiertas: reasígnalas antes de continuar (RN-02).',
                ['incidencias' => $abiertas],
                'tecnico_con_incidencias'
            );
        }
    }

    /**
     * Las incidencias abiertas de ese técnico (id, equipo, estado, prioridad y fecha: lo que la pantalla necesita para
     * reasignarlas), pedidas al Servicio del módulo incidencias (B2: no se lee su tabla desde aquí).
     *
     * @return list<array<string,mixed>>
     */
    private function incidenciasAbiertasDe(int $tecnicoId): array
    {
        return (new IncidenciasServicio())->abiertasDeTecnico($tecnicoId);
    }

    /** La fila del usuario con SELECT ... FOR UPDATE (dentro de la transacción de quien llama); 404 si no existe o es de sistema. */
    private function usuarioBloqueado(int $id): array
    {
        return $this->repo->obtener($id, true) ?? throw Errores::noEncontrado('No se encontró el usuario.');
    }

    /**
     * Un usuario gestionable, en la forma completa de la API. 404 si no existe o es de sistema.
     *
     * @return array<string,mixed>
     */
    private function usuarioPorId(int $id): array
    {
        $fila = $this->repo->obtener($id) ?? throw Errores::noEncontrado('No se encontró el usuario.');
        return $this->aApiLista([$fila])[0];
    }

    /**
     * Las filas a su forma de API, con el estado de bloqueo de cada una (una sola consulta a auth para toda la lista).
     *
     * @param list<array<string,mixed>> $filas
     * @return list<array<string,mixed>>
     */
    private function aApiLista(array $filas): array
    {
        $bloqueados = array_flip((new AuthServicio())->bloqueados(array_map(static fn (array $f): string => (string) $f['usuario'], $filas)));
        return array_map(
            static fn (array $f): array => self::aApiCompleto($f, isset($bloqueados[(string) $f['usuario']])),
            $filas
        );
    }

    /** SERIALIZADOR (técnicos y reportantes): la forma exacta que ve el frontend. Nunca clave, hash ni estado de bloqueo. */
    private static function aApi(array $fila): array
    {
        return ['id' => (int) $fila['id'], 'nombre' => (string) $fila['nombre'], 'rol' => (string) $fila['rol']];
    }

    /**
     * SERIALIZADOR de la gestión (solo con usuario.gestionar): suma el usuario, la sede por defecto y los estados.
     * Nunca clave ni hash. `bloqueado` = el login está bloqueado ahora por intentos fallidos.
     */
    private static function aApiCompleto(array $fila, bool $bloqueado): array
    {
        return [
            'id' => (int) $fila['id'],
            'nombre' => (string) $fila['nombre'],
            'usuario' => (string) $fila['usuario'],
            'rol' => (string) $fila['rol'],
            'sede_id' => $fila['sede_id'] === null ? null : (int) $fila['sede_id'],
            'activo' => Conexion::aBool($fila['activo']),
            'debe_cambiar_clave' => Conexion::aBool($fila['debe_cambiar_clave']),
            'bloqueado' => $bloqueado,
        ];
    }
}
