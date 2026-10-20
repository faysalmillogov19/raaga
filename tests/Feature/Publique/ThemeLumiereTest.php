<?php

namespace Tests\Feature\Publique;

use App\Enums\MethodeAllocation;
use App\Enums\ModeSaisie;
use App\Enums\PayeurFrais;
use App\Enums\PublicAutorise;
use App\Enums\RegleArret;
use App\Enums\RegleSiEchec;
use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutKyc;
use App\Enums\StatutTranche;
use App\Enums\TypeFraisSouscription;
use App\Models\Duree;
use App\Models\Tranche;
use App\Models\User;
use App\Services\Boutiques\ListesAttente;
use App\Support\ThemePublic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Boutiques;
use Tests\Support\ParametresInvestissementConfirmes;
use Tests\TestCase;

/**
 * Thème « lumière » (défaut) : mise en page du thème « nuit » en couleurs claires (classe .theme-lumiere en plus de
 * .theme-nuit). Les thèmes « nuit » et « clair » restent disponibles.
 */
class ThemeLumiereTest extends TestCase
{
    use ParametresInvestissementConfirmes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function trancheOuverte(): Tranche
    {
        config(['raaga.phase.presentation_actions' => true, 'raaga.phase.souscription_actions' => true]);
        Cache::flush();
        $this->confirmerParametresInvestissement(10_000, minimum: 1);

        return Tranche::create([
            'statut' => StatutTranche::Ouverte, 'nom' => 'Tranche 1', 'valeur_unitaire' => 10_000, 'min_actions_par_souscription' => 1,
            'objectif_montant' => 3_000_000_000, 'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addDays(30),
            'publics_autorises' => [PublicAutorise::ResidentBf->value], 'mode_saisie' => ModeSaisie::NbActions, 'souscriptions_multiples' => true,
            'regle_arret' => RegleArret::ArretAObjectif, 'methode_allocation' => MethodeAllocation::Prorata, 'regle_si_echec' => RegleSiEchec::RemboursementIntegral,
            'delai_remboursement_jours' => 30, 'frais_paiement_supportes_par' => PayeurFrais::Raaga, 'frais_souscription_type' => TypeFraisSouscription::Aucun,
            'frais_souscription_valeur' => 0, 'canaux_actifs' => ['ORANGE_MONEY'], 'delai_paiement_valeur' => 48, 'delai_paiement_duree_id' => Duree::parCode('HEURE')->id,
            'delai_paiement_secondes' => 172_800, 'version' => 1, 'cree_par' => User::factory()->create()->id,
        ]);
    }

    public function test_lumiere_par_defaut(): void
    {
        $this->assertStringContainsString("env('RAAGA_THEME_PUBLIC', 'lumiere')", file_get_contents(config_path('raaga.php')));
        $this->assertStringContainsString('RAAGA_THEME_PUBLIC=lumiere', file_get_contents(base_path('.env.example')));

        foreach ([null, '', 'n’importe quoi', 'lumiere'] as $valeur) {
            config(['raaga.theme_public' => $valeur]);
            $this->assertSame(ThemePublic::LUMIERE, ThemePublic::courant());
            $this->assertTrue(ThemePublic::lumiere());
            $this->assertTrue(ThemePublic::nuit());
            $this->assertFalse(ThemePublic::sombre());
        }
        config(['raaga.theme_public' => 'nuit']);
        $this->assertSame(ThemePublic::NUIT, ThemePublic::courant());
        config(['raaga.theme_public' => 'clair']);
        $this->assertSame(ThemePublic::CLAIR, ThemePublic::courant());
    }

