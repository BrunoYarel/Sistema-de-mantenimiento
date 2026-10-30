<?php
// backend/nucleo/Texto.php
declare(strict_types=1);

namespace Nucleo;

/**
 * ÚNICO lugar donde se normaliza texto escrito por personas. Antes cada módulo tenía su copia
 * (normalizarNombre, nombreValido, motivoValido, patron); ahora se llaman desde aquí.
 */
final class Texto
{
    /** Sin espacios en los bordes y un solo espacio entre palabras ("  Ana   Pérez " -> "Ana Pérez"). Mayúsculas y tildes se respetan. */
    public static function espacios(string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    /** Igual que espacios(), pero 422 en ese campo si queda vacío (nombres, motivos: nunca en blanco). */
    public static function exigir(string $campo, string $texto): string
    {
        $limpio = self::espacios($texto);
        if ($limpio === '') {
            throw Errores::validacion([$campo => 'Obligatorio.']);
        }
        return $limpio;
    }

    /** "%pérez%" -> "%perez%" (la consulta también quita las tildes de la columna) con los comodines de LIKE escapados. */
    public static function patronBusqueda(string $q): string
    {
        $q = strtr(mb_strtolower(trim($q)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
        return '%' . addcslashes($q, '%_\\') . '%';
    }
}
