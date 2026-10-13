<?php

namespace Tests\Feature\Admin;

use App\Enums\NiveauBoutique;
use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use App\Models\Boutique;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use App\Services\Backoffice\RegleGestionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

class CodeBoutiquesTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private User $gestionnaire;

    private Site $site;

    private TypeBoutique $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
        $this->gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->type = TypeBoutique::create(['code' => 'BOUT', 'libelle' => 'Boutique']);
        $this->site = Site::create([
            'statut' => StatutSite::Brouillon, 'code' => 'RAA', 'nom' => 'Marché Raaga', 'region' => 'Centre',
            'commune' => 'Ouagadougou', 'adresse' => 'Avenue de la Nation', 'cree_par' => $this->gestionnaire->id,
        ]);
    }

    private function ajouter(array $champs = []): TestResponse
    {
        return $this->en($this->gestionnaire)->post(route('admin.sites.boutiques.store', $this->site), [
            'niveau' => 'R1', 'bloc' => 'a', 'numero' => 45, 'type_boutique_id' => $this->type->id, 'etat_physique' => 'LIVREE', ...$champs,
        ]);
    }

    public function test_le_code_est_calcule_a_la_creation_et_unique(): void
    {
        $this->ajouter()->assertSessionHas('succes');
        $this->assertSame(['RAA-R1-A-045'], Boutique::query()->pluck('code')->all());

        // Même site, niveau, bloc et numéro : refusé.
        $this->ajouter()->assertSessionHas('erreur');
        $this->assertSame(1, Boutique::query()->count());

        // Une série qui recouvre un code existant est refusée en entier.
        $this->en($this->gestionnaire)->post(route('admin.sites.boutiques.serie', $this->site), [
            'niveau' => 'R1', 'bloc' => 'A', 'debut' => 40, 'fin' => 50, 'type_boutique_id' => $this->type->id, 'etat_physique' => 'LIVREE',
        ])->assertSessionHasErrors('debut');
        $this->assertSame(1, Boutique::query()->count());

        // Même numéro dans un autre bloc ou à un autre niveau : codes distincts.
        $this->ajouter(['bloc' => 'B'])->assertSessionHas('succes');
        $this->ajouter(['niveau' => 'RC'])->assertSessionHas('succes');
        $this->assertSame(['RAA-R1-A-045', 'RAA-R1-B-045', 'RAA-RC-A-045'], Boutique::query()->orderBy('code')->pluck('code')->all());
    }

    public function test_le_code_n_est_jamais_modifie(): void
    {
        $this->ajouter()->assertSessionHas('succes');
        $boutique = Boutique::query()->sole();

        // La fiche se modifie, pas les éléments du code (ignorés par le formulaire).
        $this->en($this->gestionnaire)->put(route('admin.sites.boutiques.update', [$this->site, $boutique]), [
            'niveau' => 'R2', 'bloc' => 'Z', 'numero' => 9, 'type_boutique_id' => $this->type->id, 'etat_physique' => 'EN_TRAVAUX', 'coffret' => 'C-12',
        ])->assertSessionHas('succes');
        $boutique->refresh();
        $this->assertSame('RAA-R1-A-045', $boutique->code);
        $this->assertSame(NiveauBoutique::R1, $boutique->niveau);
        $this->assertSame('C-12', $boutique->coffret);

        // Au niveau du modèle aussi.
        foreach ([['code' => 'RAA-R1-A-046'], ['niveau' => NiveauBoutique::R2], ['bloc' => 'B'], ['numero' => '046']] as $changement) {
            try {
                $boutique->fresh()->forceFill($changement)->save();
                $this->fail('Le code ou un de ses éléments a pu être modifié.');
            } catch (RegleGestionException) {
            }
        }
        $this->assertSame('RAA-R1-A-045', $boutique->fresh()->code);

        // Le code d'un site soumis (ou confirmé) est figé lui aussi.
        $this->site->forceFill(['statut' => StatutSite::Soumis])->save();
        $this->en($this->gestionnaire)->put(route('admin.sites.update', $this->site), [
            'code' => 'SAR', 'nom' => 'Marché Raaga', 'region' => 'Centre', 'ville' => 'Ouagadougou', 'adresse' => 'Avenue de la Nation',
        ])->assertSessionHas('erreur');
        $this->assertSame('RAA', $this->site->fresh()->code);
    }

    public function test_le_code_d_une_boutique_supprimee_n_est_jamais_reutilise(): void
    {
        $this->ajouter()->assertSessionHas('succes');
        $boutique = Boutique::query()->sole();

        $this->en($this->gestionnaire)->delete(route('admin.sites.boutiques.destroy', [$this->site, $boutique]))->assertSessionHas('succes');
        $this->assertSoftDeleted($boutique);
        $this->assertSame('RAA-R1-A-045', Boutique::withTrashed()->sole()->code);

        $this->ajouter()->assertSessionHas('erreur');
        $this->en($this->gestionnaire)->post(route('admin.sites.boutiques.serie', $this->site), [
            'niveau' => 'R1', 'bloc' => 'A', 'debut' => 44, 'fin' => 46, 'type_boutique_id' => $this->type->id, 'etat_physique' => 'LIVREE',
        ])->assertSessionHasErrors('debut');
        $this->assertSame(0, Boutique::query()->count());
    }

    public function test_un_site_avec_une_boutique_sans_niveau_ne_peut_pas_etre_soumis(): void
    {
        // Boutique saisie avant les codes : pas de niveau, donc pas de code.
        $ancienne = $this->site->boutiques()->create(['numero' => 'A-001', 'bloc' => 'A', 'type_boutique_id' => $this->type->id, 'statut' => StatutBoutique::Disponible]);
        $this->assertNull($ancienne->code);

        $this->en($this->gestionnaire)->post(route('admin.sites.soumettre', $this->site))->assertSessionHas('erreur', fn ($m) => str_contains($m, 'niveau'));
        $this->assertSame(StatutSite::Brouillon, $this->site->fresh()->statut);

        // Fiche complétée : le code est calculé, le site peut être soumis.
        $this->en($this->gestionnaire)->put(route('admin.sites.boutiques.update', [$this->site, $ancienne]), [
            'niveau' => 'RC', 'bloc' => 'A', 'numero' => 1, 'type_boutique_id' => $this->type->id, 'etat_physique' => 'LIVREE',
        ])->assertSessionHas('succes');
        $this->assertSame('RAA-RC-A-001', $ancienne->fresh()->code);

        $this->en($this->gestionnaire)->post(route('admin.sites.soumettre', $this->site))->assertSessionHas('succes');
        $this->assertSame(StatutSite::Soumis, $this->site->fresh()->statut);
    }
}
