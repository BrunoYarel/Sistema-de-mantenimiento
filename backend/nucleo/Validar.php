<?php
// backend/nucleo/Validar.php
declare(strict_types=1);

namespace Nucleo;

/**
 * Validación de campos del cuerpo. Acumula errores y lanza un 422 con "campos".
 *
 *   $v = new Validar($solicitud->cuerpo());
 *   $descripcion = $v->texto('descripcion', max: 500);
 *   $canal       = $v->enum('canal', Dominio::CANALES);
 *   $v->exigir();   // 422 { campos: { descripcion: "Obligatorio." } }
 */
final class Validar
{
    /** @var array<string,string> */
    private array $errores = [];

    public function __construct(private array $datos)
    {
    }

    public function texto(string $campo, bool $obligatorio = true, int $max = 255, int $min = 0, bool $recortar = true): ?string
    {
        $valor = $this->datos[$campo] ?? null;
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            if ($obligatorio) {
                $this->errores[$campo] = 'Obligatorio.';
            }
            return null;
        }
        if (!is_string($valor)) {
            $this->errores[$campo] = 'Debe ser texto.';
            return null;
        }
        if ($recortar) {
            $valor = trim($valor);
        }
        $largo = mb_strlen($valor);
        if ($largo > $max) {
            $this->errores[$campo] = "Máximo $max caracteres.";
            return null;
        }
        if ($largo < $min) {
            $this->errores[$campo] = "Mínimo $min caracteres.";
            return null;
        }
        return $valor;
    }

    public function entero(string $campo, bool $obligatorio = true): ?int
    {
        $valor = $this->datos[$campo] ?? null;
        if ($valor === null || $valor === '') {
            if ($obligatorio) {
                $this->errores[$campo] = 'Obligatorio.';
            }
            return null;
        }
        if (is_int($valor) || (is_string($valor) && preg_match('/^-?\d{1,18}$/', $valor) === 1)) {
            return (int) $valor;
        }
        $this->errores[$campo] = 'Debe ser un número entero.';
        return null;
    }

    /** @param list<string> $validos */
    public function enum(string $campo, array $validos, bool $obligatorio = true): ?string
    {
        $valor = $this->datos[$campo] ?? null;
        if ($valor === null || $valor === '') {
            if ($obligatorio) {
                $this->errores[$campo] = 'Obligatorio.';
            }
            return null;
        }
        if (!is_string($valor) || !in_array($valor, $validos, true)) {
            $this->errores[$campo] = 'Valor no permitido.';
            return null;
        }
        return $valor;
    }

    public function booleano(string $campo, bool $porDefecto = false): bool
    {
        $valor = $this->datos[$campo] ?? $porDefecto;
        if (!is_bool($valor)) {
            $this->errores[$campo] = 'Debe ser verdadero o falso.';
            return $porDefecto;
        }
        return $valor;
    }

    public function error(string $campo, string $mensaje): void
    {
        $this->errores[$campo] = $mensaje;
    }

    public function exigir(): void
    {
        if ($this->errores !== []) {
            throw Errores::validacion($this->errores);
        }
    }
}
