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

/** Formulaires courts du back-office en vraies fenêtres modales (<dialog> + showModal), envoyés sans rechargement. */
class FenetresModalesTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private User $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
        $this->gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
    }

    public function test_le_bouton_ouvre_un_dialog_modal(): void
    {
        $page = $this->en($this->gestionnaire)->get(route('admin.sites.types'))->assertOk();
        // Bouton d'ouverture, fenêtre <dialog> hors du flux, en-tête (titre + Fermer), corps, pied (Annuler + action).
        $page->assertSee('id="ajouter-type-ouvrir" x-on:click="ouvrir($event)" aria-haspopup="dialog" aria-controls="ajouter-type"', false)
            ->assertSee('<dialog id="ajouter-type" x-ref="dialogue" class="adm-fenetre" aria-labelledby="ajouter-type-titre" aria-modal="true"', false)
            ->assertSeeInOrder(['class="adm-fenetre-tete"', 'id="ajouter-type-titre"', 'aria-label="Fermer"', 'class="adm-fenetre-corps"', 'name="code"', 'class="adm-fenetre-pied"', 'Annuler', 'Ajouter le type'], false);

        // Le script ouvre par showModal() (jamais show()) ; plus aucun formulaire dépliable dans le back-office.
        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('dialogue.showModal()', $script);
        $this->assertDoesNotMatchRegularExpression('/dialog(ue)?\.show\(\)/', $script);
        foreach (glob(resource_path('views/admin/{,*/}*.blade.php'), GLOB_BRACE) as $vue) {
            if (! str_contains($vue, '_capacites')) {
                $this->assertStringNotContainsString('<details', file_get_contents($vue), $vue);
            }
        }

        // Ligne de tableau : « Modifier » charge la fenêtre depuis le serveur (en-tête X-Fenetre).
        $type = TypeBoutique::create(['code' => 'BOUT', 'libelle' => 'Boutique']);
        $site = Site::create(['statut' => StatutSite::Brouillon, 'code' => 'RAA', 'nom' => 'Raaga', 'cree_par' => $this->gestionnaire->id]);
        $boutique = $site->boutiques()->create(['niveau' => 'R1', 'bloc' => 'A', 'numero' => '001', 'type_boutique_id' => $type->id, 'statut' => StatutBoutique::Disponible]);
        $this->en($this->gestionnaire)->get(route('admin.referencement.boutiques'))
            ->assertSee('data-fenetre-url="'.route('admin.referencement.boutiques.edit', $boutique).'"', false);
        $this->en($this->gestionnaire)->withHeader('X-Fenetre', '1')->get(route('admin.referencement.boutiques.edit', $boutique))->assertOk()
            ->assertSee('<dialog id="modifier-boutique-'.$boutique->id.'"', false)->assertSee('data-ouvrir', false)->assertDontSee('<html', false);
    }

    public function test_erreurs_de_validation_sans_fermeture(): void
    {
        // Envoi par la fenêtre (fetch, JSON attendu) : 422 et erreurs par champ ; rien n'est créé.
        $this->en($this->gestionnaire)->postJson(route('admin.sites.types.store'), ['_fenetre' => 'ajouter-type', 'code' => 'x', 'attribuable' => 1])
            ->assertStatus(422)->assertJsonValidationErrors(['code', 'libelle']);
        $this->assertSame(0, TypeBoutique::query()->count());

        // Sans JavaScript : retour sur la liste avec la même fenêtre rouverte et les erreurs sous les champs.
        $this->en($this->gestionnaire)->from(route('admin.sites.types'))->post(route('admin.sites.types.store'), ['_fenetre' => 'ajouter-type', 'code' => 'x', 'attribuable' => 1])
            ->assertRedirect(route('admin.sites.types'));
        $this->en($this->gestionnaire)->from(route('admin.sites.types'))->followingRedirects()
            ->post(route('admin.sites.types.store'), ['_fenetre' => 'ajouter-type', 'code' => 'x', 'attribuable' => 1])
            ->assertSee('data-ouvrir', false)->assertSee('aria-invalid="true"', false)->assertSee('value="X"', false);
    }

    public function test_succes_qui_met_a_jour_la_ligne(): void
    {
        $type = TypeBoutique::create(['code' => 'KIOS', 'libelle' => 'Kiosque', 'ordre' => 5]);

        // La fenêtre envoie en JSON ; la redirection est suivie et la page renvoyée remplace le contenu courant.
        $page = $this->en($this->gestionnaire)->followingRedirects()->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->put(route('admin.sites.types.update', $type), ['_fenetre' => 'modifier-type', 'libelle' => 'Kiosque couvert', 'ordre' => 5])->assertOk();
        $page->assertSee('id="contenu"', false)->assertSee('Kiosque couvert')->assertSee(__('admin.types.messages.modifie'));
        $this->assertSame('Kiosque couvert', $type->fresh()->libelle);

        // Ligne de la liste mise à jour.
        $this->en($this->gestionnaire)->get(route('admin.sites.types'))->assertSee('Kiosque couvert')->assertDontSee('>Kiosque</a>', false);
    }

    public function test_403_pour_un_profil_non_autorise(): void
    {
        $type = TypeBoutique::create(['code' => 'KIOS', 'libelle' => 'Kiosque', 'ordre' => 5]);
        foreach ([$this->validateur, $this->regulateur] as $profil) {
            $this->en($profil)->postJson(route('admin.sites.types.store'), ['code' => 'NEW', 'libelle' => 'Nouveau', 'attribuable' => 1])->assertForbidden();
            $this->en($profil)->putJson(route('admin.sites.types.update', $type), ['libelle' => 'X', 'ordre' => 1])->assertForbidden();
            $this->en($profil)->get(route('admin.sites.types'))->assertDontSee('<dialog id="ajouter-type"', false);
        }
        $this->assertSame('Kiosque', $type->fresh()->libelle);
    }
}
