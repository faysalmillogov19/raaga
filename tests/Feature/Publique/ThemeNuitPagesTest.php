<?php

namespace Tests\Feature\Publique;

use App\Enums\MethodeAllocation;
use App\Enums\ModeSaisie;
use App\Enums\PayeurFrais;
use App\Enums\PublicAutorise;
use App\Enums\RegleArret;
use App\Enums\RegleSiEchec;
use App\Enums\Role;
use App\Enums\StatutCarte;
use App\Enums\StatutKyc;
use App\Enums\StatutTranche;
use App\Enums\TypeCarte;
use App\Enums\TypeFraisSouscription;
use App\Models\Carte;
use App\Models\Duree;
use App\Models\Tranche;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\ParametresInvestissementConfirmes;
use Tests\TestCase;

/**
 * Thème « nuit », lot 3 : compte (connexion, inscription), espace citoyen et ses onglets, profil, vérification
 * d'identité, carte d'accès (rendu inchangé), RAAGA Invest (offre, simulateur, souscription, accents jaunes),
 * transparence, pages d'information, pages d'erreur et maintenance. Thème « clair » : rendu historique.
 */
class ThemeNuitPagesTest extends TestCase
{
    use ParametresInvestissementConfirmes, RefreshDatabase;

    private function citoyen(StatutKyc $kyc = StatutKyc::Valide): User
    {
        return User::factory()->role(Role::Citoyen)->create(['name' => 'Awa OUEDRAOGO', 'prenoms' => 'Awa', 'nom' => 'OUEDRAOGO',
            'statut_kyc' => $kyc, 'pays_residence' => 'BF']);
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

    public function test_connexion_et_inscription_carte_sombre_puis_clair(): void
    {
        config(['raaga.theme_public' => 'nuit']);
        foreach ([route('connexion'), route('inscription')] as $url) {
            $this->get($url)->assertOk()->assertSee('nuit-page', false)->assertSee('compte-carte', false)
                ->assertSee('<div class="mb-5 text-center">', false);
        }

        config(['raaga.theme_public' => 'clair']);
        $this->get(route('connexion'))->assertOk()->assertDontSee('nuit-page', false)->assertDontSee('<div class="mb-5 text-center">', false);
    }

    public function test_pages_d_information_en_bandeau_sombre(): void
    {
        foreach (['faq', 'guichets', 'contact', 'legal.mentions', 'legal.donnees', 'legal.accessibilite'] as $route) {
            $this->get(route($route))->assertOk()->assertSee('nuit-page', false)->assertSee('nuit-bandeau-titre', false);
        }
        $this->get(route('faq'))->assertSee('Aide &amp; <strong>FAQ</strong>', false);

        config(['raaga.theme_public' => 'clair']);
        $this->get(route('faq'))->assertOk()->assertDontSee('nuit-bandeau', false)->assertSee('border-b border-ligne bg-fond', false);
    }

    public function test_espace_citoyen_onglets_et_statuts(): void
    {
        $awa = $this->citoyen();
        foreach (['actions', 'boutiques', 'profil'] as $onglet) {
            $this->actingAs($awa)->get(route('espace', ['onglet' => $onglet]))->assertOk()
                ->assertSee('nuit-page', false)->assertSee('<h1 class="nuit-bandeau-titre">Bonjour, <strong>Awa</strong></h1>', false)
                ->assertSee('onglet-espace', false)->assertSee('est-actif', false);
        }

        // Identité rejetée : pastille et lien de vérification.
        $this->actingAs($this->citoyen(StatutKyc::Rejete))->get(route('espace'))->assertOk()
            ->assertSee('bg-rouge-pale text-rouge', false)->assertSee(route('espace.verification'), false);

        config(['raaga.theme_public' => 'clair']);
        $this->actingAs($awa)->get(route('espace'))->assertOk()->assertDontSee('nuit-bandeau', false)->assertSee('<h1>Bonjour, Awa</h1>', false);
    }

    public function test_profil_verification_et_carte_d_acces(): void
    {
        Notification::fake();
        $awa = $this->citoyen();
        $this->actingAs($awa)->get(route('espace.profil'))->assertOk()->assertSee('nuit-page', false)->assertSee('nuit-bandeau', false);
        $this->actingAs($this->citoyen(StatutKyc::NonSoumis))->get(route('espace.verification'))->assertOk()
            ->assertSee('nuit-page', false)->assertSee('type="file"', false);

        $carte = Carte::create(['type' => TypeCarte::Commercant, 'user_id' => $awa->id, 'statut' => StatutCarte::Active, 'courante' => true,
            'jeton' => Str::random(64), 'emise_le' => now(), 'valide_jusqu_au' => now()->addYears(2)->toDateString(),
            'contenu' => ['nom' => 'Awa OUEDRAOGO', 'emplacements' => []]]);
        $carte->forceFill(['numero' => sprintf('RG-C-%06d', $carte->id)])->save();

        // La carte Raaga garde son rendu : c'est un document.
        $this->actingAs($awa)->get(route('espace.carte', ['type' => 'commercant']))->assertOk()
            ->assertSee('nuit-page', false)->assertSee('carte-raaga', false);
        $this->app['auth']->forgetGuards();
        $this->get(route('cartes.verifier', $carte->jeton))->assertOk()->assertSee('nuit-page', false)->assertSee('verif-carte-valide', false);
    }

    public function test_raaga_invest_accents_jaunes(): void
    {
        $this->trancheOuverte();

        foreach ([route('offre'), route('invest.simulateur'), route('transparence')] as $url) {
            $this->get($url)->assertOk()->assertSee('nuit-page', false)->assertSee('nuit-investir', false);
        }
        $this->get(route('transparence'))->assertSee('carte-blanche', false)->assertSee('role="progressbar"', false);

        $this->actingAs($this->citoyen())->get(route('souscrire'))->assertOk()
            ->assertSee('class="nuit-investir grid', false)->assertSee('nuit-bandeau', false);

        // Hors démarche « Investir » : pas d'accent jaune.
        $this->get(route('faq'))->assertDontSee('nuit-investir', false);

        config(['raaga.theme_public' => 'clair']);
        $this->get(route('offre'))->assertOk()->assertDontSee('nuit-investir', false);
    }

    public function test_souscription_bientot(): void
    {
        config(['raaga.phase.presentation_actions' => true, 'raaga.phase.souscription_actions' => false]);
        Cache::flush();

        $this->get(route('souscription.bientot'))->assertOk()->assertSee('nuit-page', false)->assertSee('nuit-investir', false);
    }

    public function test_pages_d_erreur_et_maintenance(): void
    {
        $this->get('/page-inexistante')->assertNotFound()->assertSee('theme-nuit', false)
            ->assertSee('class="flex flex-1 items-center nuit-page"', false)->assertSee('cadre-erreur-entete', false);

        $this->get('/')->assertOk();
        $html = view('public.maintenance', ['message' => 'Mise à jour en cours.', 'finPrevue' => null])->render();
        $this->assertStringContainsString('nuit-page', $html);

        config(['raaga.theme_public' => 'clair']);
        $this->get('/page-inexistante')->assertNotFound()->assertDontSee('theme-nuit', false)->assertDontSee('nuit-page', false);
    }
}
