<?php

namespace Tests\Feature\Publique;

use App\Models\AlerteSouscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Annonce permanente « Investir dans Raaga : bientôt » tant que la présentation des actions n'est pas activée. */
class AnnonceInvestissementTest extends TestCase
{
    use RefreshDatabase;

    private const TEXTE = 'Bientôt, devenez actionnaire de votre marché et recevez les dividendes décidés par l’assemblée générale.';

    protected function setUp(): void
    {
        parent::setUp();

        config(['raaga.phase.presentation_actions' => false, 'raaga.phase.souscription_actions' => false]);
        Cache::flush();
    }

    public function test_annonce_visible_quand_la_presentation_est_desactivee(): void
    {
        // Rendu historique de l'accueil (thème « clair ») ; le thème « nuit » est couvert par ThemeNuitAccueilBoutiquesTest.
        config(['raaga.theme_public' => 'clair']);
        $page = $this->get('/')->assertOk();
        $page->assertSee('data-annonce-investir', false)->assertSee('Investir dans Raaga : bientôt')->assertSee(self::TEXTE, false)
            ->assertSee('Être prévenu par SMS')->assertSee('action="'.route('souscription.bientot.store').'"', false)
            ->assertSeeInOrder(['Investir dans Raaga : bientôt', 'Installez votre commerce dans un marché moderne']);

        // Ni prix, ni lien vers les pages des actions, ni menu Investir.
        $html = $page->getContent();
        foreach (['offre', 'transparence', 'souscrire', 'souscription.bientot'] as $route) {
            $this->assertStringNotContainsString('href="'.route($route).'"', $html, $route);
        }
        $page->assertDontSee('Prix de l’action', false)->assertDontSee('class="menu-picto demarche-investir"', false);

        // Inscription à l'alerte depuis l'annonce : retour à l'accueil, numéro enregistré.
        $this->from('/')->post(route('souscription.bientot.store'), ['telephone' => '70 12 34 56'])->assertRedirect('/')->assertSessionHasErrors('consentement');
        $this->from('/')->post(route('souscription.bientot.store'), ['telephone' => '70 12 34 56', 'consentement' => '1'])->assertRedirect('/');
        $this->assertSame('+22670123456', AlerteSouscription::query()->value('telephone'));
        $this->followingRedirects()->from('/')->post(route('souscription.bientot.store'), ['telephone' => '70123456', 'consentement' => '1'])
            ->assertSee('C’est noté : vous recevrez un SMS dès l’ouverture de la souscription.', false);
    }

    public function test_remplacee_par_la_carte_normale_quand_la_presentation_est_activee(): void
    {
        // Rendu historique de l'accueil (thème « clair ») ; le thème « nuit » est couvert par ThemeNuitAccueilBoutiquesTest.
        config(['raaga.theme_public' => 'clair']);
        config(['raaga.phase.presentation_actions' => true]);

        $this->get('/')->assertOk()->assertDontSee('data-annonce-investir', false)->assertDontSee('Investir dans Raaga : bientôt')
            ->assertSee('Devenez actionnaire de votre marché')->assertSee('class="menu-picto demarche-investir"', false);
    }

    public function test_aucune_page_des_actions_accessible_avant_activation(): void
    {
        foreach (['/offre', '/transparence', '/souscrire', '/souscrire/reglement', '/souscription-bientot', '/paiement', '/actualites/ouverture-tranche-1'] as $page) {
            $this->get($page)->assertRedirect(route('boutiques'));
        }
    }
}
