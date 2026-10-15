<?php

namespace Tests\Feature\Publique;

use App\Enums\CanalPaiement;
use App\Enums\MethodeAllocation;
use App\Enums\ModeSaisie;
use App\Enums\PayeurFrais;
use App\Enums\PublicAutorise;
use App\Enums\RegleArret;
use App\Enums\RegleSiEchec;
use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Enums\StatutTranche;
use App\Enums\TypeFraisSouscription;
use App\Models\AlerteSouscription;
use App\Models\AuditLog;
use App\Models\DecisionPhase;
use App\Models\Tranche;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Compte\CandidatureBoutiqueTest;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/** Deux démarches sur le site public : investir (actions B) et obtenir une boutique. */
class DeuxDemarchesTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    private const ACTIONS = 'Acheter des actions ne donne aucun droit ni priorité pour obtenir une boutique.';

    private const BOUTIQUES = 'Candidater à une boutique ne fait pas de vous un actionnaire.';

    protected function setUp(): void
    {
        parent::setUp();

        // Valeurs initiales : rien n'est ouvert tant qu'aucune décision n'est confirmée.
        config(['raaga.phase.presentation_actions' => false, 'raaga.phase.souscription_actions' => false]);
        Cache::flush();
    }

    private function tranche(StatutTranche $statut = StatutTranche::Ouverte): Tranche
    {
        return Tranche::create([
            'statut' => $statut, 'nom' => 'Tranche 1 – 2026', 'valeur_unitaire' => 10_000, 'min_actions_par_souscription' => 5,
            'plafond_actions_par_personne' => 500, 'objectif_montant' => 3_000_000_000,
            'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addDays(30),
            'publics_autorises' => [PublicAutorise::ResidentBf->value], 'mode_saisie' => ModeSaisie::NbActions, 'souscriptions_multiples' => true,
            'regle_arret' => RegleArret::ArretAObjectif, 'methode_allocation' => MethodeAllocation::Prorata, 'regle_si_echec' => RegleSiEchec::RemboursementIntegral,
            'delai_remboursement_jours' => 30, 'frais_paiement_supportes_par' => PayeurFrais::Raaga,
            'frais_souscription_type' => TypeFraisSouscription::Pourcentage, 'frais_souscription_valeur' => 150,
            'canaux_actifs' => [CanalPaiement::OrangeMoney->value], 'delai_paiement_heures' => 48, 'seuil_kyc_renforce' => 1_000_000,
            'version' => 1, 'cree_par' => User::factory()->create()->id,
        ]);
    }

    /** Le SUPER_ADMIN propose, le VALIDATEUR confirme. */
    private function decider(bool $presentation, bool $souscription): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->post(route('admin.phase.proposer'), ['presentation_actions' => (int) $presentation, 'souscription_actions' => (int) $souscription, 'motif' => 'Décision de la direction'])
            ->assertSessionHas('succes');
        $this->connecterBackoffice(Role::Validateur);
        $this->post(route('admin.phase.confirmer', DecisionPhase::query()->latest('id')->value('id')))->assertSessionHas('succes');
        auth()->logout();
    }

    private function citoyen(): User
    {
        return User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'pays_residence' => 'BF']);
    }

    public function test_souscription_impossible_sans_parametre_confirme_ni_tranche_ouverte(): void
    {
        $this->tranche();
        $citoyen = $this->citoyen();

        // Valeurs initiales (tout fermé) : pages des actions masquées, souscription refusée.
        $this->actingAs($citoyen)->get(route('souscrire'))->assertRedirect(route('boutiques'));
        $this->actingAs($citoyen)->post(route('souscrire.store'), ['nb_actions' => 5])->assertForbidden();
        $this->get(route('offre'))->assertRedirect(route('boutiques'));

        // Proposée seulement : rien ne change avant la confirmation du VALIDATEUR.
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->post(route('admin.phase.proposer'), ['presentation_actions' => 1, 'souscription_actions' => 1, 'motif' => 'Ouverture de la tranche 1'])->assertSessionHas('succes');
        $this->post(route('admin.phase.confirmer', DecisionPhase::query()->value('id')))->assertForbidden();
        auth()->logout();
        $this->get(route('offre'))->assertRedirect(route('boutiques'));

        // Présentation visible, souscription fermée : « Souscription bientôt ouverte ».
        DecisionPhase::query()->update(['statut' => DecisionPhase::REJETEE]);
        $this->decider(true, false);
        $this->get(route('offre'))->assertOk()->assertSee('Souscription bientôt ouverte');
        $this->actingAs($citoyen)->get(route('souscrire'))->assertRedirect(route('souscription.bientot'));
        $this->actingAs($citoyen)->post(route('souscrire.store'), ['nb_actions' => 5])->assertForbidden();
        $this->get('/')->assertSee('Souscription bientôt ouverte')->assertSee('href="'.route('souscription.bientot').'"', false);

        // Souscription ouverte, mais aucune tranche ouverte (seulement confirmée) : toujours fermée.
        $this->decider(true, true);
        Tranche::query()->update(['statut' => StatutTranche::Confirmee]);
        $this->actingAs($citoyen)->get(route('souscrire'))->assertRedirect(route('souscription.bientot'));

        // Paramètre ouvert ET tranche ouverte : souscription possible.
        Tranche::query()->update(['statut' => StatutTranche::Ouverte]);
        $this->actingAs($citoyen)->get(route('souscrire'))->assertOk()->assertSee('Souscrire · Tranche 1 – 2026');
        $accueil = $this->get('/')->assertOk();
        $accueil->assertSeeInOrder(['Devenez actionnaire de votre marché', "Prix de l’action : 10\u{00A0}000\u{00A0}FCFA", "Minimum : 5 actions, soit 50\u{00A0}000\u{00A0}FCFA",
            'Rendement non garanti', 'Je deviens actionnaire', 'Installez votre commerce dans un marché moderne', 'Attribution par tirage au sort', 'Je candidate'], false)
            ->assertSee('href="'.route('souscrire').'"', false);

        $this->assertSame(['phase.proposee', 'phase.proposee', 'phase.confirmee', 'phase.proposee', 'phase.confirmee'],
            AuditLog::query()->where('action', 'like', 'phase.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_la_souscription_ne_s_ouvre_pas_sans_presentation(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->post(route('admin.phase.proposer'), ['presentation_actions' => 0, 'souscription_actions' => 1, 'motif' => 'Essai'])
            ->assertSessionHas('erreur', 'La souscription ne peut être ouverte que si la présentation des actions est visible.');
        $this->assertSame(0, DecisionPhase::query()->count());
        // L'ADMIN et le RÉGULATEUR ne proposent rien.
        foreach ([Role::Admin, Role::Regulateur] as $role) {
            $this->connecterBackoffice($role);
            $this->post(route('admin.phase.proposer'), ['presentation_actions' => 1, 'souscription_actions' => 0, 'motif' => 'Essai'])->assertForbidden();
        }
    }

    public function test_alerte_sms_enregistree_avec_consentement(): void
    {
        $this->decider(true, false);

        $this->get(route('souscription.bientot'))->assertOk()->assertSee('Recevoir l’alerte', false)->assertSee(self::ACTIONS, false);
        $this->post(route('souscription.bientot.store'), ['telephone' => '70 12 34 56'])->assertSessionHasErrors(['consentement' => 'Cochez la case pour accepter de recevoir le SMS.']);
        $this->post(route('souscription.bientot.store'), ['telephone' => '1234', 'consentement' => '1'])->assertSessionHasErrors('telephone');
        $this->assertSame(0, AlerteSouscription::query()->count());

        $this->post(route('souscription.bientot.store'), ['telephone' => '70 12 34 56', 'consentement' => '1'])->assertRedirect(route('souscription.bientot'));
        $this->post(route('souscription.bientot.store'), ['telephone' => '+226 70123456', 'consentement' => '1'])->assertRedirect(route('souscription.bientot'));
        $alerte = AlerteSouscription::query()->sole();
        $this->assertSame('+22670123456', $alerte->telephone);
        $this->assertNotNull($alerte->consentement_le);
        $this->followingRedirects()->post(route('souscription.bientot.store'), ['telephone' => '70123456', 'consentement' => '1'])
            ->assertSee('C’est noté : vous recevrez un SMS dès l’ouverture de la souscription.', false);
    }

    public function test_encadres_sur_les_deux_parcours(): void
    {
        $this->tranche();
        $this->decider(true, true);
        $citoyen = $this->citoyen();

        foreach ([route('offre'), route('transparence'), route('souscrire.reglement')] as $url) {
            $this->get($url)->assertOk()->assertSee(self::ACTIONS, false)->assertDontSee(self::BOUTIQUES, false);
        }
        $this->actingAs($citoyen)->get(route('souscrire'))->assertOk()->assertSee('data-demarche="actions"', false)->assertSee(self::ACTIONS, false);
        $this->actingAs($citoyen)->get(route('paiement'))->assertOk()->assertSee(self::ACTIONS, false);

        CandidatureBoutiqueTest::campagneOuverte();
        foreach ([route('boutiques'), route('boutiques.reglement'), route('boutiques.candidater')] as $url) {
            $this->actingAs($citoyen)->get($url)->assertOk()->assertSee('data-demarche="boutiques"', false)->assertSee(self::BOUTIQUES, false)->assertDontSee('data-demarche="actions"', false);
        }
    }

    public function test_menu_et_mon_espace_separes(): void
    {
        $citoyen = $this->citoyen();

        // Présentation masquée : seule la démarche « boutiques ».
        $menu = fn () => preg_match('#<nav aria-label="Menu principal".*?</nav>#s', $this->get('/')->getContent(), $m) ? $m[0] : '';
        $this->assertStringNotContainsString('Investir', $menu());
        $this->assertStringContainsString('href="'.route('boutiques').'"', $menu());
        $this->actingAs($citoyen)->get(route('espace'))->assertOk()->assertSee('Mes boutiques')->assertDontSee('Mes actions');

        $this->decider(true, false);
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('offre'), '#').'"[^>]*>(?:(?!</a>).)*?Investir\s*</a>.*href="'.preg_quote(route('boutiques'), '#').'"[^>]*>(?:(?!</a>).)*?Boutiques\s*</a>#s', $menu());

        $espace = $this->actingAs($citoyen)->get(route('espace'))->assertOk();
        $espace->assertSeeInOrder(['Mes actions', 'Mes boutiques', 'Mon profil'])
            ->assertSee('href="'.route('espace', ['onglet' => 'actions']).'"', false)->assertSee('href="'.route('espace', ['onglet' => 'boutiques']).'"', false)
            ->assertSeeInOrder(['Mon attestation et mes documents', 'Dividendes reçus', 'Compte de versement des dividendes', 'Bénéficiaire désigné'])->assertSee('Total investi')->assertDontSee('Cautions payées');
        $this->actingAs($citoyen)->get(route('espace', ['onglet' => 'boutiques']))->assertSee('Cautions payées')->assertDontSee('Total investi')->assertDontSee('Mes souscriptions');
        // Anciennes adresses des rubriques : onglet « Mes actions ».
        $this->assertMatchesRegularExpression('#aria-current="page"[^>]*>(?:(?!</a>).)*?Mes actions#s', $this->actingAs($citoyen)->get(route('espace', ['onglet' => 'documents']))->getContent());
    }
}
