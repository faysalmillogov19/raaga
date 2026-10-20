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
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutKyc;
use App\Enums\StatutPaiement;
use App\Enums\StatutSouscription;
use App\Enums\StatutTranche;
use App\Enums\TypeFraisSouscription;
use App\Models\Duree;
use App\Models\Paiement;
use App\Models\ParametresPaiements;
use App\Models\Relance;
use App\Models\Remboursement;
use App\Models\Souscription;
use App\Models\Tranche;
use App\Models\User;
use App\Notifications\ControlePaiementCitoyen;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * Ouverture des paiements manuels (sans opérateur, compte renseigné) et délai de complément d'un paiement incomplet :
 * 48 h par défaut (paramétrable par le RÉGULATEUR, confirmé par le VALIDATEUR), relance 24 h avant la fin, clôture et
 * remboursement de la somme reçue à l'échéance.
 */
class ComplementPaiementTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private const COMPTES = [
        'raaga.compte_sequestre' => ['titulaire' => 'Raaga SA', 'banque' => 'Banque A', 'numero' => 'BF-INV-01'],
        'raaga.compte_location' => ['titulaire' => 'Raaga SA location', 'banque' => 'Banque B', 'numero' => 'BF-LOC-02'],
    ];

    private User $citoyen;

    private Souscription $souscription;

    private User $controleur;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['raaga.phase.presentation_actions' => true, 'raaga.phase.souscription_actions' => true, ...self::COMPTES]);
        Cache::flush();
        $this->creerActeurs();
        $this->controleur = User::factory()->role(Role::ControleurPaiements)->create();
        $tranche = Tranche::create([
            'statut' => StatutTranche::Ouverte, 'nom' => 'Tranche 1', 'valeur_unitaire' => 10_000, 'min_actions_par_souscription' => 1,
            'objectif_montant' => 1_000_000_000, 'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addDays(30),
            'publics_autorises' => [PublicAutorise::ResidentBf->value], 'mode_saisie' => ModeSaisie::NbActions, 'souscriptions_multiples' => true,
            'regle_arret' => RegleArret::ArretAObjectif, 'methode_allocation' => MethodeAllocation::Prorata, 'regle_si_echec' => RegleSiEchec::RemboursementIntegral,
            'delai_remboursement_jours' => 30, 'frais_paiement_supportes_par' => PayeurFrais::Raaga, 'frais_souscription_type' => TypeFraisSouscription::Aucun,
            'canaux_actifs' => ['ORANGE_MONEY', 'VIREMENT'], 'delai_paiement_valeur' => 48, 'delai_paiement_duree_id' => Duree::parCode('HEURE')->id, 'delai_paiement_secondes' => 172_800, 'version' => 1, 'cree_par' => $this->regulateur->id,
        ]);
        $this->citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'pays_residence' => 'BF', 'telephone' => '+22670123456']);
        $this->souscription = Souscription::create([
            'reference' => 'RAA-B-COMPL001', 'user_id' => $this->citoyen->id, 'tranche_id' => $tranche->id, 'nb_actions_demandees' => 5,
            'valeur_unitaire' => 10_000, 'montant' => 50_000, 'frais_souscription' => 0, 'canal' => CanalSouscription::Web,
            'statut' => StatutSouscription::Reservee, 'expire_le' => now()->addHours(48),
        ]);
    }

    /** Virement choisi, preuve déposée, contrôle « incomplet » avec 20 000 FCFA reçus. */
    private function incomplet(): Paiement
    {
        $ref = $this->souscription->reference;
        $this->actingAs($this->citoyen)->post(route('paiement.payer', $ref), ['canal' => 'VIREMENT'])->assertSessionHasNoErrors();
        $this->actingAs($this->citoyen)->post(route('paiement.preuve', $ref), ['preuve' => UploadedFile::fake()->create('avis.pdf', 100, 'application/pdf')])->assertSessionHasNoErrors();
        $paiement = $this->souscription->paiements()->sole();
        $this->en($this->controleur)->post(route('admin.paiements.controle', $paiement), ['controle' => 'INCOMPLET', 'motif' => 'Virement partiel'])
            ->assertSessionHasErrors('controle_montant_recu');
        $this->en($this->controleur)->post(route('admin.paiements.controle', $paiement), ['controle' => 'INCOMPLET', 'motif' => 'Virement partiel', 'controle_montant_recu' => 20_000])
            ->assertSessionHas('succes');

        return $paiement->fresh();
    }

    public function test_virement_ouvert_sans_operateur_mobile_money_ferme_campagne_ouvrable(): void
    {
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class);
        config(['raaga.paiement.fournisseur' => null]);
        $ref = $this->souscription->reference;

        // Sans opérateur réel mais compte d'investissement renseigné : le virement est proposé, le mobile money non.
        $this->actingAs($this->citoyen)->get(route('paiement.show', $ref))->assertOk()
            ->assertSee('value="VIREMENT"', false)->assertDontSee('value="ORANGE_MONEY"', false);
        $this->actingAs($this->citoyen)->post(route('paiement.payer', $ref), ['canal' => 'ORANGE_MONEY', 'telephone' => '70123456'])
            ->assertSessionHasErrors(['canal' => 'Moyen de paiement indisponible']);
        $this->assertSame(0, Paiement::query()->count());
        $this->actingAs($this->citoyen)->post(route('paiement.payer', $ref), ['canal' => 'VIREMENT'])->assertSessionHasNoErrors();
        $this->assertNotNull(Paiement::query()->sole()->reference_versement);

        // Compte non renseigné : le virement aussi est fermé.
        $this->actingAs($this->citoyen)->post(route('paiement.changer', $ref));
        config(['raaga.compte_sequestre' => []]);
        $this->actingAs($this->citoyen)->post(route('paiement.payer', $ref), ['canal' => 'VIREMENT'])->assertSessionHasErrors('canal');

        // Campagne qui n'accepte que le virement : ouvrable sans opérateur ; mobile money seul : fermée.
        $virement = Boutiques::campagneOuverte(['statut' => StatutCampagneBoutiques::Brouillon, 'nom' => 'Virement seul', 'canaux_actifs' => ['VIREMENT']]);
        $mobile = Boutiques::campagneOuverte(['statut' => StatutCampagneBoutiques::Brouillon, 'nom' => 'Mobile seul', 'canaux_actifs' => ['ORANGE_MONEY']]);
        $this->assertNull($virement->raisonPaiementsFermes());
        $this->assertSame('aucun opérateur de paiement réel n’est configuré', $mobile->raisonPaiementsFermes());

        // raaga:verifier-production suit la même règle : seuls les paiements par opérateur sont annoncés fermés.
        $this->artisan('raaga:verifier-production')->expectsOutputToContain('paiements par opérateur fermés')->run();
    }

    public function test_delai_supplementaire_de_48_heures_meme_apres_le_delai_initial(): void
    {
        Notification::fake();
        $this->travel(47)->hours();
        $this->actingAs($this->citoyen)->post(route('paiement.payer', $this->souscription->reference), ['canal' => 'VIREMENT']);
        $this->actingAs($this->citoyen)->post(route('paiement.preuve', $this->souscription->reference), ['preuve' => UploadedFile::fake()->create('avis.pdf', 100, 'application/pdf')]);
        // Délai initial dépassé pendant le contrôle (gelé), puis « incomplet » : 48 h à partir de la décision.
        $this->travel(10)->hours();
        $paiement = $this->souscription->paiements()->sole();
        $this->en($this->controleur)->post(route('admin.paiements.controle', $paiement), ['controle' => 'INCOMPLET', 'motif' => 'Virement partiel', 'controle_montant_recu' => 20_000])
            ->assertSessionHas('succes');

        $this->assertEqualsWithDelta(48, now()->diffInHours($this->souscription->fresh()->expire_le), 0.1);
        $this->assertSame(StatutSouscription::Reservee, $this->souscription->fresh()->statut);
        $this->assertSame(20_000, $paiement->fresh()->controle_montant_recu);
        Notification::assertSentTo($this->citoyen, ControlePaiementCitoyen::class, fn ($n) => str_contains($n->toArray($this->citoyen)['message'], 'incomplet'));
        $this->actingAs($this->citoyen)->get(route('paiement.show', $this->souscription->reference))->assertSee('data-incomplet', false)
            ->assertSee(format_fcfa(20_000))->assertSee(format_fcfa(30_000))->assertSee('Joindre la preuve de paiement');

        // Relance 24 h avant la fin du délai de complément (une seule), pas de relance d'échéance ordinaire.
        $this->travel(23)->hours();
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();
        $this->assertSame(0, Relance::query()->where('type', Relance::COMPLEMENT)->count());
        $this->travel(2)->hours();
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();
        $this->assertSame([Relance::COMPLEMENT], Relance::query()->pluck('type')->all());
    }

    public function test_delai_parametre_par_le_regulateur_et_confirme_par_le_validateur(): void
    {
        $this->assertSame(48 * 3_600, ParametresPaiements::delaiSecondes('complement'));
        $this->en($this->regulateur)->put(route('admin.parametres-paiements.update'), ['delai_caution_valeur' => 72, 'delai_caution_duree' => 'HEURE', 'delai_complement_valeur' => 72, 'delai_complement_duree' => 'HEURE', 'delai_avance_valeur' => 30, 'delai_avance_duree' => 'JOUR', 'delai_kyc_valeur' => 48, 'delai_kyc_duree' => 'HEURE'])->assertSessionHas('succes');
        $this->en($this->regulateur)->post(route('admin.parametres-paiements.soumettre'))->assertSessionHas('succes');
        $this->assertSame(48 * 3_600, ParametresPaiements::delaiSecondes('complement'));

        // Ni le CONTRÔLEUR, ni le GESTIONNAIRE n'y touchent ; le VALIDATEUR confirme dans « À valider › Données financières ».
        $this->en($this->controleur)->get(route('admin.parametres-paiements'))->assertForbidden();
        $fiche = ParametresPaiements::fiche();
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['delais:'.$fiche->id], 'decision' => 'confirmer', 'mode' => 'ligne'])
            ->assertSessionHas('succes');
        $this->assertSame(72 * 3_600, ParametresPaiements::delaiSecondes('complement'));

        $this->incomplet();
        $this->assertEqualsWithDelta(72, now()->diffInHours($this->souscription->fresh()->expire_le), 0.1);
    }

    public function test_cloture_et_remboursement_a_l_echeance_sans_complement(): void
    {
        $paiement = $this->incomplet();

        $this->travel(49)->hours();
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();

        $this->assertSame(StatutSouscription::Expiree, $this->souscription->fresh()->statut);
        $this->assertSame(StatutPaiement::Annule, $paiement->fresh()->statut);
        $r = Remboursement::query()->sole();
        $this->assertSame([Remboursement::COMPLEMENT_NON_RECU, 20_000, $paiement->id, Remboursement::A_EFFECTUER], [$r->origine, $r->montant, $r->paiement_id, $r->statut]);
        // Pas de second remboursement aux passages suivants.
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();
        $this->assertSame(1, Remboursement::query()->count());
    }

    public function test_complement_recu_dans_le_delai(): void
    {
        $paiement = $this->incomplet();
        $this->travel(20)->hours();
        $this->actingAs($this->citoyen)->post(route('paiement.preuve', $this->souscription->reference), ['preuve' => UploadedFile::fake()->create('complement.pdf', 100, 'application/pdf')])
            ->assertSessionHasNoErrors();
        $this->assertTrue($paiement->fresh()->enControle());
        $this->en($this->controleur)->post(route('admin.paiements.controle', $paiement), ['controle' => 'RECU', 'controle_reference' => 'AVIS-2'])->assertSessionHas('succes');

        $this->assertSame(StatutSouscription::Payee, $this->souscription->fresh()->statut);
        $this->travel(5)->days();
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();
        $this->assertSame(0, Remboursement::query()->count());
    }
}
