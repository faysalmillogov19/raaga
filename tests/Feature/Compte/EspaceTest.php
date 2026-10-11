<?php

namespace Tests\Feature\Compte;

use App\Enums\CanalPaiement;
use App\Enums\CanalSouscription;
use App\Enums\DocumentAccepte;
use App\Enums\MethodeAllocation;
use App\Enums\ModeSaisie;
use App\Enums\PayeurFrais;
use App\Enums\PublicAutorise;
use App\Enums\RegleArret;
use App\Enums\RegleSiEchec;
use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Enums\StatutPaiement;
use App\Enums\StatutSouscription;
use App\Enums\StatutTranche;
use App\Enums\TypeFraisSouscription;
use App\Models\Acceptation;
use App\Models\LigneRegistre;
use App\Models\Souscription;
use App\Models\Tranche;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EspaceTest extends TestCase
{
    use RefreshDatabase;

    private Tranche $tranche;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tranche = Tranche::create([
            'statut' => StatutTranche::Ouverte, 'nom' => 'Tranche 1 – 2026', 'valeur_unitaire' => 10_000,
            'min_actions_par_souscription' => 1, 'objectif_montant' => 3_000_000_000,
            'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addDays(30),
            'publics_autorises' => [PublicAutorise::ResidentBf->value], 'mode_saisie' => ModeSaisie::NbActions,
            'souscriptions_multiples' => true, 'regle_arret' => RegleArret::ArretAObjectif, 'methode_allocation' => MethodeAllocation::Prorata,
            'regle_si_echec' => RegleSiEchec::RemboursementIntegral, 'delai_remboursement_jours' => 30,
            'frais_paiement_supportes_par' => PayeurFrais::Raaga, 'frais_souscription_type' => TypeFraisSouscription::Aucun,
            'canaux_actifs' => [CanalPaiement::OrangeMoney->value], 'delai_paiement_heures' => 48, 'version' => 2,
            'cree_par' => User::factory()->create()->id,
        ]);
    }

    private function citoyen(string $prenoms, string $telephone, StatutKyc $kyc = StatutKyc::Valide): User
    {
        return User::factory()->role(Role::Citoyen)->create([
            'name' => $prenoms.' KABORÉ', 'prenoms' => $prenoms, 'nom' => 'KABORÉ', 'telephone' => $telephone,
            'statut_kyc' => $kyc, 'pays_residence' => 'BF', 'region' => 'Centre',
        ]);
    }

    private function souscription(User $user, string $reference, StatutSouscription $statut, int $actions, array $attributs = []): Souscription
    {
        $s = Souscription::create([
            'reference' => $reference, 'user_id' => $user->id, 'tranche_id' => $this->tranche->id,
            'nb_actions_demandees' => $actions, 'valeur_unitaire' => 10_000, 'montant' => $actions * 10_000, 'frais_souscription' => 0,
            'canal' => CanalSouscription::Web, 'statut' => $statut, 'expire_le' => now()->addHours(48), ...$attributs,
        ]);

        if (in_array($statut, [StatutSouscription::Payee, StatutSouscription::Allouee], true)) {
            $s->paiements()->create(['canal' => CanalPaiement::OrangeMoney, 'montant' => $s->montant, 'frais' => 0,
                'reference' => 'PAY-'.$reference, 'statut' => StatutPaiement::Confirme, 'date_confirmation' => now()]);
            Acceptation::create(['user_id' => $user->id, 'souscription_id' => $s->id, 'document' => DocumentAccepte::ReglementTranche,
                'version_document' => '2', 'accepte_le' => now(), 'ip' => '127.0.0.1', 'user_agent' => 'test']);
        }

        return $s;
    }

    public function test_les_indicateurs_et_l_en_tete(): void
    {
        $awa = $this->citoyen('Awa', '+22670111111');
        $this->souscription($awa, 'RAA-B-PAYEE001', StatutSouscription::Payee, 25);
        $this->souscription($awa, 'RAA-B-ALLOUE01', StatutSouscription::Allouee, 10, ['nb_actions_allouees' => 8, 'montant_a_rembourser' => 20_000]);
        $this->souscription($awa, 'RAA-B-RESERVE1', StatutSouscription::Reservee, 100);
        $this->souscription($awa, 'RAA-B-EXPIREE1', StatutSouscription::Expiree, 50);

        $page = $this->actingAs($awa)->get('/espace')->assertOk()
            ->assertSee('Bonjour, Awa')
            ->assertSee('Validée')
            ->assertDontSee('Vérifier mon identité')
            // Total investi = 250 000 (payée) + 100 000 − 20 000 remboursés (allouée) ; la réservation et l'expirée ne comptent pas.
            ->assertSeeInOrder(['Total investi', "330\u{00A0}000\u{00A0}FCFA", 'Actions en attente d’allocation', '25', 'Actions allouées', '8', 'Dividendes reçus', "0\u{00A0}FCFA"]);

        // Statuts écrits en toutes lettres.
        foreach (['Payée', 'Allouée', 'Réservée', 'Expirée'] as $statut) {
            $page->assertSee($statut);
        }

        $this->actingAs($this->citoyen('Issa', '+22670222222', StatutKyc::Rejete))->get('/espace')->assertOk()
            ->assertSee('Rejetée')->assertSee('href="'.route('espace.verification').'"', false)
            ->assertSee('Aucune souscription pour le moment');
    }

    public function test_une_reservation_non_payee_propose_payer_maintenant_tant_qu_elle_n_a_pas_expire(): void
    {
        $awa = $this->citoyen('Awa', '+22670111111');
        $this->souscription($awa, 'RAA-B-RESERVE1', StatutSouscription::Reservee, 10);
        $this->souscription($awa, 'RAA-B-RESERVE2', StatutSouscription::Reservee, 5, ['expire_le' => now()->subMinute()]);
        $this->souscription($awa, 'RAA-B-PAYEE001', StatutSouscription::Payee, 5);

        $page = $this->actingAs($awa)->get('/espace')->assertOk()
            ->assertSee('Payer maintenant')
            ->assertSee('href="'.route('paiement.show', 'RAA-B-RESERVE1').'"', false)
            ->assertDontSee('href="'.route('paiement.show', 'RAA-B-RESERVE2').'"', false);

        $this->assertSame(1, substr_count($page->getContent(), 'Payer maintenant'));
        // La réservation échue est passée EXPIRÉE à l'affichage.
        $this->assertSame(StatutSouscription::Expiree, Souscription::query()->where('reference', 'RAA-B-RESERVE2')->value('statut'));
    }

    public function test_un_citoyen_ne_voit_que_ses_propres_donnees(): void
    {
        $awa = $this->citoyen('Awa', '+22670111111');
        $issa = $this->citoyen('Issa', '+22670222222');
        $this->souscription($awa, 'RAA-B-AWA00001', StatutSouscription::Payee, 25);
        $this->souscription($issa, 'RAA-B-ISSA0001', StatutSouscription::Allouee, 40, ['nb_actions_allouees' => 40]);
        $ligne = LigneRegistre::create([
            'numero_certificat' => 'CB-2026-000001', 'user_id' => $issa->id, 'tranche_id' => $this->tranche->id,
            'souscription_id' => Souscription::query()->where('reference', 'RAA-B-ISSA0001')->value('id'),
            'nb_actions' => 40, 'numero_action_debut' => 1, 'numero_action_fin' => 40, 'date_inscription' => now(),
        ]);

        foreach (['souscriptions', 'documents', 'profil'] as $onglet) {
            $this->actingAs($awa)->get('/espace?onglet='.$onglet)->assertOk()
                ->assertDontSee('RAA-B-ISSA0001')->assertDontSee('CB-2026-000001')->assertDontSee('+226 70 22')->assertDontSee('70 22 22 22');
        }
        $this->actingAs($awa)->get('/espace?onglet=documents')->assertOk()->assertSee('RAA-B-AWA00001')->assertSee('Règlement accepté');

        // Documents d'un autre citoyen : refusés.
        $this->actingAs($awa)->get(route('espace.documents.attestation', $ligne->numero_certificat))->assertForbidden();
        $this->actingAs($awa)->get(route('espace.documents.reglement', 'RAA-B-ISSA0001'))->assertForbidden();
        $this->actingAs($awa)->get(route('paiement.recu', 'RAA-B-ISSA0001'))->assertForbidden();

        // Issa voit ses propres données et télécharge son attestation.
        $this->actingAs($issa)->get('/espace?onglet=documents')->assertOk()->assertSee('CB-2026-000001')->assertDontSee('RAA-B-AWA00001');
        $this->actingAs($issa)->get(route('espace.documents.attestation', $ligne->numero_certificat))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_les_onglets_vides_et_le_profil(): void
    {
        $awa = $this->citoyen('Awa', '+22670111111');

        $this->actingAs($awa)->get('/espace?onglet=documents')->assertOk()->assertSee('Aucun document pour le moment');
        $this->actingAs($awa)->get('/espace?onglet=dividendes')->assertOk()->assertSee('Aucun dividende versé')->assertSee('Compte de versement des dividendes');
        $this->actingAs($awa)->get('/espace?onglet=profil')->assertOk()
            ->assertSee('70 11 11 11')->assertSee('Centre')->assertSee('Bénéficiaire désigné')
            ->assertSee('href="'.route('espace.profil').'"', false);
    }
}
