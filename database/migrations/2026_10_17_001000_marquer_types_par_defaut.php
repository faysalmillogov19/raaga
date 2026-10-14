<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reprise unique : les types créés par la plateforme avant la gestion des types (codes par défaut, jamais modifiés
 * depuis : aucune version) sont marqués « par défaut » et reçoivent leur unité de loyer. Libellé, description,
 * attribution et activation ne sont pas touchés. (L'unité a depuis rejoint les lignes de grille tarifaire.)
 */
return new class extends Migration
{
    /** Codes par défaut et unité du loyer à cette date (liste figée, indépendante du seeder actuel). */
    private const UNITES = ['BOUT' => 'm2', 'MAG' => 'm2', 'ETAL' => 'unite', 'POSTE' => 'unite', 'PV' => 'm2', 'PP' => 'm2', 'PF' => 'm2', 'REST' => 'm2', 'CF' => 'redevance'];

    public function up(): void
    {
        foreach (self::UNITES as $code => $unite) {
            DB::table('types_boutiques')->where('code', $code)->where('version', 0)->where('par_defaut', false)
                ->update(['par_defaut' => true, 'unite_loyer' => $unite]);
        }
    }

    public function down(): void {}
};
