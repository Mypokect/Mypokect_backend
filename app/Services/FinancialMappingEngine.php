<?php

namespace App\Services;

/**
 * Static knowledge base: merchant names / keywords → canonical financial categories.
 *
 * Usage:
 *   FinancialMappingEngine::mapToCategory('gasté en Rappi')  → 'Comida'
 *   FinancialMappingEngine::enrichTag('netflix', 'General')  → 'Entretenimiento'
 *   FinancialMappingEngine::getPromptHint()                  → short string for LLM prompts
 *
 * Design notes:
 *  - Keys are lowercase (with/without accents for common variants).
 *  - Multi-word keys are listed before single-word keys so longest match wins.
 *  - mapToCategory() sorts by key length desc before scanning.
 *  - No LLM dependency — 100% deterministic.
 */
class FinancialMappingEngine
{
    private const MAP = [
        // ── TRANSPORTE ──────────────────────────────────────────────────────
        'transmilenio'           => 'Transporte',
        'sitp'                   => 'Transporte',
        'indriver'               => 'Transporte',
        'in driver'              => 'Transporte',
        'cabify'                 => 'Transporte',
        'didi'                   => 'Transporte',
        'uber'                   => 'Transporte',   // must come after 'uber eats' in sorted order
        'taxi'                   => 'Transporte',
        'gasolina'               => 'Transporte',
        'combustible'            => 'Transporte',
        'parqueadero'            => 'Transporte',
        'peaje'                  => 'Transporte',
        'pasaje'                 => 'Transporte',
        'pasajes'                => 'Transporte',
        'tiquete'                => 'Transporte',
        'tiquetes'               => 'Transporte',
        'vuelo'                  => 'Transporte',
        'avion'                  => 'Transporte',
        'avión'                  => 'Transporte',
        'bicicleta'              => 'Transporte',
        'moto'                   => 'Transporte',
        'petroleo'               => 'Transporte',   // for gas/fuel context

        // ── COMIDA (before generic 'cafe'/'uber' tokens) ────────────────────
        'uber eats'              => 'Comida',
        'rappi'                  => 'Comida',
        'domicilios.com'         => 'Comida',
        'domicilios'             => 'Comida',
        'juan valdez'            => 'Comida',
        'starbucks'              => 'Comida',
        'burger king'            => 'Comida',
        "mcdonald's"             => 'Comida',
        'mcdonalds'              => 'Comida',
        'pollo campero'          => 'Comida',
        'la brasa roja'          => 'Comida',
        'el corral'              => 'Comida',
        'frisby'                 => 'Comida',
        'subway'                 => 'Comida',
        'domino'                 => 'Comida',
        'restaurante'            => 'Comida',
        'almuerzo'               => 'Comida',
        'desayuno'               => 'Comida',
        'cena'                   => 'Comida',
        'comida'                 => 'Comida',
        'hamburgues'             => 'Comida',   // matches hamburguesa / hamburguesas
        'pizza'                  => 'Comida',
        'picada'                 => 'Comida',
        'asado'                  => 'Comida',
        'bandeja'                => 'Comida',
        'café'                   => 'Comida',
        'cafe'                   => 'Comida',
        'starbuck'               => 'Comida',
        'helado'                 => 'Comida',
        'torta'                  => 'Comida',
        'tamal'                  => 'Comida',
        'arepa'                  => 'Comida',
        'chicharron'             => 'Comida',
        'chicharrón'             => 'Comida',
        'snack'                  => 'Comida',
        'oma'                    => 'Comida',

        // ── MERCADO ─────────────────────────────────────────────────────────
        'supermercado'           => 'Mercado',
        'alkosto'                => 'Mercado',
        'carulla'                => 'Mercado',
        'olimpica'               => 'Mercado',
        'olímpica'               => 'Mercado',
        'jumbo'                  => 'Mercado',
        'éxito'                  => 'Mercado',
        'exito'                  => 'Mercado',
        'makro'                  => 'Mercado',
        'fruver'                 => 'Mercado',
        'carniceria'             => 'Mercado',
        'carnicería'             => 'Mercado',
        'panaderia'              => 'Mercado',
        'panadería'              => 'Mercado',
        'verduras'               => 'Mercado',
        'frutas'                 => 'Mercado',
        'mercado'                => 'Mercado',
        'tienda'                 => 'Mercado',
        'ara'                    => 'Mercado',
        'd1'                     => 'Mercado',

        // ── ENTRETENIMIENTO ──────────────────────────────────────────────────
        'youtube premium'        => 'Entretenimiento',
        'amazon prime'           => 'Entretenimiento',
        'prime video'            => 'Entretenimiento',
        'apple tv'               => 'Entretenimiento',
        'netflix'                => 'Entretenimiento',
        'spotify'                => 'Entretenimiento',
        'disney'                 => 'Entretenimiento',
        'hbo'                    => 'Entretenimiento',
        'twitch'                 => 'Entretenimiento',
        'youtube'                => 'Entretenimiento',
        'steam'                  => 'Entretenimiento',
        'playstation'            => 'Entretenimiento',
        'nintendo'               => 'Entretenimiento',
        'xbox'                   => 'Entretenimiento',
        'videojuego'             => 'Entretenimiento',
        'videojuegos'            => 'Entretenimiento',
        'cine'                   => 'Entretenimiento',
        'teatro'                 => 'Entretenimiento',
        'concierto'              => 'Entretenimiento',
        'pelicula'               => 'Entretenimiento',
        'película'               => 'Entretenimiento',
        'serie'                  => 'Entretenimiento',
        'parque'                 => 'Entretenimiento',
        'juego'                  => 'Entretenimiento',
        'deezer'                 => 'Entretenimiento',

        // ── VIVIENDA ─────────────────────────────────────────────────────────
        'arrendamiento'          => 'Vivienda',
        'administracion'         => 'Vivienda',
        'administración'         => 'Vivienda',
        'arriendo'               => 'Vivienda',
        'predial'                => 'Vivienda',
        'renta'                  => 'Vivienda',

        // ── SERVICIOS PÚBLICOS ───────────────────────────────────────────────
        'gas natural'            => 'Servicios',
        'plan datos'             => 'Servicios',
        'factura luz'            => 'Servicios',
        'factura agua'           => 'Servicios',
        'codensa'                => 'Servicios',
        'emcali'                 => 'Servicios',
        'triple a'               => 'Servicios',
        'epm'                    => 'Servicios',
        'acueducto'              => 'Servicios',
        'alcantarillado'         => 'Servicios',
        'movistar'               => 'Servicios',
        'claro'                  => 'Servicios',
        'tigo'                   => 'Servicios',
        'wom'                    => 'Servicios',
        'energia'                => 'Servicios',
        'energía'                => 'Servicios',
        'internet'               => 'Servicios',
        'celular'                => 'Servicios',
        'luz'                    => 'Servicios',
        'agua'                   => 'Servicios',
        'gas'                    => 'Servicios',

        // ── SALUD ─────────────────────────────────────────────────────────────
        'drogas la rebaja'       => 'Salud',
        'cruz verde'             => 'Salud',
        'consulta medica'        => 'Salud',
        'cita medica'            => 'Salud',
        'cita médica'            => 'Salud',
        'colsubsidio'            => 'Salud',
        'compensar'              => 'Salud',
        'cafam'                  => 'Salud',
        'sura'                   => 'Salud',
        'drogueria'              => 'Salud',
        'droguería'              => 'Salud',
        'farmacia'               => 'Salud',
        'medicamento'            => 'Salud',
        'medicina'               => 'Salud',
        'médico'                 => 'Salud',
        'medico'                 => 'Salud',
        'doctor'                 => 'Salud',
        'clinica'                => 'Salud',
        'clínica'                => 'Salud',
        'hospital'               => 'Salud',
        'pastilla'               => 'Salud',
        'examen'                 => 'Salud',
        'eps'                    => 'Salud',

        // ── EDUCACIÓN ────────────────────────────────────────────────────────
        'pension colegio'        => 'Educación',
        'pensión colegio'        => 'Educación',
        'capacitacion'           => 'Educación',
        'capacitación'           => 'Educación',
        'universidad'            => 'Educación',
        'matricula'              => 'Educación',
        'matrícula'              => 'Educación',
        'colegio'                => 'Educación',
        'escuela'                => 'Educación',
        'platzi'                 => 'Educación',
        'udemy'                  => 'Educación',
        'coursera'               => 'Educación',
        'curso'                  => 'Educación',
        'libro'                  => 'Educación',

        // ── ROPA / MODA ──────────────────────────────────────────────────────
        'studio f'               => 'Ropa',
        'zapatillas'             => 'Ropa',
        'calzado'                => 'Ropa',
        'zapatos'                => 'Ropa',
        'pantalon'               => 'Ropa',
        'camisa'                 => 'Ropa',
        'vestido'                => 'Ropa',
        'adidas'                 => 'Ropa',
        'nike'                   => 'Ropa',
        'puma'                   => 'Ropa',
        'koaj'                   => 'Ropa',
        'tennis'                 => 'Ropa',
        'zara'                   => 'Ropa',
        'h&m'                    => 'Ropa',
        'ropa'                   => 'Ropa',

        // ── DEPORTE / BIENESTAR ──────────────────────────────────────────────
        'smartfit'               => 'Deporte',
        'bodytech'               => 'Deporte',
        'gimnasio'               => 'Deporte',
        'gym'                    => 'Deporte',
        'yoga'                   => 'Deporte',
        'futbol'                 => 'Deporte',
        'fútbol'                 => 'Deporte',
        'baloncesto'             => 'Deporte',
        'deporte'                => 'Deporte',

        // ── FINANZAS / BANCO ─────────────────────────────────────────────────
        'banco bogota'           => 'Finanzas',
        'banco de bogotá'        => 'Finanzas',
        'cuota credito'          => 'Finanzas',
        'cuota crédito'          => 'Finanzas',
        'bancolombia'            => 'Finanzas',
        'davivienda'             => 'Finanzas',
        'daviplata'              => 'Finanzas',
        'transfiya'              => 'Finanzas',
        'nequi'                  => 'Finanzas',
        'bbva'                   => 'Finanzas',
        'inversion'              => 'Finanzas',
        'inversión'              => 'Finanzas',
        'prestamo'               => 'Finanzas',
        'préstamo'               => 'Finanzas',
        'credito'                => 'Finanzas',
        'crédito'                => 'Finanzas',
        'seguro'                 => 'Finanzas',
        'deuda'                  => 'Finanzas',
        'ahorro'                 => 'Finanzas',
        'banco'                  => 'Finanzas',

        // ── INGRESOS ─────────────────────────────────────────────────────────
        'transferencia recibida' => 'Ingresos',
        'pago recibido'          => 'Ingresos',
        'honorarios'             => 'Ingresos',
        'freelance'              => 'Ingresos',
        'salario'                => 'Ingresos',
        'sueldo'                 => 'Ingresos',
        'quincena'               => 'Ingresos',
        'nomina'                 => 'Ingresos',
        'nómina'                 => 'Ingresos',
    ];

