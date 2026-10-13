<?php

use Database\Seeders\TypesBoutiqueSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reprise unique : les types créés par la plateforme avant la gestion des types (codes par défaut, jamais modifiés
 * depuis : aucune version) sont marqués « par défaut » et reçoivent leur unité de loyer. Libellé, description,
 * attribution et activation ne sont pas touchés. Le seeder, lui, ne modifie jamais un type existant.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (TypesBoutiqueSeeder::TYPES as [$code, , $unite]) {
            DB::table('types_boutiques')->where('code', $code)->where('version', 0)->where('par_defaut', false)
                ->update(['par_defaut' => true, 'unite_loyer' => $unite->value]);
        }
    }

    public function down(): void {}
};
