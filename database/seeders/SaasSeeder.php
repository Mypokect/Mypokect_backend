<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Siembra planes (estrategia de pricing del documento de arquitectura) y roles base.
 * Precios en price_cents = pesos * 100 (COP no usa centavos reales).
 */
class SaasSeeder extends Seeder
{
    public function run(): void
    {
        // --- ROLES ---
        foreach (['super-admin', 'admin', 'support', 'finance', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        // --- PLANES ---
        Plan::updateOrCreate(['code' => 'free'], [
            'name'        => 'Gratis',
            'description' => 'Registro manual ilimitado y funciones básicas.',
            'price_cents' => 0,
            'currency'    => 'COP',
            'interval'    => 'none',
            'trial_days'  => 0,
            'features'    => [
                'voice_ai_per_month' => 15,
                'ai_budgets'         => 1,
                'saving_goals'       => 1,
                'tax_radar'          => 'basic', // solo semáforo
            ],
            'is_active'   => true,
            'sort_order'  => 1,
        ]);

        Plan::updateOrCreate(['code' => 'pro_monthly'], [
            'name'        => 'Pro Mensual',
            'description' => 'Todas las funciones con IA, mes a mes.',
            'price_cents' => 8900 * 100, // $8.900 COP
            'currency'    => 'COP',
            'interval'    => 'month',
            'trial_days'  => 14,
            'features'    => [
                'voice_ai_per_month' => -1, // ilimitado
                'ai_budgets'         => -1,
                'saving_goals'       => -1,
                'tax_radar'          => 'full',
            ],
            'is_active'   => true,
            'sort_order'  => 2,
        ]);

        Plan::updateOrCreate(['code' => 'pro_annual'], [
            'name'        => 'Pro Anual',
            'description' => 'Todas las funciones con IA. Ahorra ~34% pagando al año.',
            'price_cents' => 69000 * 100, // $69.000 COP/año (~$5.750/mes equiv.)
            'currency'    => 'COP',
            'interval'    => 'year',
            'trial_days'  => 14,
            'features'    => [
                'voice_ai_per_month' => -1,
                'ai_budgets'         => -1,
                'saving_goals'       => -1,
                'tax_radar'          => 'full',
            ],
            'is_active'   => true,
            'sort_order'  => 3,
        ]);
    }
}