    /** Keys sorted longest-first; built once on first call. */
    private static ?array $_sorted = null;

    private static function sorted(): array
    {
        if (self::$_sorted === null) {
            $keys = array_keys(self::MAP);
            usort($keys, static fn($a, $b) => strlen($b) - strlen($a));
            self::$_sorted = $keys;
        }
        return self::$_sorted;
    }

    /**
     * Returns the canonical category for $text, or null if no match.
     *
     * Scans with longest-key-first so "uber eats" → Comida beats "uber" → Transporte.
     */
    public static function mapToCategory(string $text): ?string
    {
        $lower = mb_strtolower(trim($text));
        foreach (self::sorted() as $key) {
            if (mb_strpos($lower, $key) !== false) {
                return self::MAP[$key];
            }
        }
        return null;
    }

    /**
     * Returns the category if found, otherwise $default.
     * Drop-in replacement for hardcoded 'General' / 'Otros' fallbacks.
     */
    public static function enrichTag(string $text, string $default = 'General'): string
    {
        return self::mapToCategory($text) ?? $default;
    }

    /**
     * Classifies an array of descriptions.
     *
     * @param  string[] $descriptions  Indexed array of movement descriptions
     * @return array<int, string>      Map of index → category (only matched ones)
     */
    public static function classifyMovements(array $descriptions): array
    {
        $result = [];
        foreach ($descriptions as $i => $desc) {
            $cat = self::mapToCategory((string) $desc);
            if ($cat !== null) {
                $result[$i] = $cat;
            }
        }
        return $result;
    }

