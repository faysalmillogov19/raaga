<?php

namespace Tests\Feature\Publique;

use App\Enums\StatutSite;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * Rubrique « Où se trouvent les boutiques / Les sites » retirée de l'accueil (décision du 20/10/2026), dans les deux
 * thèmes, avec un ou plusieurs sites confirmés. Le nombre de sites reste dans les chiffres ; le choix du site reste
 * sur la page « Obtenir une boutique ».
 */
class AccueilSansSitesTest extends TestCase
{
    use RefreshDatabase;

    private function deuxiemeSite(): void
    {
        Site::create(['statut' => StatutSite::Confirme, 'code' => 'SAR', 'nom' => 'Marché de Sarfalao', 'ville' => 'Bobo-Dioulasso',
            'version' => 1, 'cree_par' => User::factory()->create()->id]);
        Cache::flush();
    }

    private function sansRubrique(): void
    {
        $this->get('/')->assertOk()
            ->assertDontSee('Où se trouvent les boutiques')->assertDontSee('acc-sites-titre', false)->assertDontSee('acc-sites', false)
            ->assertDontSee('Marché de Sarfalao')->assertDontSee('Les sites seront présentés ici', false);
    }

    public function test_theme_clair_un_puis_plusieurs_sites(): void
    {
        config(['raaga.theme_public' => 'clair']);
        Boutiques::campagneOuverte();
        $this->sansRubrique();
        $this->deuxiemeSite();
        $this->sansRubrique();
        // Nombre de sites confirmés toujours dans les chiffres clés.
        $this->get('/')->assertSee('data-compteur="2"', false);
    }

    public function test_theme_nuit_un_puis_plusieurs_sites(): void
    {
        Boutiques::campagneOuverte();
        $this->sansRubrique();
        // « Qu'est-ce que Raaga ? » garde le nom du site principal.
        $this->get('/')->assertSee('Marché central');
        $this->deuxiemeSite();
        $this->sansRubrique();
    }

    public function test_le_choix_du_site_reste_sur_obtenir_une_boutique(): void
    {
        $campagne = Boutiques::campagneOuverte();
        $this->deuxiemeSite();
        $campagne->forceFill(['sites' => Site::query()->pluck('id')->all()])->save();

        $this->get(route('boutiques'))->assertOk()->assertSee('Marché central')->assertSee('Marché de Sarfalao');
    }
}
