<?php

namespace Tests\Feature\Publique;

use App\Enums\Role;
use App\Models\User;
use App\Support\ThemePublic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Thème « nuit » du site public, lot 0 (socle) : paramètre raaga.theme_public, gabarit (en-tête blanc, pied sombre),
 * thème « clair » au rendu historique, composants réutilisables.
 */
class ThemeNuitSocleTest extends TestCase
{
    use RefreshDatabase;

    public function test_nuit_par_defaut_et_valeur_inconnue(): void
    {
        $this->assertSame('nuit', config('raaga.theme_public'));
        $this->assertTrue(ThemePublic::nuit());
        config(['raaga.theme_public' => 'n’importe quoi']);
        $this->assertSame(ThemePublic::NUIT, ThemePublic::courant());
        config(['raaga.theme_public' => 'clair']);
        $this->assertFalse(ThemePublic::nuit());
    }

    public function test_gabarit_nuit_entete_blanc_menu_et_pilule(): void
    {
        $page = $this->get(route('boutiques'))->assertOk();

        $page->assertSee('theme-nuit', false)->assertSee('class="nuit-entete"', false)
            ->assertSee('aria-controls="menu-principal"', false)
            ->assertSeeInOrder(['nuit-menu-liste', __('public.menu.accueil'), __('public.menu.boutiques'), __('public.menu.faq')], false)
            ->assertSee('href="'.route('connexion').'" class="nuit-pilule nuit-pilule-plein nuit-entete-compte"', false)
            ->assertSee('class="nuit-menu-lien est-actif"', false)
            ->assertSee('pied-public', false)
            // Contenu pas encore converti : fond clair conservé.
            ->assertDontSee('nuit-page', false)
            // Ancien en-tête absent.
            ->assertDontSee('class="bg-vert-fonce"', false);
    }

    public function test_gabarit_nuit_connecte_prenom_et_menu_du_compte(): void
    {
        $citoyen = User::factory()->role(Role::Citoyen)->create(['prenoms' => 'Awa Marie']);

        $this->actingAs($citoyen)->get(route('boutiques'))->assertOk()
            ->assertSee('aria-controls="menu-compte"', false)
            ->assertSeeInOrder(['Awa', 'id="menu-compte"', route('espace'), route('espace.profil'), route('logout')], false);
    }

    public function test_theme_clair_rend_le_gabarit_historique(): void
    {
        config(['raaga.theme_public' => 'clair']);

        $this->get(route('boutiques'))->assertOk()
            ->assertSee('<body class="site-public flex min-h-screen flex-col bg-white font-public text-texte antialiased">', false)
            ->assertDontSee('theme-nuit', false)->assertDontSee('nuit-entete', false)
            ->assertSee('class="bg-vert-fonce"', false)
            ->assertSee('<footer class="pied-public bg-encre text-gris-clair">', false);
    }

    public function test_phrase_du_pied_seulement_avec_la_presentation_des_actions(): void
    {
        $phrase = __('public.pied.presentation');
        foreach (['nuit', 'clair'] as $theme) {
            config(['raaga.theme_public' => $theme, 'raaga.phase.presentation_actions' => false]);
            $this->get(route('boutiques'))->assertDontSee($phrase);
        }
        config(['raaga.phase.presentation_actions' => true]);
        $this->get(route('accueil'))->assertSee($phrase);
    }

    public function test_composants_du_socle(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-public.section fond="alterne" surtitre="Pourquoi Raaga" titre="Un marché" fort="simple et sûr" :centre="true">
                <x-public.carte-sombre titre="Carte"><x-public.pastille-icone couleur="bleu" texte="ID" /></x-public.carte-sombre>
                <x-public.pilule href="/boutiques">Je réserve</x-public.pilule>
                <x-public.pilule variante="choix" :presse="true">R+1</x-public.pilule>
                <x-public.pilule variante="choix" :presse="false">R+2</x-public.pilule>
                <x-public.jauge :valeur="25" :max="200" libelle="Boutiques réservées : 25 sur 200" />
                <x-public.frise variante="dates" :etapes="[['date' => '19/10/2026', 'titre' => 'Réservations', 'detail' => 'Ouvertes'], ['date' => 'À venir', 'titre' => 'Construction']]" />
                <x-public.fiche-blanche surtitre="Emplacement" titre="RAA-R1-A-004" :lignes="[['libelle' => 'Loyer mensuel', 'valeur' => '161 431 FCFA', 'montant' => true]]" total-libelle="Total" total="7 748 688 FCFA" />
                <x-public.tableau-sombre legende="Tarifs par niveau" :entetes="['Niveau', 'Loyer']" :lignes="[['R+1', '161 431 FCFA']]" />
                <x-public.graphique-anneau titre="Boutiques par niveau" :segments="[['libelle' => 'R+1', 'valeur' => 214], ['libelle' => 'R+2', 'valeur' => 214, 'couleur' => 'jaune']]" />
                <x-public.graphique-barres titre="Loyer par niveau" :barres="[['libelle' => 'R+1', 'valeur' => 161431, 'etiquette' => '161 431'], ['libelle' => 'R+3', 'valeur' => 80000]]" />
            </x-public.section>
            BLADE);

