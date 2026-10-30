<?php

namespace Tests\Feature\Publique;

use App\Enums\CanalSouscription;
use App\Enums\MethodeAllocation;
use App\Enums\ModeSaisie;
use App\Enums\PayeurFrais;
use App\Enums\PublicAutorise;
use App\Enums\RegleArret;
use App\Enums\RegleSiEchec;
use App\Enums\Role;
use App\Enums\StatutSouscription;
use App\Enums\StatutTranche;
use App\Enums\TypeFraisSouscription;
use App\Models\Duree;
use App\Models\ManifestationInteret;
use App\Models\Paiement;
use App\Models\ParametresInvestissement;
use App\Models\Souscription;
use App\Models\Tranche;
use App\Models\User;
use App\Services\Invest\RaagaInvest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/**
 * RAAGA Invest : paramètres saisis par le RÉGULATEUR et confirmés par le VALIDATEUR, simulateur retiré (montant, part de
 * l'objectif de collecte), jauge qui dépasse 100 %, manifestation d'intérêt sans paiement, droits par profil et
 * textes obligatoires.
 */
class RaagaInvestTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private const RENDEMENT = 'Le rendement n\'est pas garanti.';

    private const BOUTIQUE = 'La détention d\'actions ne donne aucun droit automatique à une boutique.';

    protected function setUp(): void
    {
        parent::setUp();
        config(['raaga.phase.presentation_actions' => true, 'raaga.phase.souscription_actions' => false]);
        Cache::flush();
        $this->creerActeurs();
    }

    /** Le RÉGULATEUR saisit et soumet, le VALIDATEUR confirme dans « À valider › Données financières ». */
    private function confirmer(int $objectif = 1_000_000_000, int $valeur = 10_000, ?int $plafond = null, int $minimum = 1): void
    {
        $this->en($this->regulateur)->put(route('admin.investissement.update'), ['objectif_collecte' => $objectif, 'valeur_action' => $valeur, 'minimum_actions' => $minimum, 'plafond_global' => $plafond])
            ->assertSessionHas('succes');
        $this->en($this->regulateur)->post(route('admin.investissement.soumettre'))->assertSessionHas('succes');

        $fiche = ParametresInvestissement::fiche();
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'finances-sites'))->assertOk()->assertSee('investissement:'.$fiche->id);
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['investissement:'.$fiche->id], 'decision' => 'confirmer', 'mode' => 'ligne'])
            ->assertSessionHas('succes');
        auth()->logout();
    }

    private function citoyen(): User
    {
        return User::factory()->role(Role::Citoyen)->create();
    }

    public function test_sans_parametres_confirmes_pas_de_manifestation(): void
    {
        $this->assertNull(RaagaInvest::parametres());
        $this->assertNull(RaagaInvest::jauge());
        $this->get(route('offre'))->assertOk()->assertDontSee('id="interet"', false)->assertDontSee('data-formulaire-interet', false);

        // Saisis mais pas encore confirmés : toujours rien.
        $this->en($this->regulateur)->put(route('admin.investissement.update'), ['objectif_collecte' => 1_000_000, 'valeur_action' => 5_000, 'minimum_actions' => 1])->assertSessionHas('succes');
        auth()->logout();
        $this->assertNull(RaagaInvest::parametres());

        $this->actingAs($this->citoyen())->post(route('invest.interet'), ['profil' => 'RESIDENT', 'pays_residence' => 'Burkina Faso', 'nombre_actions' => 10, 'simulation_comprise' => '1'])
            ->assertSessionHasErrors('nombre_actions');
        $this->assertSame(0, ManifestationInteret::query()->count());
    }

    public function test_le_simulateur_est_retire(): void
    {
        $this->confirmer(objectif: 2_000_000_000, valeur: 10_000, plafond: 2_500_000_000);

        // Montant indicatif d'une manifestation d'intérêt : nombre d'actions × valeur.
        $this->assertSame(['nombre' => 50, 'valeur_action' => 10_000, 'montant' => 500_000, 'part_objectif' => 0.025], RaagaInvest::simuler(50));
        $this->assertNull(RaagaInvest::simuler(0));

        // Ancienne adresse : renvoi vers la manifestation d'intérêt de l'offre ; plus aucun lien ni mention.
        $this->get('/invest/simulateur')->assertRedirect(route('offre').'#interet');
        foreach ([route('accueil'), route('offre')] as $url) {
            $this->get($url)->assertOk()->assertDontSee('Simuler mon investissement')->assertDontSee('/invest/simulateur', false);
        }
    }

    public function test_la_jauge_depasse_l_objectif(): void
    {
        $this->confirmer(objectif: 250_000, valeur: 10_000);
        $this->assertSame('0 %', str_replace("\u{00A0}", ' ', RaagaInvest::jauge()['libelle']));

        $tranche = $this->tranche();
        $awa = $this->citoyen();
        foreach ([[StatutSouscription::Payee, 20], [StatutSouscription::Allouee, 8], [StatutSouscription::Reservee, 50]] as $i => [$statut, $actions]) {
            Souscription::create(['reference' => 'RAA-B-INV0000'.$i, 'user_id' => $awa->id, 'tranche_id' => $tranche->id, 'nb_actions_demandees' => $actions,
                'valeur_unitaire' => 10_000, 'montant' => $actions * 10_000, 'frais_souscription' => 0, 'canal' => CanalSouscription::Web,
                'statut' => $statut, 'expire_le' => now()->addDay()]);
        }

        // 280 000 FCFA payés (l'attente de paiement ne compte pas) pour 250 000 d'objectif : 112 %, barre pleine.
        $jauge = RaagaInvest::jauge();
        $this->assertSame(280_000, $jauge['collecte']);
        $this->assertSame('112 %', str_replace("\u{00A0}", ' ', $jauge['libelle']));
        $this->assertSame(100.0, $jauge['largeur']);
        $this->get(route('accueil'))->assertOk()->assertSee('data-jauge-invest', false)->assertSee("112\u{00A0}% de l’objectif")
            ->assertSee('Valeur indicative de l’action : '.format_fcfa(10_000));
    }

    public function test_manifestation_d_interet_sans_paiement(): void
    {
        $this->confirmer(objectif: 1_000_000_000, valeur: 10_000);
        $citoyen = $this->citoyen();

        $this->actingAs($citoyen)->get(route('offre'))->assertSee('id="interet"', false)->assertSee('data-formulaire-interet', false)
            ->assertSee('Je comprends que cette démarche ne m’engage pas et ne réserve aucune action')->assertDontSee('simulation non engageante');

        // La case est obligatoire.
        $this->actingAs($citoyen)->post(route('invest.interet'), ['profil' => 'DIASPORA', 'pays_residence' => 'France', 'nombre_actions' => 30])
            ->assertSessionHasErrors('simulation_comprise');
        $this->assertSame(0, ManifestationInteret::query()->count());

        $this->actingAs($citoyen)->post(route('invest.interet'), ['profil' => 'DIASPORA', 'pays_residence' => 'France', 'nombre_actions' => 30, 'simulation_comprise' => '1'])
            ->assertRedirect(route('espace', ['onglet' => 'interets']))->assertSessionHas('succes');

        $m = ManifestationInteret::query()->sole();
        $this->assertMatchesRegularExpression('/^INV-\d{4}-[A-Z0-9]{6}$/', $m->reference);
        $this->assertSame([30, 300_000, 'France'], [$m->nombre_actions, $m->montant, $m->pays_residence]);
        // Aucun paiement, aucune souscription.
        $this->assertSame(0, Paiement::query()->count());
        $this->assertSame(0, Souscription::query()->count());

        $this->actingAs($citoyen)->get(route('espace', ['onglet' => 'interets']))->assertOk()
            ->assertSee($m->reference)->assertSee(format_fcfa(300_000))->assertSee(self::RENDEMENT, false)->assertSee(self::BOUTIQUE, false);

        // Visiteur non connecté : lien de connexion, aucun enregistrement possible.
        auth()->logout();
        $this->get(route('offre'))->assertDontSee('data-formulaire-interet', false)->assertSee('Connectez-vous pour manifester votre intérêt');
        $this->post(route('invest.interet'), ['profil' => 'RESIDENT', 'pays_residence' => 'Burkina Faso', 'nombre_actions' => 5, 'simulation_comprise' => '1'])
            ->assertRedirect();
        $this->assertSame(1, ManifestationInteret::query()->count());
    }

    public function test_textes_obligatoires_mot_pour_mot_et_nom_du_portail(): void
    {
        $this->confirmer();

        foreach ([route('offre')] as $url) {
            $page = $this->get($url)->assertOk();
            $page->assertSee(self::RENDEMENT, false)->assertSee(self::BOUTIQUE, false)->assertSee('· RAAGA Invest</title>', false)->assertSee('data-portail', false);
            foreach (['UMOA', 'AMF', 'part du capital'] as $interdit) {
                $page->assertDontSee($interdit, false);
            }
        }

        // Le reste de la plateforme garde son nom.
        $this->get(route('accueil'))->assertOk()->assertDontSee('data-portail', false)->assertDontSee('RAAGA Invest</title>', false);
    }

    public function test_droits_par_profil(): void
    {
        $this->confirmer(valeur: 10_000);
        ManifestationInteret::create(['user_id' => $this->citoyen()->id, 'profil' => 'ENTREPRISE', 'pays_residence' => 'Burkina Faso',
            'nombre_actions' => 12, 'valeur_action' => 10_000, 'montant' => 120_000, 'simulation_comprise_le' => now()]);

        // RÉGULATEUR : liste, export et indicateurs du tableau de bord.
        $this->en($this->regulateur)->get(route('admin.interets'))->assertOk()->assertSee('INV-');
        $this->en($this->regulateur)->get(route('admin.interets', ['export' => 'csv']))->assertOk();
        $this->en($this->regulateur)->get(route('admin.tableau'))->assertOk()->assertSee('data-indicateurs-invest', false)->assertSee(format_fcfa(120_000));

        // SUPER_ADMIN et ADMIN (depuis le 25/10/2026) : ni liste, ni export, ni indicateurs, ni paramètres d'investissement.
        foreach ([$this->superAdmin, User::factory()->role(Role::Admin)->create()] as $user) {
            $this->en($user)->get(route('admin.interets'))->assertForbidden();
            $this->en($user)->get(route('admin.interets', ['export' => 'csv']))->assertForbidden();
            $this->en($user)->get(route('admin.investissement'))->assertForbidden();
            $this->en($user)->get(route('admin.tableau'))->assertOk()->assertDontSee('data-indicateurs-invest', false);
        }

        // GESTIONNAIRE : ni liste, ni paramètres, ni indicateurs.
        $gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->en($gestionnaire)->get(route('admin.interets'))->assertForbidden();
        $this->en($gestionnaire)->get(route('admin.investissement'))->assertForbidden();
        $this->en($gestionnaire)->put(route('admin.investissement.update'), ['objectif_collecte' => 1, 'valeur_action' => 1])->assertForbidden();
        $this->en($gestionnaire)->get(route('admin.tableau'))->assertOk()->assertDontSee('data-indicateurs-invest', false);

        // VALIDATEUR : confirme mais ne saisit pas ; RÉGULATEUR : saisit mais ne confirme pas.
        $this->en($this->validateur)->put(route('admin.investissement.update'), ['objectif_collecte' => 1, 'valeur_action' => 1])->assertForbidden();
        $this->en($this->regulateur)->post(route('admin.investissement.confirmer'))->assertForbidden();
        $this->en($this->validateur)->get(route('admin.interets'))->assertForbidden();
    }

    private function tranche(): Tranche
    {
        return Tranche::create([
            'statut' => StatutTranche::Ouverte, 'nom' => 'Tranche 1 – 2026', 'valeur_unitaire' => 10_000, 'min_actions_par_souscription' => 5,
            'plafond_actions_par_personne' => 500, 'objectif_montant' => 3_000_000_000,
            'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addDays(30),
            'publics_autorises' => [PublicAutorise::ResidentBf->value], 'mode_saisie' => ModeSaisie::NbActions, 'souscriptions_multiples' => true,
            'regle_arret' => RegleArret::ArretAObjectif, 'methode_allocation' => MethodeAllocation::Prorata, 'regle_si_echec' => RegleSiEchec::RemboursementIntegral,
            'delai_remboursement_jours' => 30, 'frais_paiement_supportes_par' => PayeurFrais::Raaga,
            'frais_souscription_type' => TypeFraisSouscription::Pourcentage, 'frais_souscription_valeur' => 150,
            'canaux_actifs' => ['ORANGE_MONEY'], 'delai_paiement_valeur' => 48, 'delai_paiement_duree_id' => Duree::parCode('HEURE')->id, 'delai_paiement_secondes' => 172_800, 'seuil_kyc_renforce' => 1_000_000,
            'version' => 1, 'cree_par' => $this->superAdmin->id,
        ]);
    }
}
