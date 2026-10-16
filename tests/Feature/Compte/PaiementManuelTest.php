<?php

namespace Tests\Feature\Compte;

use App\Enums\CanalSouscription;
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
use App\Enums\TypeDocumentKyc;
use App\Enums\TypeFraisSouscription;
use App\Models\CandidatureBoutique;
use App\Models\Paiement;
use App\Models\Relance;
use App\Models\Souscription;
use App\Models\Tranche;
use App\Models\User;
use App\Notifications\ControlePaiementCitoyen;
use App\Services\Paiement\ServicePaiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/**
 * Paiements manuels côté citoyen (virement, versement, espèces) : aucun opérateur ; montant exact, compte de
 * destination selon l'usage, référence de paiement unique, délai restant ; preuve jointe, contrôlée par le
 * CONTRÔLEUR DES PAIEMENTS ; délai gelé pendant le contrôle. Et le NIF (avec le RCCM) parmi les pièces d'identité.
 */
class PaiementManuelTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private User $citoyen;

    private Souscription $souscription;

    private User $controleur;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'raaga.phase.presentation_actions' => true, 'raaga.phase.souscription_actions' => true,
            'raaga.compte_sequestre' => ['titulaire' => 'Raaga SA · Investissement', 'banque' => 'Banque Atlantique', 'numero' => 'BF042-INVEST-0001'],
            'raaga.compte_location' => ['titulaire' => 'Raaga SA · Location', 'banque' => 'Coris Bank', 'numero' => 'BF043-LOCATION-0002'],
        ]);
        Cache::flush();
        $this->creerActeurs();
        $this->controleur = User::factory()->role(Role::ControleurPaiements)->create();

        $tranche = Tranche::create([
            'statut' => StatutTranche::Ouverte, 'nom' => 'Tranche 1', 'valeur_unitaire' => 10_000, 'min_actions_par_souscription' => 1,
            'objectif_montant' => 1_000_000_000, 'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addDays(30),
            'publics_autorises' => [PublicAutorise::ResidentBf->value], 'mode_saisie' => ModeSaisie::NbActions, 'souscriptions_multiples' => true,
            'regle_arret' => RegleArret::ArretAObjectif, 'methode_allocation' => MethodeAllocation::Prorata, 'regle_si_echec' => RegleSiEchec::RemboursementIntegral,
            'delai_remboursement_jours' => 30, 'frais_paiement_supportes_par' => PayeurFrais::Raaga, 'frais_souscription_type' => TypeFraisSouscription::Aucun,
            'canaux_actifs' => ['ORANGE_MONEY', 'VIREMENT', 'ESPECES'], 'delai_paiement_heures' => 48, 'version' => 1, 'cree_par' => $this->regulateur->id,
        ]);
        $this->citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'pays_residence' => 'BF', 'telephone' => '+22670123456']);
        $this->souscription = Souscription::create([
            'reference' => 'RAA-B-MANUEL01', 'user_id' => $this->citoyen->id, 'tranche_id' => $tranche->id, 'nb_actions_demandees' => 3,
            'valeur_unitaire' => 10_000, 'montant' => 30_000, 'frais_souscription' => 0, 'canal' => CanalSouscription::Web,
            'statut' => StatutSouscription::Reservee, 'expire_le' => now()->addHours(48),
        ]);
    }

    private function choisir(string $canal = 'VIREMENT'): Paiement
    {
        $this->actingAs($this->citoyen)->post(route('paiement.payer', $this->souscription->reference), ['canal' => $canal])->assertSessionHasNoErrors();

        return $this->souscription->paiements()->latest('id')->firstOrFail();
    }

    private function deposer(string $nom = 'avis.pdf', int $ko = 200, string $mime = 'application/pdf')
    {
        return $this->actingAs($this->citoyen)->post(route('paiement.preuve', $this->souscription->reference), ['preuve' => UploadedFile::fake()->create($nom, $ko, $mime)]);
    }

    public function test_instructions_avec_le_bon_compte_et_reference_unique(): void
    {
        $paiement = $this->choisir('VIREMENT');

        // Aucun opérateur : référence de paiement unique, aucune référence d'opérateur.
        $this->assertTrue($paiement->estManuel());
        $this->assertSame(StatutPaiement::EnAttente, $paiement->statut);
        $this->assertMatchesRegularExpression('/^RG-[A-Z2-9]{6}$/', $paiement->reference_versement);
        $this->assertNull($paiement->reference_fournisseur);

        $page = $this->actingAs($this->citoyen)->get(route('paiement.show', $this->souscription->reference))->assertOk();
        $page->assertSee('data-paiement-manuel', false)->assertSee(format_fcfa(30_000))->assertSee($paiement->reference_versement)
            ->assertSee('data-compte="INVESTISSEMENT"', false)->assertSee('BF042-INVEST-0001')->assertDontSee('BF043-LOCATION-0002')
            ->assertSee('Délai restant')->assertSee('Joindre la preuve de paiement');

        // Espèces : nouvelle demande, nouvelle référence ; les références ne se répètent jamais.
        $this->actingAs($this->citoyen)->post(route('paiement.changer', $this->souscription->reference));
        $especes = $this->choisir('ESPECES');
        $this->assertNotSame($paiement->reference_versement, $especes->reference_versement);
        $references = collect(range(1, 50))->map(fn () => Paiement::nouvelleReferenceVersement());
        $this->assertSame(50, $references->unique()->count());
    }

    public function test_caution_vers_le_compte_de_location(): void
    {
        $campagne = CandidatureBoutiqueTest::campagneOuverte();
        $this->actingAs($this->citoyen)->post('/boutiques/candidater', [
            'categorie' => $campagne->categories()->value('id'),
            'engagements' => ['TIRAGE_SANS_GARANTIE', 'CAUTION_SANS_LIEN_ACTIONS', 'REGLEMENT_CAMPAGNE_BOUTIQUES', 'ORIGINE_LICITE_FONDS'],
        ])->assertRedirect();
        $c = CandidatureBoutique::query()->sole();

        $this->actingAs($this->citoyen)->post(route('boutiques.caution.payer', $c->reference), ['canal' => 'VIREMENT'])->assertSessionHasNoErrors();
        $this->actingAs($this->citoyen)->get(route('boutiques.caution.show', $c->reference))->assertOk()
            ->assertSee('data-compte="LOCATION"', false)->assertSee('BF043-LOCATION-0002')->assertDontSee('BF042-INVEST-0001')
            ->assertSee($c->paiements()->sole()->reference_versement);
    }

    public function test_depot_de_preuve_et_controle_recu(): void
    {
        Notification::fake();
        $paiement = $this->choisir();

        // Format et taille contrôlés.
        $this->deposer('note.txt', 10, 'text/plain')->assertSessionHasErrors('preuve');
        $this->deposer('avis.pdf', 6000)->assertSessionHasErrors('preuve');

        $this->deposer('avis.pdf')->assertSessionHasNoErrors()->assertRedirect(route('paiement.show', $this->souscription->reference));
        $paiement->refresh();
        $this->assertTrue($paiement->enControle());
        Storage::disk('local')->assertExists($paiement->preuve_chemin);
        $this->actingAs($this->citoyen)->get(route('paiement.show', $this->souscription->reference))->assertSee('data-en-controle', false)
            ->assertDontSee('Joindre la preuve de paiement');
        // Pendant le contrôle : ni second dépôt, ni abandon du paiement.
        $this->deposer('autre.pdf')->assertSessionHasErrors('preuve');
        $this->actingAs($this->citoyen)->post(route('paiement.changer', $this->souscription->reference))->assertStatus(409);

        // Le contrôleur voit la preuve dans le dossier et marque reçu : dossier payé, citoyen notifié.
        $dossier = route('admin.paiements.dossier', ['souscription', $this->souscription->id]);
        $this->en($this->controleur)->get($dossier)->assertOk()->assertSee('data-preuve', false)->assertSee($paiement->reference_versement)->assertSee('data-en-controle', false);
        $this->en($this->controleur)->get(route('admin.paiements.preuve', $paiement))->assertOk();
        $this->en($this->controleur)->post(route('admin.paiements.controle', $paiement), ['controle' => 'RECU', 'controle_reference' => 'AVIS-778'])->assertSessionHas('succes');
        $this->assertSame([StatutPaiement::Confirme, StatutSouscription::Payee], [$paiement->fresh()->statut, $this->souscription->fresh()->statut]);
        Notification::assertSentTo($this->citoyen, ControlePaiementCitoyen::class, fn ($n) => str_contains($n->toArray($this->citoyen)['message'], 'reçu'));
    }

    public function test_preuve_rejetee_reprend_le_delai_ou_libere_si_depasse(): void
    {
        Notification::fake();
        $paiement = $this->choisir();
        $this->deposer();

        // Rejet dans le délai : le paiement échoue, le dossier reste en attente ; le citoyen voit le motif.
        $this->en($this->controleur)->post(route('admin.paiements.controle', $paiement), ['controle' => 'REJETE', 'motif' => 'Montant absent du relevé'])->assertSessionHas('succes');
        $this->assertSame([StatutPaiement::Echoue, StatutSouscription::Reservee], [$paiement->fresh()->statut, $this->souscription->fresh()->statut]);
        $this->actingAs($this->citoyen)->get(route('paiement.show', $this->souscription->reference))->assertSee('Montant absent du relevé');
        Notification::assertSentTo($this->citoyen, ControlePaiementCitoyen::class);

        // Nouvelle preuve déposée, délai dépassé pendant le contrôle, puis rejet : la souscription est libérée.
        $second = $this->choisir();
        $this->deposer();
        $this->travel(3)->days();
        $this->en($this->controleur)->post(route('admin.paiements.controle', $second), ['controle' => 'REJETE', 'motif' => 'Bordereau illisible'])->assertSessionHas('succes');
        $this->assertSame(StatutSouscription::Expiree, $this->souscription->fresh()->statut);
        Notification::assertSentTo($this->citoyen, ControlePaiementCitoyen::class, fn ($n) => str_contains($n->toArray($this->citoyen)['message'], 'clos'));
    }

    public function test_delai_gele_pendant_le_controle(): void
    {
        $paiement = $this->choisir();
        $this->deposer();

        // Délai dépassé : ni expiration, ni relance d'échéance tant que le contrôleur n'a pas tranché.
        $this->travel(47)->hours();
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();
        $this->assertSame(0, Relance::query()->where('type', Relance::ECHEANCE)->count());
        $this->travel(5)->days();
        app(ServicePaiement::class)->expirerReservations();
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();
        $this->assertSame(StatutSouscription::Reservee, $this->souscription->fresh()->statut);
        $this->assertSame(StatutPaiement::EnAttente, $paiement->fresh()->statut);

        // Reçu après la date limite : le dossier est payé.
        $this->en($this->controleur)->post(route('admin.paiements.controle', $paiement), ['controle' => 'RECU'])->assertSessionHas('succes');
        $this->assertSame(StatutSouscription::Payee, $this->souscription->fresh()->statut);

        // Sans preuve en contrôle, une réservation échue expire normalement.
        $autre = Souscription::create([...$this->souscription->only(['user_id', 'tranche_id', 'nb_actions_demandees', 'valeur_unitaire', 'montant', 'frais_souscription', 'canal']),
            'reference' => 'RAA-B-MANUEL02', 'statut' => StatutSouscription::Reservee, 'expire_le' => now()->subMinute()]);
        app(ServicePaiement::class)->expirerReservations();
        $this->assertSame(StatutSouscription::Expiree, $autre->fresh()->statut);
    }

    public function test_nif_et_rccm_acceptes(): void
    {
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::NonSoumis]);
        $this->actingAs($citoyen)->get(route('espace.verification'))->assertOk()->assertSee('NIF (numéro d’identification fiscale)', false);

        $this->actingAs($citoyen)->post(route('espace.verification.store'), [
            'type_piece' => 'NIF',
            'recto' => UploadedFile::fake()->create('attestation-nif.pdf', 300, 'application/pdf'),
            'rccm' => UploadedFile::fake()->create('rccm.pdf', 300, 'application/pdf'),
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
        ])->assertSessionHasNoErrors()->assertRedirect(route('espace.verification'));

        $this->assertSame(StatutKyc::EnAttente, $citoyen->fresh()->statut_kyc);
        $this->assertEqualsCanonicalizing([TypeDocumentKyc::Nif, TypeDocumentKyc::Rccm, TypeDocumentKyc::Selfie], $citoyen->kycDocuments()->pluck('type')->all());

        // RCCM facultatif : NIF et selfie suffisent.
        $autre = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::NonSoumis]);
        $this->actingAs($autre)->post(route('espace.verification.store'), [
            'type_piece' => 'NIF', 'recto' => UploadedFile::fake()->image('nif.jpg'), 'selfie' => UploadedFile::fake()->image('selfie.jpg'),
        ])->assertSessionHasNoErrors();
        $this->assertSame(StatutKyc::EnAttente, $autre->fresh()->statut_kyc);

        // L'ADMIN voit les pièces NIF et RCCM dans le dossier.
        $admin = User::factory()->role(Role::Admin)->create();
        $this->en($admin)->get(route('admin.kyc.dossier', $citoyen))->assertOk()->assertSee('NIF')->assertSee('RCCM');
    }
}