        $this->assertStringContainsString('class="nuit-section nuit-section-alterne"', $html);
        $this->assertStringContainsString('<p class="nuit-surtitre">Pourquoi Raaga</p>', $html);
        $this->assertStringContainsString('<h2 class="nuit-titre">Un marché <strong>simple et sûr</strong></h2>', $html);
        $this->assertStringContainsString('class="nuit-pastille nuit-pastille-normale nuit-c-bleu" aria-hidden="true">', $html);
        $this->assertStringContainsString('<a href="/boutiques" class="nuit-pilule nuit-pilule-plein">Je réserve</a>', $html);
        $this->assertStringContainsString('aria-pressed="true" class="nuit-pilule nuit-pilule-choix">R+1</button>', $html);
        $this->assertStringContainsString('aria-pressed="false" class="nuit-pilule nuit-pilule-choix">R+2</button>', $html);
        $this->assertStringContainsString('role="progressbar" aria-valuemin="0" aria-valuemax="200" aria-valuenow="25"', $html);
        $this->assertStringContainsString('style="--pct: 12.5%"', $html);
        $this->assertStringContainsString('<span class="nuit-frise-date">19/10/2026</span>', $html);
        $this->assertStringContainsString('nuit-frise-etape nuit-c-bleu', $html);
        $this->assertStringContainsString('<p class="nuit-fiche-total">7 748 688 FCFA</p>', $html);
        $this->assertStringContainsString('role="region" aria-label="Tarifs par niveau" tabindex="0"', $html);
        $this->assertStringContainsString('<caption class="sr-only">Tarifs par niveau</caption>', $html);
        // Graphiques : décoratifs, avec un tableau équivalent pour les lecteurs d'écran.
        $this->assertStringContainsString('--anneau: conic-gradient(#00A86B 0% 50%, #FCD116 50% 100%)', $html);
        $this->assertStringContainsString('<table class="sr-only">', $html);
        $this->assertMatchesRegularExpression('/<th scope="row">R\+2<\/th><td>214<\/td>\s*<td>50 %<\/td>/', $html);
        $this->assertStringContainsString('style="--h: 100%"', $html);
        $this->assertStringContainsString('style="--h: 49.6%"', $html);
    }

    public function test_compte_a_rebours_lisible_sans_javascript(): void
    {
        $this->travelTo(now()->setTime(10, 0, 0));
        $html = Blade::render('<x-public.compte-a-rebours :cible="$cible" titre="Clôture dans" />', ['cible' => now()->addDays(59)->addHours(14)->addMinutes(52)->addSeconds(7)]);

        $this->assertStringContainsString('x-data="compteARebours(', $html);
        $this->assertStringContainsString('<p class="nuit-rebours-titre">Clôture dans</p>', $html);
        $this->assertSame(['59', '14', '52', '07'], $this->chiffres($html));
        $this->assertStringContainsString('<span class="nuit-rebours-unite">Jours</span>', $html);
        $this->assertMatchesRegularExpression('/<p class="sr-only">Échéance : \d+ \w+ \d{4} à \d{2}h\d{2}<\/p>/u', $html);

        // Échéance passée : zéros, jamais de valeur négative.
        $this->assertSame(['00', '00', '00', '00'], $this->chiffres(Blade::render('<x-public.compte-a-rebours :cible="$c" />', ['c' => now()->subHour()])));
    }

    /** @return list<string> */
    private function chiffres(string $html): array
    {
        preg_match_all('/x-text="deux\((?:j|h|mn|s)\)">(\d+)</', $html, $m);

        return $m[1];
    }
}
