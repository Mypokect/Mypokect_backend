<?php

namespace App\Helpers;

/**
 * Utilidad UVT (Unidad de Valor Tributario) Colombia.
 *
 * Centraliza el valor anual de la UVT y los umbrales fiscales expresados
 * en UVT para que los límites en pesos se actualicen automáticamente
 * cada año sin tocar la lógica de negocio.
 */
class UvtHelper
{
    /** Valor de la UVT por año fiscal (DIAN Colombia) */
    private const TABLA = [
        2022 => 38_004,
        2023 => 42_412,
        2024 => 47_065,
        2025 => 49_799,
        2026 => 49_799, // Provisional — actualizar cuando DIAN publique resolución
    ];

    // ── Umbrales en UVT (Art. 592, 596, 599, 607 ET y Decreto 1625) ──────────

    /** Tope ingresos brutos para obligación de declarar (personas naturales) */
    public const UVT_INGRESOS_BRUTOS = 1_400;

    /** Tope rentas de capital para declaración complementaria */
    public const UVT_RENTAS_CAPITAL = 2_400;

    /** Tope facturación electrónica / contratos */
    public const UVT_FACTURACION = 3_680;

    /** Tope patrimonio neto para impuesto al patrimonio */
    public const UVT_PATRIMONIO = 72_000;

    /** Tope ingresos para pertenecer al Régimen Simple */
    public const UVT_REGIMEN_SIMPLE = 100_000;

    // ── Métodos ───────────────────────────────────────────────────────────────

    /**
     * Retorna el valor de la UVT para el año dado.
     * Si el año no existe en la tabla usa el último año conocido.
     */
    public static function valorUvt(int $year): float
    {
        return (float) (self::TABLA[$year] ?? end(self::TABLA));
    }

    /**
     * Convierte un monto expresado en UVT a pesos colombianos.
     */
    public static function enPesos(int $uvt, int $year): float
    {
        return $uvt * self::valorUvt($year);
    }
}
