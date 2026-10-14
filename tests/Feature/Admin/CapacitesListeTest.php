<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutSite;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use Database\Seeders\ReferentielsSitesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/** Onglet Capacités prévues : tableau filtrable par colonne, boutons voir, modifier, supprimer, ajout. */
class CapacitesListeTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private User $gestionnaire;

    private TypeBoutique $type;

    private Site $brouillon;

    private Site $valide;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
        $this->seed(ReferentielsSitesSeeder::class);
        $this->gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->type = TypeBoutique::query()->where('code', 'BOUT')->first() ?? TypeBoutique::create(['code' => 'BOUT', 'libelle' => 'Boutique']);
        $site = fn (string $code, string $nom, StatutSite $statut) => Site::create(['statut' => $statut, 'code' => $code, 'nom' => $nom, 'region' => 'Centre',
            'commune' => 'Ouagadougou', 'adresse' => 'Avenue', 'version' => 1, 'cree_par' => $this->gestionnaire->id]);
        $this->brouillon = $site('RAA', 'Marché Raaga', StatutSite::Brouillon);
        $this->valide = $site('SAR', 'Marché de Sarfalao', StatutSite::Confirme);
        $this->valide->capacites()->create(['niveau' => 'R1', 'zone' => 'A', 'type_boutique_id' => $this->type->id, 'nombre_prevu' => 40, 'surface_unitaire' => 9]);
    }

    private function saisie(array $champs = []): array
    {
        return ['site_id' => $this->brouillon->id, 'niveau' => 'RC', 'zone' => 'A', 'type_boutique_id' => $this->type->id, 'nombre_prevu' => 20, 'surface_unitaire' => 8.5, ...$champs];
    }

    public function test_tableau_filtrable_avec_boutons_d_action(): void
    {
        $figee = $this->valide->capacites()->sole();
        $page = $this->en($this->gestionnaire)->get(route('admin.referencement.capacites', ['site' => $this->valide->id]))->assertOk();
        $page->assertSee('Toutes les capacités prévues', false)->assertSee('Filtrer la colonne « Site »', false)->assertSee('Filtrer la colonne « Source »', false)
            ->assertSee('data-site="SAR · Marché de Sarfalao"', false)->assertSee('JSON.parse(\'{\u0022site\u0022:\u0022SAR', false)
            ->assertSee(route('admin.referencement.boutiques', ['site' => $this->valide->id, 'niveau' => 'R1', 'zone' => 'A', 'type' => $this->type->id]))
            ->assertDontSee(route('admin.referencement.capacites.edit', $figee))->assertSee('Impossible : site soumis ou validé', false)
            ->assertSee('aria-label="Ajouter une capacité"', false);

        // Les autres profils consultent : bouton voir seulement.
        $this->en($this->validateur)->get(route('admin.referencement.capacites'))->assertOk()
            ->assertSee('Voir les boutiques correspondantes')->assertDontSee('aria-label="Supprimer', false)->assertDontSee('aria-label="Ajouter une capacité"', false);
        $this->en($this->validateur)->post(route('admin.referencement.capacites.store'), $this->saisie())->assertForbidden();
        $this->en($this->validateur)->delete(route('admin.referencement.capacites.destroy', $figee))->assertForbidden();
    }

    public function test_ajout_modification_et_suppression_sur_un_site_en_brouillon(): void
    {
        $this->en($this->gestionnaire)->post(route('admin.referencement.capacites.store'), $this->saisie())
            ->assertRedirect(route('admin.referencement.capacites', ['site' => $this->brouillon->id]))->assertSessionHas('succes');
        $capacite = $this->brouillon->capacites()->sole();
        $this->assertSame([20, 'RC', 'A'], [$capacite->nombre_prevu, $capacite->niveau, $capacite->zone]);

        // Doublon (même site, niveau, zone et type), zone inconnue, surface négative : refusés.
        $this->en($this->gestionnaire)->post(route('admin.referencement.capacites.store'), $this->saisie())->assertSessionHasErrors('type_boutique_id');
        $this->en($this->gestionnaire)->post(route('admin.referencement.capacites.store'), $this->saisie(['zone' => 'ZZ', 'niveau' => 'R2']))->assertSessionHasErrors('zone');
        $this->en($this->gestionnaire)->post(route('admin.referencement.capacites.store'), $this->saisie(['niveau' => 'R2', 'surface_unitaire' => -1]))->assertSessionHasErrors('surface_unitaire');
        // Site validé : ajout refusé.
        $this->en($this->gestionnaire)->post(route('admin.referencement.capacites.store'), $this->saisie(['site_id' => $this->valide->id]))->assertSessionHasErrors('site_id');

        $this->en($this->gestionnaire)->get(route('admin.referencement.capacites.edit', $capacite))->assertOk()->assertSee('Modifier la capacité');
        $this->en($this->gestionnaire)->put(route('admin.referencement.capacites.update', $capacite), ['niveau' => 'RC', 'zone' => 'A', 'type_boutique_id' => $this->type->id,
            'surface_totale' => 300])->assertSessionHas('succes');
        $this->assertSame([null, 300.0], [$capacite->fresh()->nombre_prevu, $capacite->fresh()->surfaceRetenue()]);

        $this->en($this->gestionnaire)->delete(route('admin.referencement.capacites.destroy', $capacite))->assertSessionHas('succes');
        $this->assertModelMissing($capacite);
    }

    public function test_capacite_d_un_site_valide_ni_modifiee_ni_supprimee(): void
    {
        $figee = $this->valide->capacites()->sole();
        $this->en($this->gestionnaire)->get(route('admin.referencement.capacites.edit', $figee))->assertSessionHas('erreur');
        $this->en($this->gestionnaire)->put(route('admin.referencement.capacites.update', $figee), ['type_boutique_id' => $this->type->id, 'nombre_prevu' => 1])->assertSessionHas('erreur');
        $this->en($this->gestionnaire)->delete(route('admin.referencement.capacites.destroy', $figee))->assertSessionHas('erreur');
        $this->assertSame(40, $figee->fresh()->nombre_prevu);
    }
}
