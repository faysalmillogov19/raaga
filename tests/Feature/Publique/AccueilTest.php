<?php

namespace Tests\Feature\Publique;

use App\Enums\CanalPaiement;
use App\Enums\CanalSouscription;
use App\Enums\MethodeAllocation;
use App\Enums\ModeSaisie;
use App\Enums\PayeurFrais;
use App\Enums\PublicAutorise;
use App\Enums\RegleArret;
use App\Enums\RegleSiEchec;
use App\Enums\StatutSouscription;
use App\Enums\StatutTranche;
use App\Enums\TypeFraisSouscription;
use App\Models\Souscription;
use App\Models\Tranche;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AccueilTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_sans_tranche_ouverte_ni_mode_demo_l_accueil_l_indique(): void
    {
        config(['raaga.demo' => false]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Aucune tranche n’est ouverte', false)
            ->assertDontSee('Je souscris maintenant')
            ->assertDontSee('données fictives');
    }

    public function test_en_mode_demo_les_donnees_fictives_s_affichent_sans_bandeau(): void
    {
        config(['raaga.demo' => true]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Maquette de démonstration')
            ->assertSee("1\u{00A0}284\u{00A0}650\u{00A0}000\u{00A0}FCFA", false)
            ->assertSee('Actualités');
    }

    public function test_les_valeurs_viennent_des_parametres_de_la_tranche_ouverte(): void
    {
        config(['raaga.demo' => false]);

        $tranche = $this->trancheOuverte(['valeur_unitaire' => 5_000, 'plafond_actions_par_personne' => 200]);
        $citoyen = User::factory()->create();

        foreach ([[StatutSouscription::Payee, 10], [StatutSouscription::Payee, 5], [StatutSouscription::Reservee, 100]] as $i => [$statut, $nb]) {
            Souscription::create([
                'reference' => 'TEST-'.$i,
                'user_id' => $citoyen->id,
                'tranche_id' => $tranche->id,
                'nb_actions_demandees' => $nb,
                'valeur_unitaire' => 5_000,
                'montant' => $nb * 5_000,
                'canal' => CanalSouscription::Web,
                'statut' => $statut,
                'expire_le' => now()->addDay(),
            ]);
        }

        $this->get('/')
            ->assertOk()
            ->assertSee("5\u{00A0}000\u{00A0}FCFA", false)
            ->assertSee('dans la limite de 200 actions par personne')
            // Seules les souscriptions payées comptent : 15 actions × 5 000 = 75 000.
            ->assertSee("75\u{00A0}000\u{00A0}FCFA", false)
            ->assertSee('Je souscris maintenant')
            ->assertSee(url('/inscription'));
    }

    public function test_un_citoyen_connecte_est_envoye_vers_la_souscription(): void
    {
        config(['raaga.demo' => true]);

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertSee(url('/souscrire'));
    }

    public function test_l_entree_accueil_du_menu_est_active(): void
    {
        $this->get('/')->assertSee('aria-current="page"', false);
    }

    private function trancheOuverte(array $parametres = []): Tranche
    {
        return Tranche::create([
            'statut' => StatutTranche::Ouverte,
            'nom' => 'Tranche test',
            'valeur_unitaire' => 10_000,
            'objectif_montant' => 3_000_000_000,
            'date_ouverture' => now()->subDay(),
            'date_cloture' => now()->addDays(30),
            'publics_autorises' => [PublicAutorise::ResidentBf->value],
            'mode_saisie' => ModeSaisie::NbActions,
            'regle_arret' => RegleArret::ArretAObjectif,
            'methode_allocation' => MethodeAllocation::Prorata,
            'regle_si_echec' => RegleSiEchec::RemboursementIntegral,
            'delai_remboursement_jours' => 30,
            'frais_paiement_supportes_par' => PayeurFrais::Raaga,
            'frais_souscription_type' => TypeFraisSouscription::Aucun,
            'canaux_actifs' => [CanalPaiement::OrangeMoney->value],
            'cree_par' => User::factory()->create()->id,
            ...$parametres,
        ]);
    }
}
