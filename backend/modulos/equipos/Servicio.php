<?php
// backend/modulos/equipos/Servicio.php
declare(strict_types=1);

namespace Modulos\Equipos;

use Modulos\Catalogos\Servicio as CatalogosServicio;
use Modulos\Usuarios\Servicio as UsuariosServicio;
use Nucleo\Auditoria;
use Nucleo\Cambios;
use Nucleo\Conexion;
use Nucleo\Dominio;
use Nucleo\Errores;
use Nucleo\Idempotencia;
use Nucleo\Paginacion;
use Nucleo\Politica;
use Nucleo\Reloj;
use Nucleo\Texto;
use PDOException;

/**
 * Reglas del módulo (B1): equipos (búsqueda, alta, edición, baja, reactivación) y, por ahora, historial y fuera de servicio
 * (RF-09), que se mudan a incidencias cuando se construya. Las ubicaciones son del módulo catalogos: aquí solo se
 * consultan por su Servicio (B2), nunca por su tabla.
 */
final class Servicio
{
    /** Alias de Paginacion::POR_PAGINA (lo usan los tests). El selector en cascada (con ubicación) no pagina. */
    public const POR_PAGINA = Paginacion::POR_PAGINA;

    private const COMPLETO = 'completo'; // equipo.gestionar: ve criticidad, sede y baja
    private const BASICO = 'basico';     // solo equipo.elegir: código, tipo y ubicación

    private const CODIGO_REPETIDO = 'Ya existe un equipo con ese código.';

    /**
     * Restricciones de la base que este módulo sabe explicar (nombre => [campo, mensaje]). Normalmente no llegan:
     * las comprobaciones previas las atajan. Quedan por si dos peticiones se cruzan. El resto lo traduce Errores::desdePdo.
     */
    private const RESTRICCIONES = [
        'equipo_codigo_key' => ['codigo', self::CODIGO_REPETIDO],
        'equipo_codigo_check' => ['codigo', 'El código tiene caracteres que no se pueden pasar a mayúsculas.'],
    ];

    private Repositorio $repo;

    public function __construct()
    {
        $this->repo = new Repositorio();
    }

