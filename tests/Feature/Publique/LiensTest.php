<?php

namespace Tests\Feature\Publique;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LiensTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = [
        '/', '/offre', '/transparence', '/faq', '/actualites/ouverture-tranche-1',
        '/connexion', '/inscription', '/souscrire', '/paiement',
        '/contact', '/guichets', '/boutiques', '/mentions-legales', '/donnees-personnelles', '/accessibilite',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public static function modes(): array
    {
        return ['mode démo' => [true], 'sans données' => [false]];
    }

    #[DataProvider('modes')]
    public function test_toutes_les_pages_publiques_s_affichent(bool $demo): void
    {
        config(['raaga.demo' => $demo]);

        foreach (self::PAGES as $page) {
            if (str_starts_with($page, '/actualites/') && ! $demo) {
                $this->get($page)->assertNotFound();

                continue;
            }

            $this->get($page)->assertOk();
        }
    }

    #[DataProvider('modes')]
    public function test_aucun_lien_interne_n_est_casse(bool $demo): void
    {
        config(['raaga.demo' => $demo]);

        $liens = [];

        foreach (self::PAGES as $page) {
            $reponse = $this->get($page);

            if ($reponse->isNotFound()) {
                continue;
            }

            preg_match_all('/href="([^"]+)"/', $reponse->getContent(), $trouves);

            foreach ($trouves[1] as $href) {
                $href = html_entity_decode($href);

                if (str_starts_with($href, url('/'))) {
                    $liens[strtok(substr($href, strlen(url('/'))) ?: '/', '#')] = $page;
                }
            }
        }

        $this->assertNotEmpty($liens);

        foreach ($liens as $lien => $source) {
            // Les fichiers statiques (CSS, armoiries) ne passent pas par le routeur Laravel.
            if (preg_match('/\.(css|js|svg|png|pdf|ico|woff2)$/', $lien)) {
                $this->assertFileExists(public_path(ltrim($lien, '/')), "Fichier manquant : {$lien} (lien trouvé sur {$source})");

                continue;
            }

            $statut = $this->get($lien)->getStatusCode();
            $this->assertLessThan(400, $statut, "Lien cassé : {$lien} (trouvé sur {$source}) → HTTP {$statut}");
        }
    }

    public function test_mon_espace_exige_une_connexion(): void
    {
        $this->get('/espace')->assertRedirect(route('connexion'));

        $this->actingAs(User::factory()->create())->get('/espace')->assertOk();
    }

    public function test_les_pages_du_tunnel_activent_l_entree_souscrire(): void
    {
        foreach (['/inscription', '/paiement'] as $page) {
            $this->get($page)->assertSeeInOrder(['aria-current="page"', 'Souscrire'], false);
        }
    }

    public function test_la_recherche_de_la_faq_est_preparee_sans_accents(): void
    {
        // « Sécurité » / « arnaque » : le texte indexé est en minuscules sans accents.
        $this->get('/faq')
            ->assertOk()
            ->assertSee('data-texte="comment reconnaitre une arnaque', false)
            ->assertSee('id="guichets"', false);
    }
}
