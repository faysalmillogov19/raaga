<?php

namespace Tests\Feature\Publique;

use App\Enums\Role;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutKyc;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Boutiques;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/**
 * Thème « nuit », lot 1 : accueil, « Obtenir une boutique » et règlement, d'après les maquettes ; mêmes données et mêmes
 * états que le thème « clair », qui garde son rendu historique.
 */
class ThemeNuitAccueilBoutiquesTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['raaga.phase.presentation_actions' => false]);
        Cache::flush();
    }

    public function test_accueil_sans_campagne_ni_site(): void
    {
        $page = $this->get('/')->assertOk();

        $page->assertSee('class="nuit-section-blanc nuit-hero"', false)->assertSee('Votre boutique au <span class="nuit-hero-accent">marché Raaga</span>', false)
            ->assertSee('Au premier arrivé, premier servi')->assertSee('href="#comment"', false)
            // Sans boutique tarifée : pas de téléphone d'exemple.
            ->assertDontSee('data-exemple', false)
            ->assertSee(__('public.nuit.accueil.quoi_texte_sans_site'))
            ->assertSee('id="comment"', false)->assertSee('Contre la fraude')
            ->assertSee('Prochaine campagne bientôt')
            ->assertSee('Les montants seront publiés à l’ouverture de la campagne.', false)
            ->assertSeeInOrder(['Calendrier', 'En cours', 'Études', 'Réservations', 'À venir', 'Construction', 'À venir', 'Ouverture du marché'])
            ->assertSee('Questions <strong>et réponses</strong>', false)
            // Carte « Investir » : même logique que le thème clair (annonce sans présentation des actions).
            ->assertSee('data-annonce-investir', false)->assertSee('Investir dans Raaga : bientôt');
        $this->assertStringNotContainsString('acc-demarches', $page->getContent());
    }

    public function test_accueil_avec_campagne_ouverte_donnees_de_la_base(): void
    {
        $campagne = Boutiques::campagneOuverte(['nom' => 'Réservation des boutiques 2027']);

        $page = $this->get('/')->assertOk();

        $page->assertSee('data-exemple', false)->assertDontSee('nuit-scene-exemple', false)->assertSee('MCE-RC-A-001', false)
            // 4 petites boutiques de 9 m² et 1 grande : chiffres, orbite, campagne.
            ->assertSee('5 emplacements, 1 niveaux.')
            ->assertSee('Marché central, à Ouagadougou, réunit 5 boutiques sur 1 niveaux', false)
            ->assertSee('Réservation des <strong>boutiques 2027</strong>', false)->assertSee('Campagne en cours')
            ->assertSee('data-etat-campagne="ouverte"', false)->assertSee('data-compte-a-rebours', false)
            ->assertSee('Clôture des réservations dans')
            ->assertSee('Boutiques réservées : 0 sur 5')->assertSee('aria-valuemax="5" aria-valuenow="0"', false)
            ->assertSee('<span class="nuit-vif">1</span> par type d’emplacement', false)
            // Graphiques et tarifs : grille confirmée (loyer 5 000 FCFA/m², 9 m²).
            ->assertSee('Boutiques par niveau')->assertSee('<table class="sr-only">', false)
            ->assertSee('Grille confirmée')->assertSee('Loyer (9 m²)')->assertSee(format_fcfa(45_000))
            ->assertSeeInOrder(['Terminé', 'Études', $campagne->date_ouverture->format('d/m/Y'), 'Réservations', 'Ouvertes jusqu’au '.$campagne->date_cloture->format('d/m/Y')], false);
    }

    public function test_boutiques_sans_campagne(): void
    {
        $this->get(route('boutiques'))->assertOk()->assertSee('nuit-page', false)->assertSee('nuit-bandeau', false)
            ->assertSee('Obtenir une <strong>boutique</strong>', false)
            ->assertSee('Aucune campagne de réservation ouverte');
    }

    public function test_boutiques_campagne_ouverte_plan_et_fiche(): void
    {
        Boutiques::campagneOuverte();

        $page = $this->get(route('boutiques'))->assertOk();

        $page->assertSee('data-etat-campagne="ouverte"', false)->assertSee('Réservations ouvertes')->assertSee('data-campagne-ouverte', false)
            ->assertSee('Clôture dans')->assertSee('data-compte-a-rebours', false)
            ->assertSee('Limite par personne : Grande boutique (1), Petite boutique (1).')
            ->assertSee('Choisissez <strong>votre emplacement</strong>', false)
            ->assertSee('data-choix="niveau"', false)->assertSee('class="nuit-pilule nuit-pilule-choix" aria-current="page"', false)
            ->assertSee('data-plan', false)->assertSee('data-statut="disponible"', false)->assertSee('MCE-RC-A-001')
            ->assertSee('data-plan-detail', false)->assertSee('Total à payer (caution et avance)')->assertSee('Voir et réserver cet emplacement')
            ->assertSeeInOrder(['Tout savoir sur la campagne', 'Comment <strong>ça marche</strong>', 'Étape 1', 'Je choisis ma boutique', 'Étape 4', 'Sans lien avec les actions'], false);
    }

    public function test_boutiques_bientot_et_terminee(): void
    {
        $campagne = Boutiques::campagneOuverte(['statut' => StatutCampagneBoutiques::Confirmee, 'date_ouverture' => now()->addDays(3)]);

        $this->get(route('boutiques'))->assertSee('data-etat-campagne="bientot"', false)->assertSee('Bientôt ouverte')
            ->assertSee('Les réservations ouvrent bientôt.')->assertSee('Ouverture dans')->assertSee('data-compte-a-rebours', false)
            // Avant l'ouverture : aucune case ne mène à la réservation.
            ->assertDontSee('Choisir la boutique', false);

        $campagne->forceFill(['statut' => StatutCampagneBoutiques::Cloturee, 'date_ouverture' => now()->subMonth(), 'date_cloture' => now()->subDay(), 'cloturee_le' => now()])->save();
        $this->get(route('boutiques'))->assertSee('data-etat-campagne="terminee"', false)
            ->assertSee('La période de réservation de cette campagne est terminée.')->assertDontSee('data-compte-a-rebours', false);
    }

    public function test_boutiques_limite_atteinte_pour_le_citoyen(): void
    {
        Boutiques::campagneOuverte();
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+22670123456']);
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A'))->assertSessionHasNoErrors();

        $this->actingAs($citoyen)->get(route('boutiques'))->assertOk()
            ->assertSee('Mes réservations en cours')->assertSee('data-limite-atteinte', false)->assertSee('Limite atteinte pour ce type')
            ->assertSee('data-statut="pre_reservee"', false);
    }

    public function test_reglement_dans_des_cartes_sombres(): void
    {
        $campagne = Boutiques::campagneOuverte();

        $this->get(route('boutiques.reglement'))->assertOk()->assertSee('nuit-page', false)->assertSee('nuit-reglement', false)
            ->assertSee('Article 1 : Objet')->assertSee('Règlement · '.$campagne->nom);
        config(['raaga.theme_public' => 'clair']);
        $this->get(route('boutiques.reglement'))->assertOk()->assertDontSee('nuit-reglement', false)->assertSee('class="space-y-4 leading-relaxed carte p-5 tab:p-8"', false);
    }

    public function test_theme_clair_garde_l_accueil_et_la_page_historiques(): void
    {
        Boutiques::campagneOuverte();
        config(['raaga.theme_public' => 'clair']);

        $this->get('/')->assertOk()->assertSee('acc-demarches', false)->assertDontSee('nuit-hero', false)->assertDontSee('nuit-page', false);
        $this->get(route('boutiques'))->assertOk()->assertSee('boutiques-resume carte', false)->assertDontSee('nuit-plan', false);
    }
}
