<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class JournalAuditTest extends TestCase
{
    use RefreshDatabase;

    private function entree(): AuditLog
    {
        return AuditLog::create([
            'action' => 'tranche.creee',
            'entite_type' => 'Tranche',
            'entite_id' => '1',
            'nouvelle_valeur' => ['nom' => 'Tranche 1 – 2026'],
        ]);
    }

    public function test_une_entree_ne_peut_pas_etre_modifiee_via_eloquent(): void
    {
        $entree = $this->entree();

        $this->expectException(LogicException::class);

        $entree->update(['action' => 'falsifiee']);
    }

    public function test_une_entree_ne_peut_pas_etre_supprimee_via_eloquent(): void
    {
        $entree = $this->entree();

        $this->expectException(LogicException::class);

        $entree->delete();
    }

    public function test_la_base_refuse_la_modification_directe(): void
    {
        $this->entree();

        $this->expectException(QueryException::class);

        DB::table('audit_logs')->update(['action' => 'falsifiee']);
    }

    public function test_la_base_refuse_la_suppression_directe(): void
    {
        $this->entree();

        $this->expectException(QueryException::class);

        DB::table('audit_logs')->delete();
    }

    public function test_les_tables_de_preuve_sont_toutes_protegees(): void
    {
        $tables = collect(DB::select('SHOW TRIGGERS'))->pluck('Table')->unique()->sort()->values()->all();

        $this->assertSame(['acceptations', 'audit_logs', 'convention_versions', 'distribution_versions', 'lignes_registre', 'lignes_registre_a', 'parametrage_decisions', 'parametres_plateforme_versions', 'tranche_versions'], $tables);
    }
}
