<?php

namespace Tests\Feature\Admin;

use App\Enums\CanalSouscription;
use App\Enums\CompteDestination;
use App\Enums\FamilleMoyenPaiement;
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
use App\Enums\UsageMoyenPaiement;
use App\Models\AuditLog;
use App\Models\CandidatureBoutique;
use App\Models\MoyenPaiement;
use App\Models\Paiement;
use App\Models\ParametrageDecision;
use App\Models\Souscription;
use App\Models\Tranche;
use App\Models\User;
use Database\Seeders\MoyensPaiementSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Compte\CandidatureBoutiqueTest;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/**
 * Référentiel des moyens de paiement : contenu de départ, droits par profil, circuit soumission puis validation
 * (« À valider › Données financières »), filtre par usage et par plafond côté citoyen, désactivation sans suppression.
 */
class MoyensPaiementTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private const ACTIFS = ['FASO_ARZEKA', 'ORANGE_MONEY', 'MOOV_MONEY', 'TELECEL_MONEY', 'VIREMENT', 'VERSEMENT', 'CARTE_BANCAIRE', 'ESPECES'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
    }

    /** Champs de la fenêtre d'ajout ou de modification. */
    private function champs(array $surcharges = []): array
    {
        return array_replace_recursive([
            'libelle' => 'Sank Money', 'famille' => 'MOBILE_MONEY', 'instructions' => 'Numéro marchand : 77 00 00 00', 'ordre' => 10,
            'usages' => ['ACTIONS' => ['autorise' => 1, 'min' => '', 'max' => ''], 'CAUTION' => ['autorise' => 1, 'min' => '', 'max' => '']],
        ], $surcharges);
    }

    /** Candidature à une boutique dont la caution à payer vaut ce montant. */
    private function caution(int $montant, array $canaux): CandidatureBoutique
    {
        $campagne = CandidatureBoutiqueTest::campagneOuverte();
        $campagne->forceFill(['canaux_actifs' => $canaux])->save();
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+22670123456']);
        $this->actingAs($citoyen)->post('/boutiques/candidater', [
            'categorie' => $campagne->categories()->where('libelle', 'Grande boutique')->value('id'),
            'engagements' => ['TIRAGE_SANS_GARANTIE', 'CAUTION_SANS_LIEN_ACTIONS', 'REGLEMENT_CAMPAGNE_BOUTIQUES', 'ORIGINE_LICITE_FONDS'],
        ])->assertRedirect();
        $candidature = CandidatureBoutique::sole();
        $candidature->forceFill(['caution' => $montant, 'avance' => 0])->save();

        return $candidature->fresh();
    }

    public function test_seeder_moyens_de_depart_confirmes_sans_validation(): void
    {
        $this->assertSame([...self::ACTIFS, 'CORIS_MONEY', 'WAVE'], MoyenPaiement::query()->orderBy('ordre')->pluck('code')->all());
        $this->assertSame(self::ACTIFS, MoyenPaiement::proposables()->pluck('code')->all());
        $this->assertSame(['CORIS_MONEY', 'WAVE'], MoyenPaiement::query()->where('actif', false)->orderBy('ordre')->pluck('code')->all());
        foreach (MoyenPaiement::all() as $m) {
            $this->assertTrue($m->par_defaut && $m->statut === StatutTypeBoutique::Valide, $m->code);
            foreach (UsageMoyenPaiement::cases() as $u) {
                $this->assertSame(['autorise' => true, 'min' => null, 'max' => null], $m->usage($u), $m->code.' '.$u->value);
            }
            $premiere = $m->versions()->sortBy('numero')->first();
            $this->assertSame([1, null], [$premiere->numero, $premiere->auteur_id], $m->code);
        }
        $this->assertSame(FamilleMoyenPaiement::PlateformePublique, MoyenPaiement::parCode('FASO_ARZEKA')->famille);
        $this->assertSame(FamilleMoyenPaiement::Banque, MoyenPaiement::parCode('VERSEMENT')->famille);

        // Rejoué : rien de créé ni de modifié, aucune décision de validation.
        $this->seed(MoyensPaiementSeeder::class);
        $this->seed(MoyensPaiementSeeder::class);
        $this->assertSame(10, MoyenPaiement::query()->count());
        $this->assertSame(FamilleMoyenPaiement::CarteBancaire, MoyenPaiement::parCode('CARTE_BANCAIRE')->famille);
        $this->assertSame(0, ParametrageDecision::query()->count());
        // Aucune clé ni aucun secret d'opérateur dans la table.
        $this->assertEmpty(array_filter(DB::getSchemaBuilder()->getColumnListing('moyens_paiement'), fn ($c) => preg_match('/cle|secret|token|api/i', $c)));
    }

    public function test_droits_par_profil(): void
    {
        $gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->en($gestionnaire)->get(route('admin.referencement.moyens'))->assertForbidden();
        $this->en($this->regulateur)->get(route('admin.referencement.moyens'))->assertOk()->assertSee('Ajouter un moyen de paiement')->assertSee('Coris Money')
            ->assertSee('<dialog id="ajouter-moyens"', false);
        $this->en($this->regulateur)->get(route('admin.tableau'))->assertSee('href="'.route('admin.referencement.moyens').'"', false);
        $this->en($this->superAdmin)->get(route('admin.referencement.moyens'))->assertOk()->assertDontSee('Ajouter un moyen de paiement');
        foreach ([$this->superAdmin, $this->validateur, $gestionnaire] as $profil) {
            $this->en($profil)->post(route('admin.referencement.zones.store', 'moyens'), ['code' => 'SANK_MONEY', ...$this->champs()])->assertForbidden();
        }
    }

    public function test_circuit_soumission_puis_validation(): void
    {
        // Ajout : soumis au VALIDATEUR, pas encore proposé.
        $this->en($this->regulateur)->post(route('admin.referencement.zones.store', 'moyens'), ['code' => 'SANK_MONEY', ...$this->champs()])->assertSessionHas('succes');
        $sank = MoyenPaiement::query()->where('code', 'SANK_MONEY')->sole();
        $this->assertSame(StatutTypeBoutique::AValider, $sank->statut);
        $this->assertNotContains('SANK_MONEY', MoyenPaiement::proposables()->pluck('code')->all());
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'finances-sites'))->assertSee('<title>Données financières', false)->assertSee('SANK_MONEY · Sank Money');
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'referentiels'))->assertDontSee('SANK_MONEY');
        $this->en($this->validateur)->post(route('admin.referencement.zones.confirmer', ['moyens', $sank->id]))->assertSessionHas('succes');
        $this->assertContains('SANK_MONEY', MoyenPaiement::proposables()->pluck('code')->all());

        // Modification d'un moyen confirmé (plafond) : soumise, appliquée seulement à la validation.
        $orange = MoyenPaiement::parCode('ORANGE_MONEY');
        $this->en($this->regulateur)->put(route('admin.referencement.zones.update', ['moyens', $orange->id]), $this->champs([
            'libelle' => 'Orange Money', 'famille' => 'MOBILE_MONEY', 'instructions' => '', 'ordre' => 2, 'usages' => ['CAUTION' => ['max' => 1000000]],
        ]))->assertSessionHas('succes', __('admin.zones.messages.modification_soumise'));
        $this->assertNull($orange->refresh()->usage(UsageMoyenPaiement::Caution)['max']);
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'finances-sites'))->assertSee('ORANGE_MONEY · Orange Money');
        $this->en($this->regulateur)->post(route('admin.referencement.zones.confirmer', ['moyens', $orange->id]))->assertForbidden();
        $this->en($this->validateur)->post(route('admin.referencement.zones.confirmer', ['moyens', $orange->id]))->assertSessionHas('succes');
        $this->assertSame(1_000_000, $orange->refresh()->usage(UsageMoyenPaiement::Caution)['max']);

        // Plafonds incohérents : refusés.
        $this->en($this->regulateur)->put(route('admin.referencement.zones.update', ['moyens', $sank->id]), $this->champs(['usages' => ['ACTIONS' => ['min' => 5000, 'max' => 1000]]]))
            ->assertSessionHasErrors('usages.ACTIONS.max');
    }

    public function test_filtre_par_usage_et_par_plafond(): void
    {
        MoyenPaiement::parCode('ORANGE_MONEY')->forceFill(['usages' => [
            'ACTIONS' => ['autorise' => true, 'min' => null, 'max' => null], 'CAUTION' => ['autorise' => true, 'min' => null, 'max' => 1_000_000]]])->save();
        MoyenPaiement::parCode('TELECEL_MONEY')->forceFill(['usages' => [
            'ACTIONS' => ['autorise' => true, 'min' => null, 'max' => null], 'CAUTION' => ['autorise' => false, 'min' => null, 'max' => null]]])->save();
        MoyenPaiement::parCode('VERSEMENT')->forceFill(['usages' => [
            'ACTIONS' => ['autorise' => true, 'min' => null, 'max' => null], 'CAUTION' => ['autorise' => true, 'min' => 2_500_000, 'max' => null]]])->save();

        // Caution de 2 139 744 FCFA : Orange Money plafonné à 1 000 000 et Telecel (non autorisé) ne sont pas proposés.
        $c = $this->caution(2_139_744, ['ORANGE_MONEY', 'TELECEL_MONEY', 'FASO_ARZEKA', 'VIREMENT', 'VERSEMENT']);
        $this->assertSame(['FASO_ARZEKA', 'VIREMENT'], array_map(fn ($m) => $m->code, $c->canauxPaiement()));
        $page = $this->actingAs($c->user)->get('/boutiques/candidatures/'.$c->reference.'/caution')->assertOk();
        $page->assertSee("2\u{00A0}139\u{00A0}744\u{00A0}FCFA")->assertSee('Faso Arzeka')->assertSee('Virement bancaire')
            ->assertDontSee('Orange Money')->assertDontSee('Telecel Money')->assertDontSee('Versement bancaire');
        $this->actingAs($c->user)->post('/boutiques/candidatures/'.$c->reference.'/caution', ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56'])
            ->assertSessionHasErrors('canal');

        // Petite caution : Orange Money redevient proposé.
        $c->forceFill(['caution' => 500_000])->save();
        $this->assertSame(['FASO_ARZEKA', 'ORANGE_MONEY', 'VIREMENT'], array_map(fn ($m) => $m->code, $c->fresh()->canauxPaiement()));
    }

    public function test_desactivation_sans_suppression(): void
    {
        $c = $this->caution(500_000, ['ORANGE_MONEY', 'MOOV_MONEY', 'VIREMENT']);
        $this->actingAs($c->user)->post('/boutiques/candidatures/'.$c->reference.'/caution', ['canal' => 'MOOV_MONEY', 'telephone' => '70 12 34 56'])->assertSessionHasNoErrors();
        $paiement = Paiement::query()->sole();
        $moov = MoyenPaiement::parCode('MOOV_MONEY');

        // Désactivation d'un moyen confirmé : soumise, puis validée.
        $this->en($this->regulateur)->post(route('admin.referencement.zones.desactiver', ['moyens', $moov->id]))->assertSessionHas('succes');
        $this->assertTrue($moov->refresh()->actif);
        $this->en($this->validateur)->post(route('admin.referencement.zones.confirmer', ['moyens', $moov->id]))->assertSessionHas('succes');
        $this->assertFalse($moov->refresh()->actif);

        // Plus proposé ; l'historique garde le moyen utilisé.
        $this->assertSame(['ORANGE_MONEY', 'VIREMENT'], array_map(fn ($m) => $m->code, $c->fresh()->canauxPaiement()));
        $this->assertSame('Moov Africa Money', $paiement->fresh()->canal->label());

        // Jamais supprimé, même inutilisé.
        $wave = MoyenPaiement::parCode('WAVE');
        $this->en($this->regulateur)->delete(route('admin.referencement.zones.destroy', ['moyens', $wave->id]))->assertSessionHas('erreur', __('admin.moyens_paiement.regles.jamais_supprime'));
        $this->assertModelExists($wave);
        $this->en($this->regulateur)->get(route('admin.referencement.zones.show', ['moyens', $wave->id]))->assertOk()->assertDontSee('>Supprimer<', false);
    }

    public function test_separation_des_comptes_de_destination(): void
    {
        config(['raaga.compte_sequestre' => ['titulaire' => 'Raaga SA · investissement', 'banque' => 'Banque A', 'numero' => 'BF00 INVEST'],
            'raaga.compte_location' => ['titulaire' => 'Raaga SA · location', 'banque' => 'Banque B', 'numero' => 'BF00 LOCATION']]);

        // Caution : compte des fonds de location, partout (paiement, instructions de virement, reçu).
        $c = $this->caution(500_000, ['ORANGE_MONEY', 'VIREMENT']);
        $this->actingAs($c->user)->get('/boutiques/candidatures/'.$c->reference.'/caution')->assertSee('BF00 LOCATION')->assertDontSee('BF00 INVEST');
        $this->actingAs($c->user)->post('/boutiques/candidatures/'.$c->reference.'/caution', ['canal' => 'VIREMENT'])->assertSessionHasNoErrors();
        $caution = Paiement::query()->sole();
        $this->assertSame(CompteDestination::Location, $caution->compte_destination);
        $this->assertSame('LOCATION', AuditLog::query()->where('action', 'paiement.initie')->sole()->nouvelle_valeur['compte']);

        // Souscription d'actions : compte des fonds d'investissement.
        $s = $this->souscription();
        $this->actingAs($s->user)->get('/paiement/'.$s->reference)->assertSee('BF00 INVEST')->assertDontSee('BF00 LOCATION');
        $this->actingAs($s->user)->post('/paiement/'.$s->reference, ['canal' => 'VIREMENT'])->assertSessionHasNoErrors();
        $this->assertSame(CompteDestination::Investissement, Paiement::query()->where('souscription_id', $s->id)->sole()->compte_destination);

        // Aucun mélange possible : refusé par le modèle et par la base.
        try {
            $caution->forceFill(['compte_destination' => CompteDestination::Investissement])->save();
            $this->fail('Caution envoyée au compte d’investissement.');
        } catch (LogicException) {
        }
        try {
            DB::transaction(fn () => DB::table('paiements')->where('id', $caution->id)->update(['compte_destination' => 'INVESTISSEMENT']));
            $this->fail('Mélange accepté par la base.');
        } catch (QueryException) {
        }
        $this->assertSame(CompteDestination::Location, $caution->fresh()->compte_destination);
    }

    public function test_carte_bancaire_par_la_page_de_l_operateur_seulement(): void
    {
        $s = $this->souscription(['CARTE_BANCAIRE']);
        $this->actingAs($s->user)->get('/paiement/'.$s->reference)->assertOk()->assertSee('Carte bancaire')->assertSee('data-carte-operateur', false)
            ->assertDontSee('name="numero_carte"', false)->assertDontSee('autocomplete="cc-number"', false);

        // Un numéro de carte envoyé malgré tout n'est jamais conservé.
        $this->actingAs($s->user)->post('/paiement/'.$s->reference, ['canal' => 'CARTE_BANCAIRE', 'numero_carte' => '4111111111111111', 'cvv' => '123'])->assertSessionHasNoErrors();
        $paiement = Paiement::query()->sole();
        $this->assertSame('CARTE_BANCAIRE', $paiement->getRawOriginal('canal'));
        $this->assertStringNotContainsString('4111', json_encode($paiement->getAttributes()));
        $this->assertFalse(AuditLog::query()->where('nouvelle_valeur', 'like', '%4111%')->exists());
    }

    /** Souscription d'actions réservée, en attente de paiement. */
    private function souscription(array $canaux = ['ORANGE_MONEY', 'VIREMENT']): Souscription
    {
        $tranche = Tranche::create([
            'statut' => StatutTranche::Ouverte, 'nom' => 'Tranche 1', 'valeur_unitaire' => 10_000, 'min_actions_par_souscription' => 1,
            'objectif_montant' => 3_000_000_000, 'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addDays(30),
            'publics_autorises' => [PublicAutorise::ResidentBf->value], 'mode_saisie' => ModeSaisie::NbActions, 'souscriptions_multiples' => true,
            'regle_arret' => RegleArret::ArretAObjectif, 'methode_allocation' => MethodeAllocation::Prorata, 'regle_si_echec' => RegleSiEchec::RemboursementIntegral,
            'delai_remboursement_jours' => 30, 'frais_paiement_supportes_par' => PayeurFrais::Raaga, 'frais_souscription_type' => TypeFraisSouscription::Aucun,
            'canaux_actifs' => $canaux, 'delai_paiement_heures' => 48, 'version' => 1, 'cree_par' => $this->regulateur->id,
        ]);
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'pays_residence' => 'BF', 'telephone' => '+22670999999']);

        return Souscription::create([
            'reference' => 'RAA-B-TEST0001', 'user_id' => $citoyen->id, 'tranche_id' => $tranche->id, 'nb_actions_demandees' => 25, 'valeur_unitaire' => 10_000,
            'montant' => 250_000, 'frais_souscription' => 0, 'canal' => CanalSouscription::Web, 'statut' => StatutSouscription::Reservee, 'expire_le' => now()->addHours(48),
        ]);
    }
}
