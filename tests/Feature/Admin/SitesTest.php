<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

class SitesTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private User $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
        $this->gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
    }

    private function siteAvecBoutiques(): Site
    {
        // Types ajoutés par le gestionnaire, utilisables après validation.
        $this->en($this->gestionnaire)->post(route('admin.sites.types.store'), ['code' => 'BOUT', 'libelle' => 'Petite boutique', 'description' => '9 m²', 'attribuable' => 1])->assertSessionHas('succes');
        $this->en($this->gestionnaire)->post(route('admin.sites.types.store'), ['code' => 'ETAL', 'libelle' => 'Kiosque', 'attribuable' => 1]);
        foreach (TypeBoutique::all() as $type) {
            $this->en($this->validateur)->post(route('admin.sites.types.confirmer', $type))->assertSessionHas('succes');
        }
        $petite = TypeBoutique::query()->where('libelle', 'Petite boutique')->value('id');

        $this->en($this->gestionnaire)->post(route('admin.sites.store'), [
            'code' => 'RAA', 'nom' => 'Marché central de Ouagadougou', 'region' => 'Centre', 'ville' => 'Ouagadougou', 'adresse' => 'Avenue de la Nation',
        ])->assertSessionHasNoErrors();
        $site = Site::query()->sole();

        $this->en($this->gestionnaire)->post(route('admin.sites.boutiques.serie', $site), [
            'niveau' => 'R1', 'bloc' => 'A', 'debut' => 1, 'fin' => 40, 'surface' => 9, 'type_boutique_id' => $petite, 'etat_physique' => 'LIVREE',
        ])->assertSessionHasNoErrors();
        $this->en($this->gestionnaire)->post(route('admin.sites.boutiques.store', $site), [
            'niveau' => 'RC', 'bloc' => 'K', 'numero' => 1, 'type_boutique_id' => TypeBoutique::query()->where('libelle', 'Kiosque')->value('id'), 'etat_physique' => 'LIVREE',
        ])->assertSessionHasNoErrors();

        return $site->refresh();
    }

    public function test_le_gestionnaire_cree_un_site_et_ses_boutiques_puis_le_soumet(): void
    {
        $site = $this->siteAvecBoutiques();

        $this->assertSame(41, $site->boutiques()->count());
        $this->assertSame(['RAA-R1-A-001', 'RAA-R1-A-002'], $site->boutiques()->where('bloc', 'A')->limit(2)->pluck('code')->all());
        $this->assertSame('RAA-R1-A-040', $site->boutiques()->where('bloc', 'A')->get()->last()->code);
        $this->assertTrue($site->boutiques()->get()->every(fn ($b) => $b->statut === StatutBoutique::Disponible));

        // Une série qui recouvre des numéros existants est refusée.
        $this->en($this->gestionnaire)->post(route('admin.sites.boutiques.serie', $site), [
            'niveau' => 'R1', 'bloc' => 'A', 'debut' => 39, 'fin' => 45, 'type_boutique_id' => TypeBoutique::query()->value('id'), 'etat_physique' => 'LIVREE',
        ])->assertSessionHasErrors('debut');
        $this->assertSame(41, $site->boutiques()->count());

        $this->en($this->gestionnaire)->post(route('admin.sites.soumettre', $site))->assertSessionHas('succes');
        $site->refresh();
        $this->assertSame(StatutSite::Soumis, $site->statut);
        $this->assertStringContainsString('Petite boutique : 40 (RAA-R1-A-001 à RAA-R1-A-040)', $site->versions()->first()->parametres['boutiques']);

        // Le gestionnaire ne confirme jamais ; soumis, le site n'est plus modifiable.
        $this->en($this->gestionnaire)->post(route('admin.sites.confirmer', $site))->assertForbidden();
        $this->en($this->gestionnaire)->post(route('admin.sites.boutiques.store', $site), ['niveau' => 'RC', 'bloc' => 'X', 'numero' => 1, 'type_boutique_id' => TypeBoutique::query()->value('id'), 'etat_physique' => 'LIVREE'])
            ->assertSessionHas('erreur');
    }

    public function test_le_validateur_confirme_ou_rejette_et_le_site_peut_etre_remis_en_revision(): void
    {
        $site = $this->siteAvecBoutiques();
        $this->en($this->gestionnaire)->post(route('admin.sites.soumettre', $site));

        $this->en($this->validateur)->get(route('admin.sites.show', $site))->assertOk()->assertSee('RAA-R1-A-001')->assertSee('Soumis à confirmation');
        $this->en($this->validateur)->post(route('admin.sites.rejeter', $site), ['motif' => 'Surface manquante sur le kiosque'])->assertSessionHas('succes');
        $this->assertSame(StatutSite::Brouillon, $site->refresh()->statut);

        $this->en($this->gestionnaire)->post(route('admin.sites.soumettre', $site));
        $this->en($this->validateur)->post(route('admin.sites.confirmer', $site))->assertSessionHas('succes');
        $this->assertSame(StatutSite::Confirme, $site->refresh()->statut);
        $this->assertSame(2, $site->version);

        // Révision pour ajouter des boutiques ; une boutique engagée reste verrouillée.
        $engagee = $site->boutiques()->first();
        $engagee->forceFill(['statut' => StatutBoutique::EnCampagne])->save();
        $this->en($this->gestionnaire)->post(route('admin.sites.reviser', $site))->assertSessionHas('succes');
        $this->assertSame(StatutSite::Brouillon, $site->refresh()->statut);
        $this->en($this->gestionnaire)->delete(route('admin.sites.boutiques.destroy', [$site, $engagee]))->assertSessionHas('erreur');
        $this->assertModelExists($engagee);
        $libre = $site->boutiques()->where('statut', StatutBoutique::Disponible)->first();
        $this->en($this->gestionnaire)->delete(route('admin.sites.boutiques.destroy', [$site, $libre]))->assertSessionHas('succes');
        $this->assertSoftDeleted($libre);
    }

    public function test_les_autres_profils_consultent_sans_rien_modifier(): void
    {
        $site = $this->siteAvecBoutiques();

        // L'ADMIN (support) n'a pas accès au référencement.
        $this->en(User::factory()->role(Role::Admin)->create())->get(route('admin.sites'))->assertForbidden();

        foreach ([$this->regulateur, User::factory()->role(Role::SuperAdmin)->create()] as $profil) {
            $this->en($profil)->get(route('admin.sites'))->assertOk();
            $this->en($profil)->get(route('admin.sites.show', $site))->assertOk()->assertSee('RAA-R1-A-001');
            $this->en($profil)->get(route('admin.sites.create'))->assertForbidden();
            $this->en($profil)->post(route('admin.sites.boutiques.store', $site), ['numero' => 'Z-1'])->assertForbidden();
        }

        // Le gestionnaire des sites et boutiques ne touche ni aux campagnes, ni aux tranches.
        $this->en($this->gestionnaire)->get(route('admin.boutiques'))->assertForbidden();
        $this->en($this->gestionnaire)->get(route('admin.tranches'))->assertForbidden();
    }

    public function test_un_type_utilise_ne_peut_pas_etre_supprime(): void
    {
        $this->siteAvecBoutiques();
        $kiosque = TypeBoutique::query()->where('libelle', 'Kiosque')->firstOrFail();

        $this->en($this->gestionnaire)->delete(route('admin.sites.types.destroy', $kiosque))->assertSessionHas('erreur');
        $this->assertModelExists($kiosque);
    }
}
