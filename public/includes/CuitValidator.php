<?php
// public/includes/CuitValidator.php
declare(strict_types=1);

/**
 * Validador de CUIT/CUIL/CDI argentino
 * Valida formato, tipo y dígito verificador según AFIP
 */
class CuitValidator
{
    private const TIPOS_VALIDOS = [20, 23, 24, 27, 30, 33, 34];
    private const MULTIPLICADORES = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    
    /**
     * Valida un CUIT/CUIL completo
     */
    public static function validar(?string $cuit): bool
    {
        if ($cuit === null || trim($cuit) === '') {
            return false;
        }
        
        $cuitLimpio = self::limpiar($cuit);
        
        if (strlen($cuitLimpio) !== 11 || !ctype_digit($cuitLimpio)) {
            return false;
        }
        
        $tipo = (int)substr($cuitLimpio, 0, 2);
        if (!in_array($tipo, self::TIPOS_VALIDOS, true)) {
            return false;
        }
        
        return self::verificarDigito($cuitLimpio);
    }
    
    /**
     * Verifica el dígito verificador
     */
    private static function verificarDigito(string $cuit): bool
    {
        $suma = 0;
        
        for ($i = 0; $i < 10; $i++) {
            $suma += (int)$cuit[$i] * self::MULTIPLICADORES[$i];
        }
        
        $resto = $suma % 11;
        $digitoEsperado = 11 - $resto;
        
        if ($digitoEsperado === 11) $digitoEsperado = 0;
        if ($digitoEsperado === 10) $digitoEsperado = 9;
        
        return $digitoEsperado === (int)$cuit[10];
    }
    
    /**
     * Limpia un CUIT de guiones y espacios
     */
    public static function limpiar(string $cuit): string
    {
        return preg_replace('/[^0-9]/', '', $cuit) ?? '';
    }
    
    /**
     * Formatea un CUIT a XX-XXXXXXXX-X
     */
    public static function formatear(?string $cuit): ?string
    {
        if ($cuit === null) {
            return null;
        }
        
        $limpio = self::limpiar($cuit);
        
        if (strlen($limpio) !== 11) {
            return null;
        }
        
        return substr($limpio, 0, 2) . '-' . 
               substr($limpio, 2, 8) . '-' . 
               substr($limpio, 10, 1);
    }
    
    /**
     * Obtiene el tipo de documento
     */
    public static function obtenerTipo(string $cuit): string
    {
        $limpio = self::limpiar($cuit);
        
        if (strlen($limpio) !== 11) {
            return 'Desconocido';
        }
        
        $tipo = (int)substr($limpio, 0, 2);
        
        return match($tipo) {
            20 => 'CUIT Persona Física Masculino',
            23, 24 => 'CUIT Persona Física Femenino',
            27 => 'CUIL Persona Física',
            30 => 'CUIT Persona Jurídica',
            33, 34 => 'CUIL Extranjero',
            default => 'Tipo desconocido'
        };
    }
    
    /**
     * Valida que sea CUIT de empresa (tipo 30)
     */
    public static function esEmpresa(?string $cuit): bool
    {
        if (!self::validar($cuit)) {
            return false;
        }
        
        $limpio = self::limpiar($cuit);
        $tipo = (int)substr($limpio, 0, 2);
        
        return $tipo === 30;
    }
    
    /**
     * Valida que sea CUIL de persona física
     */
    public static function esPersonaFisica(?string $cuit): bool
    {
        if (!self::validar($cuit)) {
            return false;
        }
        
        $limpio = self::limpiar($cuit);
        $tipo = (int)substr($limpio, 0, 2);
        
        return in_array($tipo, [20, 23, 24, 27], true);
    }
    
    /**
     * Genera mensaje de error detallado
     */
    public static function obtenerError(?string $cuit): string
    {
        if ($cuit === null || trim($cuit) === '') {
            return 'El CUIT/CUIL está vacío.';
        }
        
        $limpio = self::limpiar($cuit);
        
        if (strlen($limpio) < 11) {
            return 'El CUIT/CUIL es muy corto. Debe tener 11 dígitos (XX-XXXXXXXX-X).';
        }
        
        if (strlen($limpio) > 11) {
            return 'El CUIT/CUIL es muy largo. Debe tener 11 dígitos (XX-XXXXXXXX-X).';
        }
        
        if (!ctype_digit($limpio)) {
            return 'El CUIT/CUIL contiene caracteres inválidos.';
        }
        
        $tipo = (int)substr($limpio, 0, 2);
        if (!in_array($tipo, self::TIPOS_VALIDOS, true)) {
            return "El tipo de CUIT/CUIL ($tipo) no es válido.";
        }
        
        if (!self::verificarDigito($limpio)) {
            return 'El dígito verificador del CUIT/CUIL es incorrecto.';
        }
        
        return '';
    }
}