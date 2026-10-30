<?php
// backend/nucleo/Solicitud.php
declare(strict_types=1);

namespace Nucleo;

/**
 * La petición HTTP ya limpia. Es el ÚNICO lugar que toca $_GET, $_SERVER y php://input
 * (lo comprueba bin/verificar.sh). El resto del código recibe una Solicitud.
 */
final class Solicitud
{
    private const MAX_CUERPO = 1_048_576; // 1 MiB

    /** @var array<string,string> parámetros de la ruta: /incidencias/{id} */
    public array $params = [];
    /** Lo pone el Router tras autenticar (null en rutas sin sesión). */
    public ?array $usuario = null;
    /** Lo pone el Router en rutas con 'idempotente' => true. */
    public ?string $claveIdempotencia = null;
    private ?array $cuerpo = null;

    /** @param array<string,string> $cabeceras nombres en minúscula */
    public function __construct(
        public string $metodo,
        public string $ruta,
        public array $query,
        private string $crudo,
        private array $cabeceras,
        public string $ip,
    ) {
    }

    public static function desdeGlobales(): self
    {
        $metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $ruta = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

        $prefijo = rtrim((string) Config::get('API_PREFIJO', '/api'), '/');
        if ($prefijo !== '' && ($ruta === $prefijo || str_starts_with($ruta, $prefijo . '/'))) {
            $ruta = substr($ruta, strlen($prefijo));
        }
        $ruta = '/' . trim($ruta, '/');

        $cabeceras = [];
        foreach ($_SERVER as $clave => $valor) {
            if (is_string($clave) && str_starts_with($clave, 'HTTP_')) {
                $cabeceras[strtolower(str_replace('_', '-', substr($clave, 5)))] = (string) $valor;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $clave => $nombre) {
            if (isset($_SERVER[$clave])) {
                $cabeceras[$nombre] = (string) $_SERVER[$clave];
            }
        }

        $crudo = '';
        if (in_array($metodo, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $crudo = (string) file_get_contents('php://input', false, null, 0, self::MAX_CUERPO + 1);
        }

        return new self($metodo, $ruta, $_GET, $crudo, $cabeceras, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    public function cabecera(string $nombre): ?string
    {
        return $this->cabeceras[strtolower($nombre)] ?? null;
    }

    /** Cuerpo JSON como arreglo. Se interpreta al pedirlo, para que un 401 no se tape con un 422. */
    public function cuerpo(): array
    {
        if ($this->cuerpo !== null) {
            return $this->cuerpo;
        }
        if (trim($this->crudo) === '') {
            return $this->cuerpo = [];
        }
        if (strlen($this->crudo) > self::MAX_CUERPO) {
            throw Errores::validacion([], 'El cuerpo de la petición es demasiado grande.');
        }
        if (stripos((string) $this->cabecera('content-type'), 'application/json') === false) {
            throw Errores::validacion([], 'Se esperaba application/json.');
        }
        try {
            $datos = json_decode($this->crudo, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw Errores::validacion([], 'El cuerpo no es JSON válido.');
        }
        if (!is_array($datos)) {
            throw Errores::validacion([], 'El cuerpo debe ser un objeto JSON.');
        }
        return $this->cuerpo = $datos;
    }

    public function param(string $nombre): string
    {
        return $this->params[$nombre] ?? throw Errores::noEncontrado();
    }

    /** Id numérico de la ruta; si no es un entero, para el cliente simplemente no existe (404). */
    public function paramEntero(string $nombre): int
    {
        $valor = $this->param($nombre);
        if (!ctype_digit($valor) || strlen($valor) > 18) {
            throw Errores::noEncontrado();
        }
        return (int) $valor;
    }

    public function consulta(string $nombre, ?string $porDefecto = null): ?string
    {
        $valor = $this->query[$nombre] ?? $porDefecto;
        return is_string($valor) ? $valor : $porDefecto;
    }

    /**
     * Defensa CSRF adicional a SameSite=Strict (RNF-01): en peticiones que modifican datos,
     * si el navegador envía Origin, debe ser el mismo sitio.
     */
    public function validarOrigen(): void
    {
        if (!in_array($this->metodo, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        $origen = $this->cabecera('origin');
        if ($origen === null) {
            return; // clientes que no son navegador; la cookie SameSite=Strict ya no viaja entre sitios
        }
        $partes = parse_url($origen) ?: [];
        $host = ($partes['host'] ?? '') . (isset($partes['port']) ? ':' . $partes['port'] : '');
        $propio = (string) $this->cabecera('host');
        $permitidos = array_filter(array_map('trim', explode(',', (string) Config::get('ORIGENES_PERMITIDOS', ''))));

        if ($host !== $propio && !in_array($origen, $permitidos, true)) {
            throw Errores::prohibido('origen_invalido', 'Origen no permitido.');
        }
    }
}
