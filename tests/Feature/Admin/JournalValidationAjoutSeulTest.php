<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\ParametrageDecision;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/**
 * Journal de validation en ajout seul, protégé en base par des triggers PostgreSQL : aucune décision, version ni
 * entrée du journal d'audit ne peut être modifiée ou supprimée, même par une requête SQL directe.
 */
class JournalValidationAjoutSeulTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private const TABLES = ['parametrage_decisions', 'site_versions', 'audit_logs'];

    public function test_le_journal_de_validation_refuse_modification_et_suppression(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->creerActeurs();
        $gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->en($gestionnaire)->post(route('admin.sites.types.store'), ['code' => 'BOUT', 'libelle' => 'Petite boutique', 'attribuable' => 1]);
        $this->en($this->validateur)->post(route('admin.sites.types.confirmer', TypeBoutique::query()->sole()))->assertSessionHas('succes');
        $this->en($gestionnaire)->post(route('admin.sites.store'), ['code' => 'SAR', 'nom' => 'Marché SAR', 'region' => 'Centre', 'ville' => 'Ouagadougou']);
        $site = Site::query()->where('code', 'SAR')->sole();
        $this->en($gestionnaire)->post(route('admin.referencement.boutiques.store', $site), [
            'niveau' => 'R1', 'bloc' => 'A', 'numero' => 1, 'surface' => 9, 'type_boutique_id' => TypeBoutique::query()->value('id'), 'etat_physique' => 'LIVREE',
        ]);
        $this->en($gestionnaire)->post(route('admin.sites.soumettre', $site))->assertSessionHas('succes');
        $this->en($this->validateur)->post(route('admin.sites.rejeter', $site), ['motif' => 'Adresse à préciser'])->assertSessionHas('succes');
        $decision = ParametrageDecision::query()->sole();

        foreach (self::TABLES as $table) {
            $avant = DB::table($table)->count();
            $this->assertGreaterThan(0, $avant, $table);
            $id = DB::table($table)->min('id');
            foreach (['modification' => fn () => DB::table($table)->where('id', $id)->update(['created_at' => now()->subYear()]),
                'suppression' => fn () => DB::table($table)->where('id', $id)->delete(),
                'suppression totale' => fn () => DB::table($table)->delete()] as $operation => $requete) {
                try {
                    // Point de sauvegarde : l'échec du trigger n'interrompt pas la transaction du test.
                    DB::transaction($requete);
                    $this->fail($table.' : '.$operation.' acceptée.');
                } catch (QueryException $e) {
                    $this->assertStringContainsString('ajout seul', $e->getMessage(), $table.' : '.$operation);
                }
            }
            $this->assertSame($avant, DB::table($table)->count(), $table);
        }

        // Par le modèle aussi : refusé avant même d'atteindre la base ; la décision reste telle qu'elle a été écrite.
        foreach (['modification interdite' => fn () => $decision->forceFill(['motif' => 'Motif réécrit'])->save(),
            'suppression interdite' => fn () => $decision->delete()] as $message => $action) {
            try {
                DB::transaction($action);
                $this->fail('ParametrageDecision : '.$message.' non appliquée.');
            } catch (LogicException $e) {
                $this->assertSame('ParametrageDecision est en ajout seul : '.$message.'.', $e->getMessage());
            }
        }
        $this->assertSame('Adresse à préciser', $decision->fresh()->motif);
    }
}
