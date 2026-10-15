<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutSite;
use App\Enums\StatutTypeBoutique;
use App\Models\AuditLog;
use App\Models\DecisionPhase;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\Unite;
use App\Models\User;
use App\Notifications\DecisionRendue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/** File « À valider » du VALIDATEUR : groupes, décision sur une ligne ou une sélection, demandes de modification. */
class AValiderTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private User $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
        $this->gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->en($this->gestionnaire)->post(route('admin.sites.types.store'), ['code' => 'BOUT', 'libelle' => 'Petite boutique', 'attribuable' => 1]);
        $this->en($this->validateur)->post(route('admin.sites.types.confirmer', TypeBoutique::query()->sole()))->assertSessionHas('succes');
    }

    /** Site avec une boutique, soumis au VALIDATEUR. */
    private function siteSoumis(string $code): Site
    {
        $this->en($this->gestionnaire)->post(route('admin.sites.store'), ['code' => $code, 'nom' => 'Marché '.$code, 'region' => 'Centre', 'ville' => 'Ouagadougou'])
            ->assertSessionHasNoErrors();
        $site = Site::query()->where('code', $code)->sole();
        $this->en($this->gestionnaire)->post(route('admin.referencement.boutiques.store', $site), [
            'niveau' => 'R1', 'bloc' => 'A', 'numero' => 1, 'surface' => 9, 'type_boutique_id' => TypeBoutique::query()->value('id'), 'etat_physique' => 'LIVREE',
        ])->assertSessionHasNoErrors();
        $this->en($this->gestionnaire)->post(route('admin.sites.soumettre', $site))->assertSessionHas('succes');

        return $site->refresh();
    }

    public function test_file_regroupee_et_reservee_au_validateur(): void
    {
        $this->siteSoumis('SAR');
        $this->siteSoumis('A04');
        $this->en($this->regulateur)->post(route('admin.referencement.zones.store', 'unites'), ['code' => 'FORFAIT', 'libelle' => 'Forfait mensuel'])->assertSessionHas('succes');

        $page = $this->en($this->validateur)->get(route('admin.a-valider'))->assertOk();
        $page->assertSeeInOrder(['Sites (2)', 'SAR · Marché SAR', 'A04 · Marché A04', 'Grilles tarifaires (0)', 'Référentiels : types, niveaux, zones, unités (1)', 'FORFAIT · Forfait mensuel',
            'Campagnes (0)', 'Démarches du site public (0)', 'Autorisations de modification de site (0)'], false)
            ->assertSee('Valider la sélection')->assertSee('Rejeter la sélection…', false)->assertSee('name="elements[]" value="site:', false);
        // Badge du menu : total de la file.
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('admin.a-valider'), '#').'"[^>]*>.*?<span class="adm-compteur">3<#s', $page->getContent());

        foreach ([$this->regulateur, $this->gestionnaire, $this->superAdmin] as $profil) {
            $this->en($profil)->get(route('admin.a-valider'))->assertForbidden();
        }
    }

    public function test_validation_et_rejet_groupes(): void
    {
        $sar = $this->siteSoumis('SAR');
        $a04 = $this->siteSoumis('A04');
        $this->en($this->regulateur)->post(route('admin.referencement.zones.store', 'unites'), ['code' => 'FORFAIT', 'libelle' => 'Forfait mensuel']);
        $unite = Unite::query()->where('code', 'FORFAIT')->sole();

        // Rejet groupé : motif obligatoire, puis le même motif pour chaque élément.
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['site:'.$a04->id, 'unites:'.$unite->id], 'decision' => 'rejeter'])
            ->assertSessionHasErrors('motif');
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['site:'.$a04->id, 'unites:'.$unite->id], 'decision' => 'rejeter', 'motif' => 'Plans à compléter'])
            ->assertRedirect(route('admin.a-valider'))->assertSessionHas('succes', '2 rejetés.');
        $this->assertSame('Plans à compléter', $a04->fresh()->motif_rejet);
        $this->assertSame(StatutTypeBoutique::Rejete, $unite->fresh()->statut);
        $this->assertSame('Plans à compléter', $unite->fresh()->motif_rejet);

        // Validation groupée (ici une seule ligne restante).
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['site:'.$sar->id], 'decision' => 'confirmer'])
            ->assertSessionHas('succes', '1 validé.');
        $this->assertSame(StatutSite::Confirme, $sar->fresh()->statut);
        $this->assertTrue(AuditLog::query()->where('entite_type', 'Site')->where('entite_id', $sar->id)->exists());

        // Plus rien en attente ; une ligne déjà traitée est refusée avec un message.
        $this->en($this->validateur)->get(route('admin.a-valider'))->assertSee('Sites (0)');
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['site:'.$sar->id], 'decision' => 'confirmer'])->assertSessionHas('erreur');
    }

    public function test_demande_d_autorisation_de_modifier_un_site_valide(): void
    {
        $site = $this->siteSoumis('SAR');
        $this->en($this->validateur)->post(route('admin.sites.confirmer', $site))->assertSessionHas('succes');

        // Le GESTIONNAIRE demande l'autorisation (motif obligatoire) ; la demande arrive dans la file.
        $this->en($this->gestionnaire)->post(route('admin.sites.demander-revision', $site), ['motif' => 'x'])->assertSessionHasErrors('motif');
        $this->en($this->gestionnaire)->post(route('admin.sites.demander-revision', $site), ['motif' => 'Ajouter le bloc B'])->assertSessionHas('succes');
        $this->en($this->gestionnaire)->get(route('admin.sites.show', $site))->assertSee('En attente de la décision du VALIDATEUR.');
        $this->en($this->validateur)->get(route('admin.a-valider'))->assertSee('Autorisations de modification de site (1)')->assertSee('« Ajouter le bloc B »', false);

        // Refusée : le site reste validé.
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['autorisation:'.$site->id], 'decision' => 'rejeter', 'motif' => 'Pas avant la campagne'])
            ->assertSessionHas('succes');
        $this->assertSame(StatutSite::Confirme, $site->fresh()->statut);
        $this->assertNull($site->fresh()->revision_demandee_le);

        // Nouvelle demande, acceptée : le site repasse en brouillon.
        $this->en($this->gestionnaire)->post(route('admin.sites.demander-revision', $site), ['motif' => 'Ajouter le bloc B']);
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['autorisation:'.$site->id], 'decision' => 'confirmer'])->assertSessionHas('succes');
        $this->assertSame(StatutSite::Brouillon, $site->fresh()->statut);
        $this->assertTrue(AuditLog::query()->where('action', 'site.modification_autorisee')->exists());
    }

    public function test_groupage_refuse_pour_les_elements_sensibles(): void
    {
        $sar = $this->siteSoumis('SAR');
        $this->en($this->regulateur)->post(route('admin.phase.proposer'), ['presentation_actions' => 1, 'souscription_actions' => 0, 'motif' => 'Ouverture prochaine'])->assertSessionHas('succes');
        $phase = DecisionPhase::query()->sole();

        // Pas de case à cocher dans les groupes sensibles ; la ligne garde ses boutons.
        $page = $this->en($this->validateur)->get(route('admin.a-valider'))->assertOk();
        $page->assertDontSee('value="phase:'.$phase->id.'"', false)->assertSee('data-non-groupable', false)->assertSee('name="elements[]" value="site:'.$sar->id.'"', false);

        // Refus côté serveur (422) pour toute requête groupée qui en contient, quel que soit le type sensible.
        foreach ([['phase:'.$phase->id, 'site:'.$sar->id], ['campagne:1', 'site:'.$sar->id], ['finances:'.$sar->id, 'site:'.$sar->id]] as $elements) {
            $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => $elements, 'decision' => 'confirmer', 'mode' => 'lot'])->assertStatus(422);
        }
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['phase:'.$phase->id], 'decision' => 'confirmer', 'mode' => 'lot'])->assertStatus(422);
        $this->assertSame(StatutSite::Soumis, $sar->fresh()->statut);
        $this->assertSame(DecisionPhase::SOUMISE, $phase->fresh()->statut);

        // Une par une : accepté.
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['phase:'.$phase->id], 'decision' => 'confirmer', 'mode' => 'ligne'])->assertSessionHas('succes', '1 validé.');
        $this->assertSame(DecisionPhase::CONFIRMEE, $phase->fresh()->statut);
    }

    public function test_auto_validation_refusee_ligne_seule_et_groupee(): void
    {
        $sar = $this->siteSoumis('SAR');
        $a04 = $this->siteSoumis('A04');
        $sar->forceFill(['soumis_par' => $this->validateur->id])->save(); // Élément soumis par le VALIDATEUR lui-même.

        $page = $this->en($this->validateur)->get(route('admin.a-valider'))->assertOk();
        $page->assertSee('data-moi', false)->assertSee('Vous avez soumis cet élément : un autre VALIDATEUR doit décider.', false)
            ->assertDontSee('name="elements[]" value="site:'.$sar->id.'"', false);

        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['site:'.$sar->id], 'decision' => 'confirmer', 'mode' => 'ligne'])
            ->assertSessionHas('erreur', '1 refusé : SAR · Marché SAR (Vous avez soumis cet élément : un autre VALIDATEUR doit décider.).');
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['site:'.$sar->id, 'site:'.$a04->id], 'decision' => 'rejeter', 'motif' => 'Plans à revoir'])
            ->assertSessionHas('erreur', '1 rejeté, 1 refusé : SAR · Marché SAR (Vous avez soumis cet élément : un autre VALIDATEUR doit décider.).');
        $this->assertSame(StatutSite::Soumis, $sar->fresh()->statut);
        $this->assertSame(StatutSite::Brouillon, $a04->fresh()->statut);
    }

    public function test_detail_avec_differences_historique_et_element_suivant(): void
    {
        $sar = $this->siteSoumis('SAR');
        $this->en($this->validateur)->post(route('admin.sites.confirmer', $sar))->assertSessionHas('succes');
        $this->en($this->validateur)->post(route('admin.sites.reviser', $sar), ['motif' => 'Ajout du bloc B'])->assertSessionHas('succes');
        $this->en($this->gestionnaire)->put(route('admin.sites.update', $sar), ['code' => 'SAR', 'nom' => 'Marché moderne de Sarfalao', 'region' => 'Centre', 'ville' => 'Ouagadougou'])
            ->assertSessionHasNoErrors();
        $this->en($this->gestionnaire)->post(route('admin.sites.soumettre', $sar))->assertSessionHas('succes');
        $a04 = $this->siteSoumis('A04');

        $detail = $this->en($this->validateur)->get(route('admin.a-valider', ['detail' => 'site:'.$sar->id]))->assertOk();
        $detail->assertSee('data-detail="site:'.$sar->id.'"', false)
            ->assertSeeInOrder(['Informations', 'Différences avec la version confirmée', 'Marché SAR', 'Marché moderne de Sarfalao', 'Pièces jointes', 'Historique'], false)
            ->assertSee('data-champ-modifie="nom"', false)->assertSee('Ouvrir la fiche complète')
            ->assertSee('name="suivant" value="site:'.$a04->id.'"', false);

        // Décision depuis le détail : l'élément suivant s'ouvre.
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['site:'.$sar->id], 'decision' => 'confirmer', 'mode' => 'ligne', 'suivant' => 'site:'.$a04->id])
            ->assertRedirect(route('admin.a-valider', ['detail' => 'site:'.$a04->id]));
        $this->assertSame(StatutSite::Confirme, $sar->fresh()->statut);
    }

    public function test_resultat_partiel_deja_traite_et_notification(): void
    {
        Notification::fake();
        $sar = $this->siteSoumis('SAR');
        $a04 = $this->siteSoumis('A04');
        $autre = User::factory()->role(Role::Validateur)->create();

        // Un autre VALIDATEUR traite SAR entre-temps.
        $this->en($autre)->post(route('admin.a-valider.decider'), ['elements' => ['site:'.$sar->id], 'decision' => 'confirmer', 'mode' => 'ligne'])->assertSessionHas('succes');

        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['site:'.$sar->id, 'site:'.$a04->id], 'decision' => 'confirmer'])
            ->assertSessionHas('erreur', '1 validé, 1 refusé : SAR · Marché SAR (déjà traité).');
        $this->assertSame(StatutSite::Confirme, $a04->fresh()->statut);

        // Chaque décision est notifiée à l'auteur (back-office et e-mail).
        Notification::assertSentTo($this->gestionnaire, DecisionRendue::class, 2);
        Notification::assertSentTo($this->gestionnaire, DecisionRendue::class, fn (DecisionRendue $n, array $canaux) => $canaux === ['database', 'mail']
            && str_contains($n->toArray($this->gestionnaire)['message'], 'validé'));
    }
}
