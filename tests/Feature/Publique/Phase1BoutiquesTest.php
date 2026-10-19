<?php

namespace Tests\Feature\Publique;

use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Boutiques;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/** Phase 1 (décision du 13/10/2026) : souscriptions aux boutiques et cautions uniquement. */
class Phase1BoutiquesTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['raaga.phase.presentation_actions' => false]);
        Cache::flush();
        Boutiques::campagneOuverte();
    }

    public function test_l_accueil_est_centre_sur_les_boutiques(): void
    {
        // Rendu historique de l'accueil (thème « clair ») ; le thème « nuit » est couvert par ThemeNuitAccueilBoutiquesTest.
        config(['raaga.theme_public' => 'clair']);
        $page = $this->get('/')->assertOk()
            ->assertSee('Votre boutique au marché Raaga')->assertSee('Réservations ouvertes jusqu’au', false)
            ->assertSee('Je réserve')->assertSee('boutiques référencées')
            ->assertSee('L’achat d’actions ne donne aucun droit ni priorité pour obtenir une boutique.')
            ->assertDontSee('J’investis');

        // Menus : ni offre, ni souscription d'actions, ni transparence de la collecte.
        foreach (['offre', 'souscrire', 'transparence'] as $route) {
            $page->assertDontSee('href="'.route($route).'"', false);
        }
        $page->assertSee('href="'.route('boutiques').'"', false);
    }

    public function test_les_pages_des_actions_renvoient_vers_les_boutiques(): void
    {
        foreach (['/offre', '/transparence', '/souscrire', '/souscrire/reglement', '/souscription-bientot', '/paiement', '/actualites/ouverture-tranche-1'] as $page) {
            $this->get($page)->assertRedirect(route('boutiques'));
        }

        // Connecté : un envoi de souscription est refusé ; le paiement d'actions renvoie aux boutiques.
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide]);
        $this->actingAs($citoyen)->post('/souscrire', ['nb_actions' => 1])->assertForbidden();
        $this->actingAs($citoyen)->get('/paiement/RAA-B-XXXX')->assertRedirect(route('boutiques'));
    }

    public function test_la_faq_porte_sur_les_boutiques_et_la_securite(): void
    {
        $this->get('/faq')->assertOk()
            ->assertSee('Boutiques et cautions')->assertSee('Comment les boutiques sont-elles attribuées ?')
            ->assertSee('Sécurité')
            ->assertDontSee('Mes droits')->assertDontSee('Combien coûte une action ?');
    }

    public function test_mon_espace_ne_montre_que_les_boutiques_et_le_profil(): void
    {
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide]);

        $this->actingAs($citoyen)->get('/espace')->assertOk()
            ->assertSee('Mes boutiques')->assertSee('Mon profil')->assertSee('Cautions payées')->assertSee('Boutiques attribuées')
            ->assertDontSee('Mes souscriptions')->assertDontSee('Mes dividendes')->assertDontSee('Total investi');
        $this->actingAs($citoyen)->get('/espace?onglet=dividendes')->assertOk()->assertSee('Aucune réservation de boutique');
        $this->actingAs($citoyen)->get('/espace/verification')->assertOk();
    }

    public function test_aucun_lien_casse_sur_les_pages_publiques_de_la_phase_1(): void
    {
        $liens = [];
        foreach (['/', '/boutiques', '/boutiques/reglement', '/faq', '/contact', '/guichets', '/connexion', '/inscription'] as $page) {
            preg_match_all('/href="([^"]+)"/', $this->get($page)->assertOk()->getContent(), $trouves);
            foreach ($trouves[1] as $href) {
                $href = html_entity_decode($href);
                if (str_starts_with($href, url('/')) && ! preg_match('/\.(css|js|svg|png|pdf|ico|woff2)(\?v=\d+)?$/', $href)) {
                    $liens[strtok(substr($href, strlen(url('/'))) ?: '/', '#')] = $page;
                }
            }
        }

        foreach ($liens as $lien => $source) {
            $reponse = $this->get($lien);
            $this->assertLessThan(400, $reponse->getStatusCode(), "Lien cassé : {$lien} (sur {$source})");
            $this->assertFalse($reponse->isRedirect(route('boutiques')) && $lien !== '/boutiques', "Lien vers une page des actions : {$lien} (sur {$source})");
        }
    }

    public function test_le_back_office_et_l_espace_institutionnel_restent_en_place(): void
    {
        $this->connecterBackoffice(Role::Validateur);
        $this->get('/admin/tranches')->assertOk();
        $this->get('/admin/boutiques')->assertOk();
        $this->get('/admin/conventions')->assertOk();
    }
}
