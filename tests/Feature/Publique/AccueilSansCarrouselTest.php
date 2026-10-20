<?php

namespace Tests\Feature\Publique;

use App\Enums\Role;
use App\Support\Plateforme\Catalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Boutiques;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/**
 * Carrousel retiré de l'accueil (décision du 20/10/2026) : bandeau fixe dans les deux thèmes, même si des diapositives
 * restent en base ; rubrique « Site public › Carrousel » retirée du back-office, données conservées.
 */
class AccueilSansCarrouselTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    /** Diapositives encore en base (ancien paramètre site_public.carrousel), jamais supprimées. */
    private function diapositivesEnBase(): string
    {
        $json = json_encode(['carrousel' => [['image' => 'plateforme/diapositive-00000000-0000-0000-0000-000000000000', 'alt' => 'Allée du marché',
            'titre' => 'Diapositive du jour', 'sousTitre' => 'Ancien texte', 'boutonLibelle' => 'Voir', 'boutonLien' => '/faq', 'active' => true, 'ordre' => 1]]], JSON_UNESCAPED_UNICODE);
        DB::table('parametres_plateforme')->insert(['section' => 'site_public', 'valeurs' => $json, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('parametres_plateforme_versions')->insert(['section' => 'site_public', 'ancienne_valeur' => null, 'nouvelle_valeur' => $json, 'created_at' => now()]);
        Cache::flush();

        return $json;
    }

    public function test_accueil_theme_nuit_bandeau_toujours_affiche(): void
    {
        $this->diapositivesEnBase();

        $this->get('/')->assertOk()->assertSee('nuit-hero', false)->assertSee('<h1 id="accueil-titre"', false)
            ->assertDontSee('Diapositive du jour')->assertDontSee('acc-diapo', false)->assertDontSee('data-carrousel', false)
            ->assertDontSee('/plateforme/diapositives/', false);
    }

    public function test_accueil_theme_clair_bandeau_fixe_de_la_diapositive_par_defaut(): void
    {
        config(['raaga.theme_public' => 'clair']);
        $a = __('public.accueil_raaga');

        foreach ([false, true] as $avecDiapositives) {
            if ($avecDiapositives) {
                $this->diapositivesEnBase();
            }
            $this->get('/')->assertOk()->assertSee('data-bandeau-accueil', false)
                ->assertSee('<h1 id="accueil-titre" class="sr-only">'.e($a['titre_page']).'</h1>', false)
                ->assertSee('<h2 class="acc-titre">'.e($a['titre']).'</h2>', false)->assertSee(e($a['sous_titre']), false)
                // Sans campagne ouverte ni connexion : création de compte.
                ->assertSee('href="'.route('inscription').'" class="acc-btn acc-btn-jaune acc-diapo-bouton">'.e($a['candidater']).'</a>', false)
                ->assertSee('href="#comment" class="acc-btn acc-btn-contour">'.e($a['comment']).'</a>', false)
                ->assertDontSee('Diapositive du jour')->assertDontSee('data-carrousel', false)->assertDontSee('data-diapo', false);
        }

        // Campagne ouverte : le bouton mène au plan des boutiques.
        Boutiques::campagneOuverte();
        $this->get('/')->assertSee('href="'.route('boutiques').'" class="acc-btn acc-btn-jaune acc-diapo-bouton"', false);
    }

    public function test_rubrique_carrousel_retiree_du_back_office_donnees_conservees(): void
    {
        $json = $this->diapositivesEnBase();
        $version = DB::table('parametres_plateforme_versions')->where('section', 'site_public')->value('id');
        $this->connecterBackoffice(Role::SuperAdmin);

        $this->assertFalse(Catalogue::existe('site_public'));
        $this->get(route('admin.plateforme', ['section' => 'site_public']))->assertRedirect(route('admin.plateforme'));
        $this->get(route('admin.plateforme'))->assertOk()->assertDontSee('Carrousel')->assertDontSee('section=site_public', false);
        $this->put(route('admin.plateforme.update', 'site_public'), ['carrousel' => []])->assertNotFound();
        $this->post(route('admin.plateforme.apercu', 'site_public'), ['_method' => 'PUT'])->assertNotFound();
        $this->post(route('admin.plateforme.restaurer', $version))->assertNotFound();
        $this->get('/plateforme/diapositives/00000000-0000-0000-0000-000000000000/640')->assertNotFound();

        // Données conservées en base.
        $this->assertSame($json, DB::table('parametres_plateforme')->where('section', 'site_public')->value('valeurs'));
    }
}