    public function test_pages_principales_dans_les_trois_themes(): void
    {
        $this->trancheOuverte();
        Boutiques::campagneOuverte();
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide]);
        $pages = [
            fn () => $this->get('/'),
            fn () => $this->get(route('boutiques')),
            fn () => $this->get(route('faq')),
            fn () => $this->get(route('connexion')),
            fn () => $this->get(route('offre')),
            fn () => $this->actingAs($citoyen)->get(route('espace')),
            fn () => $this->actingAs($citoyen)->get(route('souscrire')),
        ];

        foreach (['lumiere' => ['theme-nuit theme-lumiere', true], 'nuit' => ['theme-nuit', true], 'clair' => [null, false]] as $theme => [$classes, $miseEnPageNuit]) {
            config(['raaga.theme_public' => $theme]);
            foreach ($pages as $page) {
                $this->app['auth']->forgetGuards();
                $reponse = $page()->assertOk();
                preg_match('/<body class="([^"]*)"/', $reponse->getContent(), $m);
                $corps = $m[1] ?? '';
                if ($classes) {
                    $this->assertStringEndsWith($classes, $corps, $theme);
                } else {
                    $this->assertStringNotContainsString('theme-nuit', $corps);
                }
                $this->assertSame($theme === 'lumiere', str_contains($corps, 'theme-lumiere'), $theme);
                $miseEnPageNuit ? $reponse->assertSee('nuit-entete', false) : $reponse->assertDontSee('nuit-entete', false);
            }
        }
    }

    public function test_connexion_logo_couleur_en_lumiere_logo_blanc_en_nuit(): void
    {
        $this->get(route('connexion'))->assertOk()->assertSee('compte-carte', false)->assertDontSee('<div class="mb-5 text-center">', false);

        config(['raaga.theme_public' => 'nuit']);
        $this->get(route('connexion'))->assertOk()->assertSee('<div class="mb-5 text-center">', false);
    }

    public function test_page_d_erreur_en_lumiere(): void
    {
        $this->get('/page-inexistante')->assertNotFound()->assertSee('theme-nuit', false)->assertSee('theme-lumiere', false);

        config(['raaga.theme_public' => 'nuit']);
        $this->get('/page-inexistante')->assertNotFound()->assertSee('theme-nuit', false)->assertDontSee('theme-lumiere', false);
    }

    public function test_grille_du_plan_selon_la_maquette(): void
    {
        Boutiques::campagneOuverte([], 6);
        Boutiques::boutique('A', 2)->forceFill(['statut' => StatutBoutique::PreReservee])->save();
        Boutiques::boutique('A', 3)->forceFill(['statut' => StatutBoutique::Reservee])->save();
        Boutiques::boutique('A', 4)->forceFill(['statut' => StatutBoutique::Attribuee])->save();
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+22670123456']);
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A', 5))->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();

        $html = $this->get(route('boutiques', ['niveau' => 'RC', 'bloc' => 'A']))->assertOk()
            ->assertSee('<strong>Rez-de-chaussée · bloc A · 2 disponibles sur 6</strong> · Touchez un emplacement pour voir son détail.', false)
            ->assertSee('aria-label="MCE-RC-A-001, Disponible"', false)->assertSee('aria-label="MCE-RC-A-002, Pré-réservée"', false)
            ->assertSee('aria-label="MCE-RC-A-004, Attribuée"', false)
            ->assertSee('@click.prevent="d = ', false)->assertSee('Retirée')
            ->assertSee('Emplacement')->assertSee('data-interesses', false)
            ->getContent();

        // Numéro sur trois chiffres pour une case disponible, icône pour les autres ; toutes les cases dans l'ordre.
        $this->assertMatchesRegularExpression('/aria-label="MCE-RC-A-001, Disponible"[^>]*>001</', $html);
        $this->assertSame(['001', '002', '003', '004', '005', '006'], array_map(fn ($c) => substr($c, -3), $this->codes($html)));
        // Fiche : libellés des montants avec leur durée, action selon le statut, personnes inscrites.
        $this->assertStringContainsString('Caution (12 mois)', $html);
        $this->assertStringContainsString('Boutique attribuée', $html);
        $this->assertStringContainsString('Voir et m’inscrire sur la liste d’attente', $html);
        $this->assertStringContainsString('1 personne s’est déjà inscrite sur cette boutique.', $html);
        $this->assertSame(1, ListesAttente::interessesParBoutique([Boutiques::boutique('A', 5)->id])[Boutiques::boutique('A', 5)->id] ?? 0);
    }

    /** @return list<string> */
    private function codes(string $html): array
    {
        preg_match_all('/aria-label="(MCE-RC-A-\d{3}),/', $html, $m);

        return array_values(array_unique($m[1]));
    }

    public function test_feuille_de_style_du_theme_lumiere(): void
    {
        $css = file_get_contents(public_path('css/raaga.css'));

        $this->assertStringContainsString('.theme-nuit.theme-lumiere {', $css);
        foreach (['--nuit-fond: #FFFFFF;', '--nuit-fond-alt: #F5F7F5;', '--nuit-texte: #16201A;', '--nuit-texte-2: #5E6B63;',
            '--nuit-bordure: #D9E0DB;', '--nuit-bouton: #005C2B;', '--nuit-jaune-texte: #8A6A00;'] as $variable) {
            $this->assertStringContainsString($variable, $css);
        }
        // Pied de page sombre conservé, cases « disponible » du plan claires.
        $this->assertStringContainsString('.theme-lumiere .pied-public,', $css);
        $this->assertStringContainsString('.theme-lumiere .nuit-plan-disponible { --c: #005C2B; --c-fond: #E8F4EC; }', $css);
    }
}
