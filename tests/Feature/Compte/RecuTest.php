<?php

namespace Tests\Feature\Compte;

use App\Contracts\SmsProvider;
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
use App\Enums\TypeFraisSouscription;
use App\Models\Duree;
use App\Models\MoyenPaiement;
use App\Models\Paiement;
use App\Models\Souscription;
use App\Models\Tranche;
use App\Models\User;
use App\Services\Paiement\ServicePaiement;
use App\Services\Paiement\SimulatedProvider;
use App\Support\Recu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

class RecuTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsProvider $sms;

    private User $citoyen;

    private Souscription $souscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsProvider;
        $this->app->instance(SmsProvider::class, $this->sms);

        $tranche = Tranche::create([
            'statut' => StatutTranche::Ouverte, 'nom' => 'Tranche 1 – 2026', 'valeur_unitaire' => 10_000,
            'min_actions_par_souscription' => 1, 'objectif_montant' => 3_000_000_000,
            'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addDays(30),
            'publics_autorises' => [PublicAutorise::ResidentBf->value], 'mode_saisie' => ModeSaisie::NbActions,
            'souscriptions_multiples' => true, 'regle_arret' => RegleArret::ArretAObjectif, 'methode_allocation' => MethodeAllocation::Prorata,
            'regle_si_echec' => RegleSiEchec::RemboursementIntegral, 'delai_remboursement_jours' => 30,
            'frais_paiement_supportes_par' => PayeurFrais::Raaga, 'frais_souscription_type' => TypeFraisSouscription::Fixe,
            'frais_souscription_valeur' => 500, 'canaux_actifs' => ['ORANGE_MONEY'],
            'delai_paiement_valeur' => 48, 'delai_paiement_duree_id' => Duree::parCode('HEURE')->id, 'delai_paiement_secondes' => 172_800, 'version' => 2, 'cree_par' => User::factory()->create()->id,
        ]);

        $this->citoyen = User::factory()->role(Role::Citoyen)->create([
            'name' => 'Awa OUÉDRAOGO', 'statut_kyc' => StatutKyc::Valide, 'pays_residence' => 'BF', 'telephone' => '+22670123456',
        ]);
        $this->souscription = Souscription::create([
            'reference' => 'RAA-B-K7M2Q9XP', 'user_id' => $this->citoyen->id, 'tranche_id' => $tranche->id,
            'nb_actions_demandees' => 25, 'valeur_unitaire' => 10_000, 'montant' => 250_000, 'frais_souscription' => 500,
            'canal' => CanalSouscription::Web, 'statut' => StatutSouscription::Reservee, 'expire_le' => now()->addHours(48),
        ]);
    }

    private function payer(): Paiement
    {
        $service = app(ServicePaiement::class);
        $paiement = $service->initier($this->souscription, MoyenPaiement::parCode('ORANGE_MONEY'), '+22670123456', new SimulatedProvider, $this->citoyen);

        return $service->confirmer($paiement->reference, true);
    }

    public function test_le_recu_pdf_contient_les_informations_cles(): void
    {
        // Seul test qui produit un vrai PDF (DomPDF) ; les autres utilisent la doublure PdfFactice.
        $this->pdfReel();
        $paiement = $this->payer();
        $numero = Recu::numero($paiement);
        $this->assertMatchesRegularExpression('/^REC-\d{4}-\d{6}$/', $numero);

        $html = view('documents.recu-souscription', [
            'souscription' => $this->souscription->fresh()->load(['tranche', 'user']), 'paiement' => $paiement, 'numero' => $numero, 'pdf' => true,
        ])->render();

        foreach ([$numero, 'Reçu de souscription', 'RAA-B-K7M2Q9XP', 'Awa OUÉDRAOGO', "250\u{00A0}000\u{00A0}FCFA", "250\u{00A0}500\u{00A0}FCFA",
            'Orange Money', $paiement->reference, $paiement->date_confirmation->format('d/m/Y'), 'En attente d’allocation',
            'L’achat d’actions ne donne aucun droit ni priorité pour obtenir une boutique.', 'data:image'] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }
        // Mentions légales : dénomination des paramètres de la plateforme, jamais en dur.
        $this->assertStringContainsString(e(plateforme('mentions.denominationSociale') ?: plateforme('identite.nomPlateforme')), $html);

        $reponse = $this->actingAs($this->citoyen)->get('/paiement/RAA-B-K7M2Q9XP/recu')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('recu-'.$numero.'.pdf');
        $this->assertStringStartsWith('%PDF', $reponse->getContent());

        // Confirmation : récapitulatif et boutons.
        $this->actingAs($this->citoyen)->get('/paiement/RAA-B-K7M2Q9XP/confirmation')->assertOk()
            ->assertSee('Votre souscription est enregistrée')->assertSee($numero)
            ->assertSee($paiement->date_confirmation->format('d/m/Y'))
            ->assertSee('Télécharger le reçu (PDF)')->assertSee('Accéder à mon espace')
            ->assertSee('href="'.route('paiement.recu', 'RAA-B-K7M2Q9XP').'"', false);

        // Toujours téléchargeable une fois les actions allouées.
        $this->souscription->fresh()->forceFill(['statut' => StatutSouscription::Allouee])->save();
        $this->actingAs($this->citoyen)->get('/paiement/RAA-B-K7M2Q9XP/recu')->assertOk();
    }

    public function test_le_sms_de_confirmation_contient_le_numero_de_recu(): void
    {
        $paiement = $this->payer();

        $this->assertCount(1, $this->sms->envoyes);
        $envoi = $this->sms->envoyes[0];
        $this->assertSame('+22670123456', $envoi['telephone']);
        $this->assertSame(plateforme('identite.expediteurSms'), $envoi['expediteur']);
        $this->assertStringContainsString(Recu::numero($paiement), $envoi['message']);
        $this->assertStringContainsString('RAA-B-K7M2Q9XP', $envoi['message']);
        $this->assertStringContainsString("250\u{00A0}500\u{00A0}FCFA", $envoi['message']);
        $this->assertStringNotContainsString('{', $envoi['message']);

        // Confirmation rejouée : aucun second SMS.
        app(ServicePaiement::class)->confirmer($paiement->reference, true);
        $this->assertCount(1, $this->sms->envoyes);
    }

    public function test_acces_refuse_au_recu_d_un_autre_citoyen(): void
    {
        $this->payer();
        $autre = User::factory()->role(Role::Citoyen)->create();

        $this->actingAs($autre)->get('/paiement/RAA-B-K7M2Q9XP/recu')->assertForbidden();
        $this->actingAs($autre)->get('/paiement/RAA-B-K7M2Q9XP/confirmation')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->get('/paiement/RAA-B-K7M2Q9XP/recu')->assertRedirect(route('connexion'));
    }

    public function test_pas_de_recu_avant_le_paiement(): void
    {
        $this->actingAs($this->citoyen)->get('/paiement/RAA-B-K7M2Q9XP/recu')->assertNotFound();
        $this->assertSame(0, Paiement::query()->where('statut', StatutPaiement::Confirme)->count());
    }
}
