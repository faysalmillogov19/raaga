<?php

namespace Tests\Feature;

use App\Enums\MethodeAllocation;
use App\Enums\ModeSaisie;
use App\Enums\PayeurFrais;
use App\Enums\PublicAutorise;
use App\Enums\RegleArret;
use App\Enums\RegleSiEchec;
use App\Enums\StatutInscriptionBoutique;
use App\Enums\StatutReservationBoutique;
use App\Enums\StatutSouscription;
use App\Enums\StatutTranche;
use App\Enums\TypeFraisSouscription;
use App\Models\Duree;
use App\Models\InscriptionBoutique;
use App\Models\KycDocument;
use App\Models\LigneRegistre;
use App\Models\Paiement;
use App\Models\ReservationBoutique;
use App\Models\Souscription;
use App\Models\Tranche;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * raaga:simulation : citoyens fictifs à chaque étape des parcours, sur le poste de développement seulement.
 */
class SimulationRecetteTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    public function test_refus_hors_poste_local_avant_tout_acces_a_la_base(): void
    {
        foreach (['production', 'testing'] as $environnement) {
            $this->app['env'] = $environnement;
            $requetes = 0;
            DB::listen(function () use (&$requetes) {
                $requetes++;
            });
            $this->assertSame(1, Artisan::call('raaga:simulation'));
            $this->assertStringContainsString('ne tourne que sur un poste de développement', Artisan::output());
            $this->assertSame(0, $requetes);
        }
    }

    public function test_tous_les_scenarios_puis_idempotente(): void
    {
        Storage::fake('local');
        $this->creerActeurs();
        Boutiques::campagneOuverte(petites: 8);
        Tranche::create([
            'statut' => StatutTranche::Ouverte, 'nom' => 'Tranche 1', 'valeur_unitaire' => 10_000, 'min_actions_par_souscription' => 1,
            'objectif_montant' => 1_000_000_000, 'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addDays(30),
            'publics_autorises' => [PublicAutorise::ResidentBf->value], 'mode_saisie' => ModeSaisie::NbActions, 'souscriptions_multiples' => true,
            'regle_arret' => RegleArret::ArretAObjectif, 'methode_allocation' => MethodeAllocation::Prorata, 'regle_si_echec' => RegleSiEchec::RemboursementIntegral,
            'delai_remboursement_jours' => 30, 'frais_paiement_supportes_par' => PayeurFrais::Raaga, 'frais_souscription_type' => TypeFraisSouscription::Aucun,
            'frais_souscription_valeur' => 0, 'canaux_actifs' => ['ORANGE_MONEY', 'VIREMENT'], 'delai_paiement_valeur' => 48, 'delai_paiement_duree_id' => Duree::parCode('HEURE')->id,
            'delai_paiement_secondes' => 172_800, 'version' => 1, 'cree_par' => $this->regulateur->id,
        ]);
        $this->app['env'] = 'local';

        $this->artisan('raaga:simulation')->assertSuccessful()->expectsOutputToContain('/recette/sms');
        $u = fn (string $local) => User::query()->where('telephone', '+226'.$local)->sole();

        // Actions.
        $this->assertSame(StatutSouscription::Reservee, Souscription::query()->where('user_id', $u('60000001')->id)->sole()->statut);
        $this->assertSame(StatutSouscription::Payee, Souscription::query()->where('user_id', $u('60000002')->id)->sole()->statut);
        $this->assertSame(1, LigneRegistre::query()->where('user_id', $u('60000003')->id)->count());
        $this->assertNotNull($u('60000004')->kyc_actions_a_corriger_avant);
        $this->assertTrue($u('60000005')->actionsGelees());
        $this->assertSame(0, Souscription::query()->where('user_id', $u('60000006')->id)->count());
        // Boutiques.
        $statut = fn (string $local) => ReservationBoutique::query()->where('user_id', $u($local)->id)->sole();
        $this->assertSame(StatutReservationBoutique::PreReservee, $statut('60000011')->statut);
        $this->assertSame([1, 2], [InscriptionBoutique::query()->where('user_id', $u('60000012')->id)->sole()->rang(), InscriptionBoutique::query()->where('user_id', $u('60000013')->id)->sole()->rang()]);
        $this->assertSame(StatutInscriptionBoutique::EnAttente, InscriptionBoutique::query()->where('user_id', $u('60000013')->id)->sole()->statut);
        $this->assertNull($statut('60000014')->avance_payee_le);
        $this->assertSame(StatutReservationBoutique::Reservee, $statut('60000014')->statut);
        $this->assertNotNull($statut('60000015')->avance_payee_le);
        $this->assertSame(StatutReservationBoutique::Attribuee, $statut('60000016')->statut);
        $this->assertNotNull($statut('60000017')->kyc_a_corriger_avant);
        // Preuves de virement en contrôle (souscription et caution), dossier d'identité à examiner.
        $this->assertSame(2, Paiement::query()->enControle()->count());
        $this->assertSame(3, KycDocument::query()->where('user_id', $u('60000021')->id)->count());

        // Relancée : rien de plus.
        $citoyens = User::query()->count();
        $this->artisan('raaga:simulation')->assertSuccessful()->expectsOutputToContain('déjà créé');
        $this->assertSame($citoyens, User::query()->count());
    }
}
