<?php

namespace Tests\Feature\Publique;

use App\Enums\Role;
use App\Enums\StatutCampagneBoutiques;
use App\Models\CampagneBoutiques;
use App\Models\User;
use App\Support\MarkdownLeger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * Page « Obtenir une boutique » (lot 1) : message de chaque état de campagne (compte à rebours avant l'ouverture), plan
 * juste après le résumé, limites en clair, « Tout savoir » repliable, présentation mise en forme sans HTML interprété,
 * aperçu dans le formulaire du RÉGULATEUR.
 */
class ObtenirBoutiqueTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private function campagne(StatutCampagneBoutiques $statut, string $ouverture, string $cloture, array $plus = []): CampagneBoutiques
    {
        return Boutiques::campagneOuverte(['statut' => $statut, 'date_ouverture' => $ouverture, 'date_cloture' => $cloture, ...$plus]);
    }

    public function test_message_de_chaque_etat_de_campagne(): void
    {
        // Brouillon ou soumise : jamais montrée au public.
        $c = $this->campagne(StatutCampagneBoutiques::Brouillon, now()->addDays(3), now()->addMonth());
        $this->get(route('boutiques'))->assertOk()->assertSee('Aucune campagne de réservation ouverte')->assertDontSee($c->nom);
        $c->forceFill(['statut' => StatutCampagneBoutiques::Soumise])->save();
        $this->get(route('boutiques'))->assertSee('Aucune campagne de réservation ouverte');

        // Confirmée, date d'ouverture à venir : « ouvrent bientôt », date et compte à rebours.
        $c->forceFill(['statut' => StatutCampagneBoutiques::Confirmee])->save();
        $page = $this->get(route('boutiques'))->assertOk()->assertSee('data-etat-campagne="bientot"', false)
            ->assertSee('Les réservations ouvrent bientôt.')->assertSee('Bientôt ouverte')->assertSee('data-compte-a-rebours', false)
            ->assertSee('Ouverture le '.$c->date_ouverture->translatedFormat('j F Y à H\hi'))->assertDontSee('est terminée');

        // Confirmée, date d'ouverture atteinte : la campagne s'ouvre d'elle-même, sans action du VALIDATEUR.
        $c->forceFill(['date_ouverture' => now()->subDays(2)])->save();
        $this->get(route('boutiques'))->assertSee('data-etat-campagne="ouverte"', false)->assertDontSee('est terminée');
        $this->assertSame(StatutCampagneBoutiques::Ouverte, $c->refresh()->statut);

        // Ouverte, avant sa date : bientôt, avec compte à rebours.
        $c->forceFill(['statut' => StatutCampagneBoutiques::Ouverte, 'date_ouverture' => now()->addHours(5)])->save();
        $this->get(route('boutiques'))->assertSee('data-etat-campagne="bientot"', false)->assertSee('data-compte-a-rebours', false);

        // Ouverte dans sa période : réservations ouvertes jusqu'à la clôture.
        $c->forceFill(['date_ouverture' => now()->subHour()])->save();
        $this->get(route('boutiques'))->assertSee('data-etat-campagne="ouverte"', false)->assertSee('Réservations ouvertes')
            ->assertSee('Réservations ouvertes jusqu’au '.$c->date_cloture->translatedFormat('j F Y à H\hi'), false);

        // Ouverte mais période passée, ou clôturée : terminée.
        $c->forceFill(['date_ouverture' => now()->subMonths(2), 'date_cloture' => now()->subDay()])->save();
        $this->get(route('boutiques'))->assertSee('data-etat-campagne="terminee"', false)->assertSee('La période de réservation de cette campagne est terminée.');
        $c->forceFill(['statut' => StatutCampagneBoutiques::Cloturee, 'cloturee_le' => now()])->save();
        $this->get(route('boutiques'))->assertSee('data-etat-campagne="terminee"', false);
    }

    public function test_le_plan_vient_juste_apres_le_resume_et_le_reste_est_repliable(): void
    {
        // Rendu historique de l'accueil (thème « clair ») ; le thème « nuit » est couvert par ThemeNuitAccueilBoutiquesTest.
        config(['raaga.theme_public' => 'clair']);
        $this->campagne(StatutCampagneBoutiques::Ouverte, now()->subDay(), now()->addMonth());
        $page = $this->get(route('boutiques'))->assertOk();

        // Résumé, plan, puis « Tout savoir » ; limites en clair (nom du type), une seule mention de l'indépendance en tête.
        $page->assertSeeInOrder(['data-etat-campagne', 'Limite par personne : Grande boutique (1), Petite boutique (1).', 'data-plan', 'id="tout-savoir"'], false);
        $html = $page->getContent();
        $this->assertSame(3, substr_count($html, '<details class="boutiques-repli"'));
        $this->assertSame(1, substr_count($html, 'Comment ça marche</summary>'));
        $this->assertStringNotContainsString('class="mb-8"', $html);
        $this->assertLessThan(strpos($html, 'id="tout-savoir"'), strpos($html, 'data-plan'));
    }

    public function test_presentation_mise_en_forme_sans_html_interprete(): void
    {
        $this->campagne(StatutCampagneBoutiques::Ouverte, now()->subDay(), now()->addMonth(), [
            'description' => "## Les étapes\n\n1. Créez **votre** compte\n2. Choisissez\n\n- Particulier : *CNIB*\n<script>alert('x')</script>\n\n<img src=x onerror=alert(1)>",
        ]);
        $this->get(route('boutiques'))->assertOk()
            ->assertSee('<h3>Les étapes</h3>', false)->assertSee('<ol><li>Créez <strong>votre</strong> compte</li><li>Choisissez</li></ol>', false)
            ->assertSee('<em>CNIB</em>', false)
            ->assertDontSee("<script>alert('x')</script>", false)->assertSee('&lt;script&gt;', false)->assertDontSee('<img src=x', false);

        $this->assertSame('', (string) MarkdownLeger::html(null));
        $this->assertSame("<p>a<br>b</p>\n<p>c</p>", (string) MarkdownLeger::html("a\nb\n\nc"));
    }

    public function test_apercu_de_la_presentation_dans_le_formulaire_du_regulateur(): void
    {
        $this->creerActeurs();
        $this->en($this->regulateur)->get(route('admin.boutiques.create'))->assertOk()->assertSee('data-apercu-description', false)
            ->assertSee('« ## Titre »', false);
        $this->en($this->regulateur)->postJson(route('admin.boutiques.apercu'), ['texte' => "### Paiement\n- **Orange Money**\n<b onclick=x>gras</b>"])->assertOk()
            ->assertExactJson(['html' => "<h4>Paiement</h4>\n<ul><li><strong>Orange Money</strong></li></ul>\n<p>&lt;b onclick=x&gt;gras&lt;/b&gt;</p>"]);
        // Réservé à la saisie des campagnes.
        $this->en($this->validateur)->postJson(route('admin.boutiques.apercu'), ['texte' => 'x'])->assertForbidden();
        $this->en(User::factory()->role(Role::GestionnaireSites)->create())->postJson(route('admin.boutiques.apercu'), ['texte' => 'x'])->assertForbidden();
    }
}
