<?php

namespace Tests\Feature\Admin;

use App\Enums\CanalSouscription;
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
use App\Enums\StatutTypeBoutique;
use App\Enums\TypeFraisSouscription;
use App\Enums\TypeMoyenPaiement;
use App\Models\MoyenPaiement;
use App\Models\Paiement;
use App\Models\ParametrageDecision;
use App\Models\Souscription;
use App\Models\Tranche;
use App\Models\User;
use Database\Seeders\MoyensPaiementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/** Référentiel des moyens de paiement : contenu par défaut, gestion RÉGULATEUR et VALIDATEUR, effet sur le paiement. */
class MoyensPaiementTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private const DEFAUTS = ['ORANGE_MONEY' => 'Orange Money', 'MOOV_MONEY' => 'Moov Africa Money', 'TELECEL_MONEY' => 'Telecel Money',
        'FASO_ARZEKA' => 'Faso Arzeka', 'VIREMENT' => 'Virement bancaire', 'ESPECES' => 'Espèces au guichet'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
    }

    private function souscription(array $canaux): Souscription
    {
        $tranche = Tranche::create([
            'statut' => StatutTranche::Ouverte, 'nom' => 'Tranche 1', 'valeur_unitaire' => 10_000, 'min_actions_par_souscription' => 1,
            'objectif_montant' => 3_000_000_000, 'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addDays(30),
            'publics_autorises' => [PublicAutorise::ResidentBf->value], 'mode_saisie' => ModeSaisie::NbActions, 'souscriptions_multiples' => true,
            'regle_arret' => RegleArret::ArretAObjectif, 'methode_allocation' => MethodeAllocation::Prorata, 'regle_si_echec' => RegleSiEchec::RemboursementIntegral,
            'delai_remboursement_jours' => 30, 'frais_paiement_supportes_par' => PayeurFrais::Raaga, 'frais_souscription_type' => TypeFraisSouscription::Aucun,
            'canaux_actifs' => $canaux, 'delai_paiement_heures' => 48, 'version' => 1, 'cree_par' => $this->regulateur->id,
        ]);
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'pays_residence' => 'BF', 'telephone' => '+22670123456']);

        return Souscription::create([
            'reference' => 'RAA-B-TEST0001', 'user_id' => $citoyen->id, 'tranche_id' => $tranche->id, 'nb_actions_demandees' => 25, 'valeur_unitaire' => 10_000,
            'montant' => 250_000, 'frais_souscription' => 0, 'canal' => CanalSouscription::Web, 'statut' => StatutSouscription::Reservee, 'expire_le' => now()->addHours(48),
        ]);
    }

    public function test_moyens_par_defaut_confirmes_et_seeder_rejouable(): void
    {
        $this->assertSame(self::DEFAUTS, MoyenPaiement::query()->orderBy('ordre')->pluck('libelle', 'code')->all());
        foreach (MoyenPaiement::all() as $m) {
            $this->assertTrue($m->par_defaut && $m->actif && $m->statut === StatutTypeBoutique::Valide, $m->code);
            $premiere = $m->versions()->sortBy('numero')->first();
            $this->assertSame([1, null], [$premiere->numero, $premiere->auteur_id], $m->code);
        }
        $this->assertSame(TypeMoyenPaiement::Passerelle, MoyenPaiement::parCode('FASO_ARZEKA')->type);
        $this->assertSame(['ORANGE_MONEY', 'MOOV_MONEY', 'TELECEL_MONEY'], MoyenPaiement::proposables([TypeMoyenPaiement::MobileMoney])->pluck('code')->all());

        // Rejoué : rien de créé ni de modifié.
        $this->seed(MoyensPaiementSeeder::class);
        $this->seed(MoyensPaiementSeeder::class);
        $this->assertSame(self::DEFAUTS, MoyenPaiement::query()->orderBy('ordre')->pluck('libelle', 'code')->all());
        $this->assertSame(0, ParametrageDecision::query()->count());
    }

    public function test_le_regulateur_ajoute_un_moyen_que_le_validateur_confirme(): void
    {
        $this->en($this->regulateur)->get(route('admin.referencement.moyens'))->assertOk()->assertSee('Telecel Money')->assertSee('Faso Arzeka')->assertSee('Ajouter un moyen de paiement');
        $this->en($this->regulateur)->post(route('admin.referencement.zones.store', 'moyens'), ['code' => 'SANK_MONEY', 'libelle' => 'Sank Money', 'type' => 'MOBILE_MONEY'])
            ->assertSessionHas('succes');
        $sank = MoyenPaiement::query()->where('code', 'SANK_MONEY')->sole();
        $this->assertSame([StatutTypeBoutique::AValider, 7], [$sank->statut, $sank->ordre]);
        $this->assertNotContains('SANK_MONEY', MoyenPaiement::proposables()->pluck('code')->all());

        // Dans « À valider › Référentiels », puis validé : proposé dans les tranches et les campagnes.
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'referentiels'))->assertSee('SANK_MONEY · Sank Money');
        $this->en($this->validateur)->post(route('admin.referencement.zones.confirmer', ['moyens', $sank->id]))->assertSessionHas('succes');
        $this->assertContains('SANK_MONEY', MoyenPaiement::proposables()->pluck('code')->all());
        $this->en($this->regulateur)->get(route('admin.tranches.create'))->assertSee('Sank Money');
        $this->en($this->regulateur)->get(route('admin.boutiques.create'))->assertSee('Sank Money');

        // Code invalide ou type inconnu : refusés.
        $this->en($this->regulateur)->post(route('admin.referencement.zones.store', 'moyens'), ['code' => 'sank money', 'libelle' => 'X', 'type' => 'CHEQUE'])
            ->assertSessionHasErrors(['code', 'type']);
    }

    public function test_droits_par_profil(): void
    {
        $gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->en($gestionnaire)->get(route('admin.referencement.moyens'))->assertForbidden();
        $this->en($this->superAdmin)->get(route('admin.referencement.moyens'))->assertOk()->assertDontSee('Ajouter un moyen de paiement');
        $this->en($this->superAdmin)->post(route('admin.referencement.zones.store', 'moyens'), ['code' => 'X_PAY', 'libelle' => 'X', 'type' => 'MOBILE_MONEY'])->assertForbidden();
        $this->en($this->validateur)->post(route('admin.referencement.zones.store', 'moyens'), ['code' => 'X_PAY', 'libelle' => 'X', 'type' => 'MOBILE_MONEY'])->assertForbidden();

        // Moyen par défaut : sa désactivation passe par le VALIDATEUR.
        $especes = MoyenPaiement::parCode('ESPECES');
        $this->en($this->regulateur)->post(route('admin.referencement.zones.desactiver', ['moyens', $especes->id]))->assertSessionHas('succes');
        $this->assertTrue($especes->refresh()->actif);
        $this->en($this->validateur)->post(route('admin.referencement.zones.confirmer', ['moyens', $especes->id]))->assertSessionHas('succes');
        $this->assertFalse($especes->refresh()->actif);
    }

    public function test_paiement_avec_les_moyens_du_referentiel(): void
    {
        $s = $this->souscription(['TELECEL_MONEY', 'FASO_ARZEKA', 'VIREMENT']);
        $page = $this->actingAs($s->user)->get('/paiement/'.$s->reference)->assertOk();
        $page->assertSee('Telecel Money')->assertSee('Faso Arzeka')->assertSee('Virement bancaire')->assertDontSee('Orange Money')
            ->assertSee('Vous allez payer en ligne sur la plateforme Faso Arzeka', false);

        // Mobile money : numéro exigé ; passerelle : sans numéro.
        $this->actingAs($s->user)->post('/paiement/'.$s->reference, ['canal' => 'TELECEL_MONEY'])->assertSessionHasErrors('telephone');
        $this->actingAs($s->user)->post('/paiement/'.$s->reference, ['canal' => 'FASO_ARZEKA'])->assertSessionHasNoErrors();
        $paiement = Paiement::query()->latest('id')->firstOrFail();
        $this->assertSame('FASO_ARZEKA', $paiement->getRawOriginal('canal'));
        $this->assertSame('Faso Arzeka', $paiement->canal->label());

        // Moyen désactivé : plus proposé ni accepté ; les paiements passés gardent leur libellé.
        MoyenPaiement::parCode('FASO_ARZEKA')->forceFill(['actif' => false])->save();
        $this->actingAs($s->user)->get('/paiement/'.$s->reference.'?changer=1');
        $this->actingAs($s->user)->post('/paiement/'.$s->reference, ['canal' => 'FASO_ARZEKA'])->assertSessionHasErrors('canal');
        $this->assertSame('Faso Arzeka', $paiement->fresh()->canal->label());
        $this->assertSame(['TELECEL_MONEY', 'VIREMENT'], array_map(fn ($m) => $m->code, $s->fresh()->canauxPaiement()));
    }
}
