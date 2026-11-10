<?php

namespace Tests\Feature\Publique;

use App\Support\Guides\Guides;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Rubrique « Guides » (10/11/2026) : aucune dérive silencieuse. Chaque étape vise un écran qui existe, chaque capture
 * est présente, les pages statiques publiées reprennent le contenu des guides ; la source n'est jamais servie en
 * production et la commande refuse de tourner ailleurs que sur le poste de développement.
 */
class GuidesTest extends TestCase
{
    use RefreshDatabase;

    public function test_chaque_etape_vise_un_ecran_existant_et_chaque_capture_est_presente(): void
    {
        $this->assertNotEmpty(Guides::tous());
        foreach (Guides::tous() as $guide) {
            foreach ($guide['etapes'] as $etape) {
                $this->assertTrue(Route::has($etape['ecran']), "Guide {$guide['slug']}, étape {$etape['id']} : écran « {$etape['ecran']} » introuvable.");
                $this->assertNotEmpty($etape['gestes']);
                $this->assertNotEmpty($etape['captures']);
            }
            $this->assertSame([], Guides::capturesManquantes($guide), "Guide {$guide['slug']} : relancez « php artisan raaga:guides ».");
        }
    }

    public function test_les_pages_statiques_reprennent_le_contenu_des_guides(): void
    {
        $sommaire = (string) file_get_contents(Guides::dossier().'/index.html');
        foreach (Guides::tous() as $guide) {
            $this->assertStringContainsString(e($guide['titre']), $sommaire);
            $page = (string) file_get_contents(Guides::dossier($guide['slug']).'/index.html');
            foreach ($guide['etapes'] as $etape) {
                $this->assertStringContainsString(e($etape['titre']), $page, "Page statique du guide {$guide['slug']} à régénérer : « php artisan raaga:guides --sans-captures ».");
                foreach ($etape['gestes'] as $geste) {
                    $this->assertStringContainsString(e($geste), $page, "Page statique du guide {$guide['slug']} à régénérer : « php artisan raaga:guides --sans-captures ».");
                }
                foreach ($etape['captures'] as $capture) {
                    $this->assertStringContainsString(Guides::image($guide['slug'], $capture['fichier']), $page);
                }
            }
            // Page statique autonome : aucune adresse du serveur local, aucun jeton de formulaire.
            $this->assertStringNotContainsString('127.0.0.1', $page);
            $this->assertStringNotContainsString('csrf-token', $page);
        }
    }

    public function test_source_rendue_hors_production_seulement(): void
    {
        $this->get(route('guides.source'))->assertOk()->assertSee('data-guides', false);
        $this->get(route('guides.source', 'boutique'))->assertOk()->assertSee('data-guide="boutique"', false)->assertSee('récépissé', false);
        $this->get(route('guides.source', 'inconnu'))->assertNotFound();

        $this->app['env'] = 'production';
        $this->get(route('guides.source'))->assertNotFound();
        $this->get(route('guides.source', 'boutique'))->assertNotFound();
    }

    public function test_commande_refusee_hors_du_poste_de_developpement(): void
    {
        $this->assertSame(1, Artisan::call('raaga:guides', ['--sans-captures' => true]));
        $this->assertStringContainsString('raaga:guides', Artisan::output());
    }

    public function test_lien_vers_les_guides_dans_le_pied_de_page(): void
    {
        $this->get(route('faq'))->assertOk()->assertSee('href="'.url('guides/index.html').'"', false)->assertSee('Guides pas à pas');
    }
}
