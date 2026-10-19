<?php

namespace Tests\Feature\Publique;

use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use Database\Seeders\ReferentielsSitesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\Support\GrillesTarifaires;
use Tests\TestCase;

/** Nouvelle page d'accueil publique (phase 1 : boutiques). */
class AccueilRaagaTest extends TestCase
{
    use ActeursParametrage, GrillesTarifaires, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Rendu historique de l'accueil (thème « clair ») ; le thème « nuit » est couvert par ThemeNuitAccueilBoutiquesTest.
        config(['raaga.theme_public' => 'clair']);

        config(['raaga.phase.presentation_actions' => false]);
        Cache::flush();
        $this->seed(ReferentielsSitesSeeder::class);
    }

    /** Deux sites confirmés (5 et 2 boutiques, dont une retirée), un site en brouillon (1 boutique). */
    protected function sites(): array
    {
        $auteur = User::factory()->create();
        $type = TypeBoutique::query()->where('code', 'BOUT')->first();
        $sites = [];
        foreach ([['RAA', 'Marché Raaga', StatutSite::Confirme, 5], ['SAR', 'Marché de Sarfalao', StatutSite::Confirme, 2], ['A04', 'Arrondissement 4', StatutSite::Brouillon, 1]] as [$code, $nom, $statut, $n]) {
            $site = Site::create(['statut' => $statut, 'code' => $code, 'nom' => $nom, 'ville' => 'Ouagadougou', 'version' => 1, 'cree_par' => $auteur->id]);
            foreach (range(1, $n) as $i) {
                $site->boutiques()->create(['niveau' => $i % 2 ? 'RC' : 'R1', 'bloc' => 'A', 'numero' => sprintf('%03d', $i), 'type_boutique_id' => $type->id,
                    'statut' => $code === 'SAR' && $i === 2 ? StatutBoutique::Retiree : StatutBoutique::Disponible]);
            }
            $sites[$code] = $site;
        }

        return $sites;
    }

    public function test_affichage_de_la_page(): void
    {
        $page = $this->get('/')->assertOk();
        $page->assertSeeInOrder(['Votre boutique au marché Raaga', 'boutiques référencées', 'Comment ça marche', 'Ce que vous versez', 'Les sites',
            'Questions fréquentes', 'Besoin d’aide ?'], false)
            ->assertSee('Je réserve')->assertSee('href="#comment"', false)
            ->assertSee('Raaga ne vous demandera jamais de payer à une personne. Payez uniquement par les canaux officiels.', false);
    }

    public function test_chiffres_lus_depuis_la_base(): void
    {
        $this->sites();
        $page = $this->get('/')->assertOk();
        // 7 boutiques non retirées, 5 niveaux par défaut, 2 sites confirmés ; aucune campagne ouverte.
        $page->assertSee('data-compteur="7"', false)->assertSee('data-compteur="5"', false)->assertSee('data-compteur="2"', false)
            ->assertSee('Prochaine campagne bientôt')
            ->assertSee('Marché Raaga')->assertSee('Marché de Sarfalao')->assertDontSee('Arrondissement 4')->assertSee('5 boutiques')->assertSee('1 boutique');

        // Campagne ouverte : sa date de clôture.
        $campagne = Boutiques::campagneOuverte();
        Cache::flush();
        $this->get('/')->assertSee('Réservations ouvertes jusqu’au '.$campagne->date_cloture->translatedFormat('j F Y'), false);
    }

    public function test_message_si_aucune_grille_confirmee_puis_durees_de_la_grille(): void
    {
        $this->get('/')->assertSee('Les montants seront publiés à l’ouverture de la campagne.', false);

        $this->creerActeurs();
        $sites = $this->sites();
        $bout = TypeBoutique::query()->where('code', 'BOUT')->value('id');
        $this->grilleConfirmee($sites['RAA'], ['RC' => [$bout => [2000, 12, 60]], 'R1' => [$bout => [1800, 12, 36]]]);
        $page = $this->get('/')->assertOk()->assertDontSee('Les montants seront publiés', false);
        $page->assertSeeInOrder(['Rez-de-chaussée', '12 mois', '60 mois', 'R+1', '12 mois', '36 mois'])->assertDontSee('FCFA');
    }

    public function test_les_actions_restent_masquees(): void
    {
        $page = $this->get('/')->assertOk();
        foreach (['offre', 'souscrire', 'transparence'] as $route) {
            $page->assertDontSee('href="'.route($route).'"', false);
        }
        $page->assertDontSee('J’investis');
        $this->get(route('offre'))->assertRedirect(route('boutiques'));
    }

    public function test_accordeon_accessible_au_clavier(): void
    {
        $page = $this->get('/')->assertOk();
        // Accordéon natif <details>/<summary> : chaque question est un élément focalisable, ouvert avec Entrée ou Espace.
        preg_match('#<section[^>]*aria-labelledby="acc-faq-titre".*?</section>#s', $page->getContent(), $faq);
        $this->assertSame(5, substr_count($faq[0], '<details class="faq-item">'));
        $this->assertSame(5, substr_count($faq[0], '<summary>'));
        $this->assertStringContainsString('Qui peut réserver une boutique ?', $faq[0]);
        $this->assertStringContainsString('href="'.route('faq').'"', $faq[0]);
    }
}
