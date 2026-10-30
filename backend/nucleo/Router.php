<?php
// backend/nucleo/Router.php
declare(strict_types=1);

namespace Nucleo;

/**
 * Router mínimo. Cada módulo registra sus rutas en modulos/<id>/rutas.php:
 *
 *   $r->get('/equipos/{id}', [Controlador::class, 'ver'], ['permiso' => 'equipo.historial']);
 *   $r->post('/incidencias', [Controlador::class, 'crear'], ['permiso' => 'incidencia.registrar_propia',
 *                                                              'idempotente' => true, 'estado' => 201]);
 *
 * Opciones (todas se validan AL ARRANCAR; un error aquí rompe la app a propósito, como en el frontend):
 *   permiso          OBLIGATORIA. Un permiso de Dominio::PERMISOS, o null = basta con tener sesión (F6).
 *   sesion           false solo en rutas públicas (login). Por defecto true.
 *   idempotente      true en todo POST que inserta: exige Idempotency-Key (B7, F8).
 *   clave_pendiente  true en las pocas rutas permitidas con debe_cambiar_clave (me, clave, logout).
 *   estado           código HTTP de éxito. Por defecto 200.
 *
 * El handler es [Clase::class, 'metodo'] o un callable y recibe una Solicitud; devuelve los datos
 * (se envuelven en { data: ... }). Los errores se lanzan con Errores::...
 *
 * Orden de coincidencia: las rutas fijas (/equipos/fuera-de-servicio) ganan siempre a las que llevan
 * {parametros} (/equipos/{id}), sin importar el orden de registro. Dentro de cada grupo manda el orden de registro.
 */
final class Router
{
    private const OPCIONES = ['permiso', 'sesion', 'idempotente', 'clave_pendiente', 'estado'];

    /** @var array<string,array{metodo:string,regex:string,dinamica:bool,handler:mixed,opc:array<string,mixed>}> */
    private array $rutas = [];

    public function get(string $ruta, array|callable $handler, array $opc): void
    {
        $this->agregar('GET', $ruta, $handler, $opc);
    }

    public function post(string $ruta, array|callable $handler, array $opc): void
    {
        $this->agregar('POST', $ruta, $handler, $opc);
    }

    public function put(string $ruta, array|callable $handler, array $opc): void
    {
        $this->agregar('PUT', $ruta, $handler, $opc);
    }

    public function patch(string $ruta, array|callable $handler, array $opc): void
    {
        $this->agregar('PATCH', $ruta, $handler, $opc);
    }

    public function delete(string $ruta, array|callable $handler, array $opc): void
    {
        $this->agregar('DELETE', $ruta, $handler, $opc);
    }

    public function despachar(Solicitud $solicitud): void
    {
        $otrosMetodos = false;
        $rutas = $this->rutas;
        uasort($rutas, static fn (array $a, array $b): int => $a['dinamica'] <=> $b['dinamica']);
        foreach ($rutas as $r) {
            if (preg_match($r['regex'], $solicitud->ruta, $m) !== 1) {
                continue;
            }
            if ($r['metodo'] !== $solicitud->metodo) {
                $otrosMetodos = true;
                continue;
            }
            $solicitud->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $this->ejecutar($r, $solicitud);
            return;
        }
        if ($otrosMetodos) {
            throw new ErrorApi(405, 'metodo_no_permitido', 'Método no permitido.');
        }
        throw Errores::noEncontrado('Ruta no encontrada.');
    }

    // ------------------------------------------------------------------ interno

    private function agregar(string $metodo, string $ruta, array|callable $handler, array $opc): void
    {
        $id = "$metodo $ruta";
        if (preg_match('#^(/[a-z0-9_-]+|/\{[a-z_]+\})+$#', $ruta) !== 1) {
            throw new \LogicException("Ruta inválida '$id': usa segmentos en minúscula y {parametros}.");
        }
        if (isset($this->rutas[$id])) {
            throw new \LogicException("Ruta duplicada: $id");
        }
        if (!array_key_exists('permiso', $opc)) {
            throw new \LogicException("$id: declara 'permiso' (usa null si basta con tener sesión).");
        }
        if ($opc['permiso'] !== null && !in_array($opc['permiso'], Dominio::PERMISOS, true)) {
            throw new \LogicException("$id: el permiso '{$opc['permiso']}' no existe en Dominio::PERMISOS.");
        }
        if (($opc['sesion'] ?? true) === false && $opc['permiso'] !== null) {
            throw new \LogicException("$id: una ruta sin sesión no puede exigir un permiso.");
        }
        $desconocidas = array_diff(array_keys($opc), self::OPCIONES);
        if ($desconocidas !== []) {
            throw new \LogicException("$id: opciones desconocidas: " . implode(', ', $desconocidas));
        }

        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[A-Za-z0-9_-]+)', $ruta) . '$#';
        $this->rutas[$id] = [
            'metodo' => $metodo,
            'regex' => $regex,
            'dinamica' => str_contains($ruta, '{'),
            'handler' => $handler,
            'opc' => $opc,
        ];
    }

    private function ejecutar(array $ruta, Solicitud $solicitud): void
    {
        $opc = $ruta['opc'];
        $solicitud->validarOrigen();

        if (($opc['sesion'] ?? true) !== false) {
            $usuario = Sesion::usuarioActual();
            if ($usuario === null) {
                throw Errores::sinSesion();
            }
            if ($usuario['debe_cambiar_clave'] && empty($opc['clave_pendiente'])) {
                throw Errores::prohibido('cambiar_clave', 'Debes cambiar tu clave antes de continuar.');
            }
            if ($opc['permiso'] !== null) {
                Politica::exigir($usuario, $opc['permiso']);
            }
            $solicitud->usuario = $usuario;
        }

        if (!empty($opc['idempotente'])) {
            $solicitud->claveIdempotencia = Idempotencia::exigirClave($solicitud);
        }

        $datos = $this->llamar($ruta['handler'], $solicitud);
        Respuesta::ok($datos, (int) ($opc['estado'] ?? 200));
    }

    private function llamar(mixed $handler, Solicitud $solicitud): mixed
    {
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
            [$clase, $metodo] = $handler;
            return (new $clase())->$metodo($solicitud);
        }
        return $handler($solicitud);
    }
}