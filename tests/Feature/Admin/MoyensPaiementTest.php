<?php

namespace Tests\Feature\Admin;

use App\Enums\FamilleMoyenPaiement;
use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Enums\StatutTypeBoutique;
use App\Enums\UsageMoyenPaiement;
use App\Models\CandidatureBoutique;
use App\Models\MoyenPaiement;
use App\Models\Paiement;
use App\Models\ParametrageDecision;
use App\Models\User;
use Database\Seeders\MoyensPaiementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    private const ACTIFS = ['FASO_ARZEKA', 'ORANGE_MONEY', 'MOOV_MONEY', 'TELECEL_MONEY', 'VIREMENT', 'VERSEMENT', 'ESPECES'];

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
        $this->assertSame(9, MoyenPaiement::query()->count());
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
}