    /**
     * Código de equipo: mayúsculas y espacios a guion. ÚNICA función que lo normaliza: la usan la búsqueda,
     * el alta y bin/importar-equipos.php, para que "pc 01" y "PC-01" sean el mismo equipo.
     */
    public static function normalizarCodigo(string $codigo): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '-', trim($codigo)) ?? '');
    }

    /**
     * Búsqueda de equipos. Con `ubicacion` devuelve todos los de esa ubicación (selector en cascada);
     * sin ella pagina de POR_PAGINA en POR_PAGINA. Qué campos salen depende del permiso del usuario:
     * con equipo.gestionar el equipo completo; con solo equipo.elegir, código, tipo y ubicación (y solo activos).
     *
     * @param array{rol:string} $usuario
     * @param array{sede:?int,ubicacion:?int,tipo:?string,q:?string,activo:?bool} $filtros
     * @return array{items:list<array<string,mixed>>,total:int,pagina?:int,por_pagina?:int}
     */
    public function equipos(array $usuario, array $filtros, int $pagina): array
    {
        $completo = $this->alcance($usuario) === self::COMPLETO;
        if (!$completo) {
            $filtros['activo'] = true; // un equipo dado de baja no se elige
        }
        if ($filtros['q'] !== null) {
            $filtros['q'] = self::normalizarCodigo($filtros['q']);
        }

        $todos = $filtros['ubicacion'] !== null;
        $filas = $this->repo->buscarEquipos($filtros, $todos ? null : Paginacion::POR_PAGINA, $todos ? 0 : Paginacion::desplazamiento($pagina));
        $items = array_map($completo ? [self::class, 'equipoCompletoAApi'] : [self::class, 'equipoBasicoAApi'], $this->conUbicaciones($filas));

        if ($todos) {
            return ['items' => $items, 'total' => count($items)];
        }
        return Paginacion::respuesta($items, $this->repo->contarEquipos($filtros), $pagina);
    }

    /**
     * Detalle de un equipo, completo (con criticidad). La ruta exige equipo.gestionar.
     *
     * @return array<string,mixed>
     */
    public function ver(int $id): array
    {
        return self::equipoCompletoAApi($this->cargarEquipo($id) ?? throw Errores::noEncontrado('No se encontró el equipo.'));
    }

    /**
     * Alta de un equipo (RF-04). El código se normaliza y es único; la sede es la de la ubicación elegida.
     * Queda en auditoría. Reintento con la misma clave = mismo equipo, sin duplicar (RNF-07).
     *
     * @param array{id:int} $usuario
     * @return array<string,mixed>
     */
    public function crearEquipo(array $usuario, string $codigo, string $tipo, int $ubicacionId, string $criticidad, string $claveIdempotencia): array
    {
        $codigo = self::codigoValido($codigo);

        try {
            return Idempotencia::ejecutar(
                $usuario['id'],
                $claveIdempotencia,
                'equipo.crear',
                function () use ($usuario, $codigo, $tipo, $ubicacionId, $criticidad): int {
                    $ubicacion = (new CatalogosServicio())->ubicacion($ubicacionId)
                        ?? throw Errores::validacion(['ubicacion_id' => 'La ubicación no existe.']);
                    if ($this->repo->existeCodigo($codigo)) {
                        throw Errores::validacion(['codigo' => self::CODIGO_REPETIDO]);
                    }

                    $sedeId = (int) $ubicacion['sede_id'];
                    $id = $this->repo->insertarEquipo($codigo, $tipo, $sedeId, $ubicacionId, $criticidad, Reloj::paraBd());
                    Auditoria::registrar($usuario['id'], 'equipo', $id, 'crear', null, [
                        'codigo' => $codigo,
                        'tipo' => $tipo,
                        'sede_id' => $sedeId,
                        'ubicacion_id' => $ubicacionId,
                        'criticidad' => $criticidad,
                    ]);
                    return $id;
                },
                fn (int $id): array => $this->ver($id)
            );
        } catch (PDOException $e) {
            throw Errores::deRestriccion($e, self::RESTRICCIONES) ?? $e;
        }
    }

    /**
     * Edita tipo, ubicación y criticidad. El código no se edita. Cambiar la ubicación cambia también la sede del
     * equipo (las incidencias ya registradas conservan la suya, RF-11). Cada cambio real deja su registro en
     * auditoría (la criticidad mueve la prioridad, RN-04); lo que llega igual que está no escribe nada.
     * Como toda escritura: SELECT ... FOR UPDATE, cambio y auditoría en una sola transacción.
     *
     * @param array{id:int} $usuario
     * @param array{tipo?:string,ubicacion_id?:int,criticidad?:string} $cambios solo lo que llegó
     * @param ?string $codigoEnviado si el cliente lo manda, debe ser el actual
     * @return array<string,mixed>
     */
    public function editarEquipo(array $usuario, int $id, array $cambios, ?string $codigoEnviado): array
    {
        try {
            return Conexion::transaccion(function () use ($usuario, $id, $cambios, $codigoEnviado): array {
                $actual = $this->equipoBloqueado($id);

                if ($codigoEnviado !== null && self::normalizarCodigo($codigoEnviado) !== (string) $actual['codigo']) {
                    throw Errores::validacion(['codigo' => 'El código no se puede cambiar.']);
                }

                $c = new Cambios();
                $c->campo('tipo', (string) $actual['tipo'], $cambios['tipo'] ?? (string) $actual['tipo'], 'cambiar_tipo');
                $c->campo('criticidad', (string) $actual['criticidad'], $cambios['criticidad'] ?? (string) $actual['criticidad'], 'cambiar_criticidad');
                if (isset($cambios['ubicacion_id']) && $cambios['ubicacion_id'] !== (int) $actual['ubicacion_id']) {
                    $ubicacion = (new CatalogosServicio())->ubicacion($cambios['ubicacion_id'])
                        ?? throw Errores::validacion(['ubicacion_id' => 'La ubicación no existe.']);
                    $sedeNueva = (int) $ubicacion['sede_id']; // la sede sigue a la ubicación
                    $c->varias(
                        ['ubicacion_id' => $cambios['ubicacion_id'], 'sede_id' => $sedeNueva],
                        'cambiar_ubicacion',
                        [
                            'antes' => ['ubicacion_id' => (int) $actual['ubicacion_id'], 'sede_id' => (int) $actual['sede_id']],
                            'despues' => ['ubicacion_id' => $cambios['ubicacion_id'], 'sede_id' => $sedeNueva],
                        ]
                    );
                }

                if (!$c->hay()) {
                    return self::equipoCompletoAApi($actual);
                }
                if ($this->repo->actualizarEquipo($id, $c->nuevos()) !== 1) {
                    throw Errores::modificadoPorOtro('el equipo');
                }
                $c->auditar($usuario['id'], 'equipo', $id);

                return $this->ver($id);
            });
        } catch (PDOException $e) {
            throw Errores::deRestriccion($e, self::RESTRICCIONES) ?? $e;
        }
    }

    /**
     * Da de baja un equipo: activo = false, con motivo obligatorio y rastro en auditoría. No se borra nada (RN-07):
     * el equipo, su historial y sus incidencias siguen ahí, y puede reactivarse. La ruta exige equipo.dar_de_baja
     * (solo el jefe de soporte). 409 si ya estaba dado de baja.
     *
     * Es público a propósito: la acción dar_de_baja de una incidencia no_reparable (módulo incidencias) llama a esta
     * misma función dentro de su transacción (Conexion::transaccion se une a la abierta) y suma su propio evento.
     *
     * @param array{id:int} $usuario
     * @return array<string,mixed> el equipo completo
     */
    public function darDeBaja(array $usuario, int $id, string $motivo): array
    {
        $motivo = Texto::exigir('motivo', $motivo);

        return Conexion::transaccion(function () use ($usuario, $id, $motivo): array {
            $actual = $this->equipoBloqueado($id);
            if (!Conexion::aBool($actual['activo'])) {
                throw Errores::conflicto('El equipo ya estaba dado de baja.', self::equipoCompletoAApi($actual), 'transicion_invalida');
            }

            if ($this->repo->cambiarActivo($id, false) !== 1) {
                throw Errores::modificadoPorOtro('el equipo');
            }
            Auditoria::registrar($usuario['id'], 'equipo', $id, 'dar_de_baja', $motivo);

            return $this->ver($id);
        });
    }

    /**
     * Reactiva un equipo dado de baja. El motivo es opcional (si llega, queda en auditoría). 409 si ya estaba activo.
     *
     * @param array{id:int} $usuario
     * @return array<string,mixed> el equipo completo
     */
    public function reactivarEquipo(array $usuario, int $id, ?string $motivo): array
    {
        if ($motivo !== null) {
            $motivo = Texto::exigir('motivo', $motivo);
        }

        return Conexion::transaccion(function () use ($usuario, $id, $motivo): array {
            $actual = $this->equipoBloqueado($id);
            if (Conexion::aBool($actual['activo'])) {
                throw Errores::conflicto('El equipo ya estaba activo.', self::equipoCompletoAApi($actual), 'transicion_invalida');
            }

            if ($this->repo->cambiarActivo($id, true) !== 1) {
                throw Errores::modificadoPorOtro('el equipo');
            }
            Auditoria::registrar($usuario['id'], 'equipo', $id, 'reactivar', $motivo);

            return $this->ver($id);
        });
    }

    /**
     * Historial de intervenciones de un equipo (RF-09): una entrada por incidencia, la más reciente primero, de 25 en 25.
     * Cada entrada trae exactamente fecha (la del registro), falla, solución (las acciones de la última atención) y técnico:
     * sin reportante ni reportes vinculados (RN-09). La ruta exige equipo.historial. Un equipo dado de baja también tiene historial.
     *
     * @return array{items:list<array{fecha:string,falla:string,solucion:?string,tecnico:?string}>,total:int,pagina:int,por_pagina:int}
     */
    public function historial(int $id, int $pagina): array
    {
        if ($this->repo->obtenerEquipo($id) === null) {
            throw Errores::noEncontrado('No se encontró el equipo.');
        }

        $filas = $this->repo->historialDeEquipo($id, Paginacion::POR_PAGINA, Paginacion::desplazamiento($pagina));
        $tecnicos = [];
        foreach ($filas as $fila) {
            if ($fila['tecnico_id'] !== null) {
                $tecnicos[] = (int) $fila['tecnico_id'];
            }
        }
        $nombres = (new UsuariosServicio())->nombres($tecnicos); // B2: los nombres los da el Servicio de usuarios

        $items = array_map(
            static fn (array $fila): array => [
                'fecha' => (string) Reloj::iso($fila['fecha_registro']),
                'falla' => (string) $fila['descripcion'],
                'solucion' => $fila['solucion'] === null ? null : (string) $fila['solucion'],
                'tecnico' => $fila['tecnico_id'] === null ? null : ($nombres[(int) $fila['tecnico_id']] ?? null),
            ],
            $filas
        );

        return Paginacion::respuesta($items, $this->repo->contarHistorialDeEquipo($id), $pagina);
    }

    /**
     * Equipos fuera de servicio (RF-09). NO es un dato guardado: es un equipo ACTIVO con al menos una incidencia abierta
     * (Dominio::ESTADOS_ABIERTOS). Un equipo de baja ya no se repara y no figura. El que lleva más tiempo, primero; de 25 en 25.
     * Un equipo que sigue andando pero tiene una incidencia menor también sale: si molesta, se agrega un campo
     * "inoperativo" a la incidencia. La ruta exige equipo.historial.
     *
     * @param array{sede:?int,ubicacion:?int,tipo:?string} $filtros
     * @return array{items:list<array<string,mixed>>,total:int,pagina:int,por_pagina:int}
     */
    public function fueraDeServicio(array $filtros, int $pagina): array
    {
        $filas = $this->conUbicaciones(
            $this->repo->buscarFueraDeServicio($filtros, Dominio::ESTADOS_ABIERTOS, Paginacion::POR_PAGINA, Paginacion::desplazamiento($pagina))
        );

        $items = array_map(
            static fn (array $fila): array => self::equipoCompletoAApi($fila) + [
                'incidencias_abiertas' => (int) $fila['incidencias_abiertas'],
                'desde' => (string) Reloj::iso($fila['desde']),
            ],
            $filas
        );

        return Paginacion::respuesta($items, $this->repo->contarFueraDeServicio($filtros, Dominio::ESTADOS_ABIERTOS), $pagina);
    }

    // ------------------------------------------------------------------ interno

    /**
     * La ruta solo pide sesión: el jefe de informática gestiona equipos pero no tiene equipo.elegir.
     * Aquí se decide qué ve cada quien; sin ninguno de los dos permisos, 403.
     */
    private function alcance(array $usuario): string
    {
        if (Politica::puede($usuario, 'equipo.gestionar')) {
            return self::COMPLETO;
        }
        if (Politica::puede($usuario, 'equipo.elegir')) {
            return self::BASICO;
        }
        throw Errores::prohibido();
    }

    private static function codigoValido(string $codigo): string
    {
        $codigo = self::normalizarCodigo($codigo);
        if ($codigo === '') {
            throw Errores::validacion(['codigo' => 'Obligatorio.']);
        }
        return $codigo;
    }

    /** El equipo con SELECT ... FOR UPDATE (dentro de la transacción de quien llama); 404 si no existe. */
    private function equipoBloqueado(int $id): array
    {
        return $this->cargarEquipo($id, true) ?? throw Errores::noEncontrado('No se encontró el equipo.');
    }

    /** El equipo con el nombre de su ubicación ya agregado, o null si no existe. */
    private function cargarEquipo(int $id, bool $bloquear = false): ?array
    {
        $fila = $this->repo->obtenerEquipo($id, $bloquear);
        return $fila === null ? null : $this->conUbicaciones([$fila])[0];
    }

    /**
     * Agrega a cada fila el nombre de su ubicación (`ubicacion`). Lo da el Servicio de catalogos (B2): este módulo
     * no lee la tabla `ubicacion`. Una sola consulta por lista, no una por equipo.
     *
     * @param list<array<string,mixed>> $filas filas de equipo con `ubicacion_id`
     * @return list<array<string,mixed>>
     */
    private function conUbicaciones(array $filas): array
    {
        $ids = array_map(static fn (array $fila): int => (int) $fila['ubicacion_id'], $filas);
        $nombres = (new CatalogosServicio())->nombresDeUbicaciones($ids);

        return array_map(
            static fn (array $fila): array => $fila + ['ubicacion' => $nombres[(int) $fila['ubicacion_id']] ?? ''],
            $filas
        );
    }

    private static function equipoBasicoAApi(array $fila): array
    {
        return [
            'id' => (int) $fila['id'],
            'codigo' => (string) $fila['codigo'],
            'tipo' => (string) $fila['tipo'],
            'ubicacion_id' => (int) $fila['ubicacion_id'],
            'ubicacion' => (string) $fila['ubicacion'],
        ];
    }

    private static function equipoCompletoAApi(array $fila): array
    {
        return self::equipoBasicoAApi($fila) + [
            'sede_id' => (int) $fila['sede_id'],
            'criticidad' => (string) $fila['criticidad'],
            'activo' => Conexion::aBool($fila['activo']),
        ];
    }
}