    /**
     * Short, dense hint for inclusion in LLM prompts (≤ 3 lines).
     * Tells the model the merchant→category rules so it never invents categories.
     */
    public static function getPromptHint(): string
    {
        return
            "Merchant→category rules (use EXACTLY these names, never 'Otros'/'Other'):\n"
          . "Uber/Didi/taxi/TransMilenio/bus/gasolina → Transporte\n"
          . "D1/Éxito/Jumbo/Carulla/Ara/supermercado → Mercado\n"
          . "Rappi/restaurante/almuerzo/café/McDonald's → Comida\n"
          . "Netflix/Spotify/Disney/cine/videojuego → Entretenimiento\n"
          . "arriendo/administración/predial → Vivienda\n"
          . "luz/agua/internet/Claro/Movistar/EPM/gas → Servicios\n"
          . "farmacia/médico/EPS/hospital/droguería → Salud\n"
          . "universidad/colegio/curso/Platzi → Educación\n"
          . "ropa/zapatos/Zara/Nike/Adidas → Ropa\n"
          . "gimnasio/gym/deporte/SmartFit → Deporte\n"
          . "banco/crédito/préstamo/Nequi/ahorro → Finanzas";
    }

    /** All unique canonical category names in the engine. */
    public static function getCategories(): array
    {
        return array_unique(array_values(self::MAP));
    }
}
