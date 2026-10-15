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
use App\Enums\StatutSouscription;
use App\Enums\StatutTranche;
use App\Enums\TypeDocumentKyc;
use App\Enums\TypeFraisSouscription;
use App\Models\Acceptation;
use App\Models\Souscription;
use App\Models\Tranche;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SouscriptionTest extends TestCase
{
    use RefreshDatabase;

    private const TOUS = ['AUCUN_DROIT_BOUTIQUE', 'RENDEMENT_NON_GARANTI', 'REGLEMENT_TRANCHE', 'ORIGINE_LICITE_FONDS'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function tranche(array $parametres = []): Tranche
    {
        return Tranche::create([
            'statut' => StatutTranche::Ouverte,
            'nom' => 'Tranche 1 – 2026',
            'valeur_unitaire' => 10_000,
            'min_actions_par_souscription' => 1,
            'plafond_actions_par_personne' => 500,
            'objectif_montant' => 3_000_000_000,
            'date_ouverture' => now()->subDay(),
            'date_cloture' => now()->addDays(30),
            'publics_autorises' => [PublicAutorise::ResidentBf->value],
            'mode_saisie' => ModeSaisie::NbActions,
            'souscriptions_multiples' => true,
            'regle_arret' => RegleArret::ArretAObjectif,
            'methode_allocation' => MethodeAllocation::Prorata,
            'regle_si_echec' => RegleSiEchec::RemboursementIntegral,
            'delai_remboursement_jours' => 30,
            'frais_paiement_supportes_par' => PayeurFrais::Raaga,
            'frais_souscription_type' => TypeFraisSouscription::Pourcentage,
            'frais_souscription_valeur' => 150, // 1,5 %
            'canaux_actifs' => [CanalPaiement::OrangeMoney->value],
            'delai_paiement_heures' => 48,
            'seuil_kyc_renforce' => 1_000_000,
            'version' => 3,
            'cree_par' => User::factory()->create()->id,
            ...$parametres,
        ]);
    }

    private function citoyen(StatutKyc $kyc = StatutKyc::Valide): User
    {
        return User::factory()->role(Role::Citoyen)->create(['statut_kyc' => $kyc, 'pays_residence' => 'BF']);
    }

    public function test_la_page_affiche_les_parametres_de_la_tranche_et_des_cases_non_cochees(): void
    {
        $this->tranche();

        $page = $this->actingAs($this->citoyen())->get('/souscrire')->assertOk()
            ->assertSee('Souscrire — Tranche 1 – 2026')
            ->assertSee("10\u{00A0}000\u{00A0}FCFA")
            ->assertSee('L’achat d’actions ne donne aucun droit ni priorité pour obtenir une boutique.')
            ->assertSee('Le rendement n’est pas garanti.')
            ->assertSee('version 3')
            ->assertSee('href="'.route('souscrire.reglement').'"', false)
            ->assertSee('Continuer vers le paiement');

        $this->assertSame(4, substr_count($page->getContent(), 'name="engagements[]"'));
        $this->assertDoesNotMatchRegularExpression('/name="engagements\[\]"[^>]*\bchecked\b/', $page->getContent());

        $this->get('/souscrire/reglement')->assertOk()->assertSee('Règlement de la souscription — Tranche 1 – 2026')->assertSee('Version 3');
    }

    public function test_une_souscription_est_reservee_avec_les_quatre_acceptations_horodatees(): void
    {
        $tranche = $this->tranche();
        $citoyen = $this->citoyen();

        $this->actingAs($citoyen)->post('/souscrire', ['nb_actions' => 25, 'engagements' => self::TOUS])
            ->assertRedirect(route('paiement.show', Souscription::sole()->reference))
            ->assertSessionHas('souscription.reservee');

        $souscription = Souscription::sole();
        $this->assertSame(StatutSouscription::Reservee, $souscription->statut);
        $this->assertSame(CanalSouscription::Web, $souscription->canal);
        $this->assertSame(25, $souscription->nb_actions_demandees);
        $this->assertSame(250_000, $souscription->montant);
        $this->assertSame(3_750, $souscription->frais_souscription);
        $this->assertSame($tranche->id, $souscription->tranche_id);
        $this->assertTrue($souscription->expire_le->between(now()->addHours(47), now()->addHours(49)));
        $this->assertMatchesRegularExpression('/^RAA-B-[A-Z2-9]{8}$/', $souscription->reference);

        $acceptations = Acceptation::query()->where('souscription_id', $souscription->id)->get();
        $this->assertEqualsCanonicalizing(self::TOUS, $acceptations->map(fn ($a) => $a->document->value)->all());
        $this->assertTrue($acceptations->every(fn ($a) => $a->accepte_le !== null && $a->user_id === $citoyen->id));
        $this->assertSame('3', $acceptations->firstWhere('document', DocumentAccepte::ReglementTranche)->version_document);

        $this->followingRedirects()->actingAs($citoyen)->post('/souscrire', ['nb_actions' => 1, 'engagements' => self::TOUS])
            ->assertSee('est réservée');
    }

    public function test_refus_sans_les_quatre_engagements(): void
    {
        $this->tranche();
        $citoyen = $this->citoyen();

        foreach ([[], array_slice(self::TOUS, 0, 3)] as $coches) {
            $this->actingAs($citoyen)->from('/souscrire')->post('/souscrire', ['nb_actions' => 10, 'engagements' => $coches])
                ->assertRedirect('/souscrire')
                ->assertSessionHasErrors(['engagements' => 'Cochez les quatre engagements pour continuer.']);
        }

        $this->assertSame(0, Souscription::count());
        $this->assertSame(0, Acceptation::count());
    }

    public function test_au_dela_du_seuil_un_justificatif_d_origine_des_fonds_est_exige(): void
    {
        $this->tranche();
        $citoyen = $this->citoyen();

        // 101 actions = 1 010 000 FCFA > seuil de 1 000 000 FCFA.
        $this->actingAs($citoyen)->post('/souscrire', ['nb_actions' => 101, 'engagements' => self::TOUS])
            ->assertSessionHasErrors('justificatif');
        $this->assertSame(0, Souscription::count());

        // Sous le seuil : aucun justificatif.
        $this->actingAs($citoyen)->post('/souscrire', ['nb_actions' => 100, 'engagements' => self::TOUS])->assertSessionHasNoErrors();

        // Le cumul compte : 100 déjà réservées + 1 dépasse le seuil.
        $this->actingAs($citoyen)->post('/souscrire', ['nb_actions' => 1, 'engagements' => self::TOUS])->assertSessionHasErrors('justificatif');

        $this->actingAs($citoyen)->post('/souscrire', [
            'nb_actions' => 1, 'engagements' => self::TOUS,
            'justificatif' => UploadedFile::fake()->create('salaire.pdf', 200, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Souscription::count());
        $document = $citoyen->kycDocuments()->sole();
        $this->assertSame(TypeDocumentKyc::JustifFonds, $document->type);
        Storage::disk('local')->assertExists($document->chemin_fichier);
        $this->assertStringStartsWith('kyc/'.$citoyen->id.'/', $document->chemin_fichier);
    }

    public function test_identite_non_validee_preparation_possible_mais_reservation_refusee(): void
    {
        $this->tranche();
        $citoyen = $this->citoyen(StatutKyc::EnAttente);

        $this->actingAs($citoyen)->get('/souscrire')->assertOk()
            ->assertSee('Votre identité n’est pas encore vérifiée')
            ->assertSee('Paiement possible après vérification de votre identité.')
            ->assertSee('href="'.route('espace.verification').'"', false);

        $this->actingAs($citoyen)->post('/souscrire', ['nb_actions' => 10, 'engagements' => self::TOUS])
            ->assertSessionHasErrors(['identite' => 'Votre identité doit être vérifiée avant de réserver et de payer une souscription.']);

        $this->assertSame(0, Souscription::count());
    }

    public function test_plafond_par_personne_et_acces(): void
    {
        $this->tranche(['plafond_actions_par_personne' => 30]);

        $this->get('/souscrire')->assertRedirect(route('connexion'));
        $this->actingAs(User::factory()->role(Role::Investisseur)->create())->get('/souscrire')->assertForbidden();

        $this->actingAs($this->citoyen())->post('/souscrire', ['nb_actions' => 31, 'engagements' => self::TOUS])
            ->assertSessionHasErrors('nb_actions');
        $this->assertSame(0, Souscription::count());
    }

    public function test_sans_tranche_ouverte_la_souscription_renvoie_vers_l_alerte_sms(): void
    {
        $this->get('/souscrire')->assertRedirect(route('souscription.bientot'));
        $this->get(route('souscription.bientot'))->assertOk()->assertSee('Souscription bientôt ouverte');
    }
}
