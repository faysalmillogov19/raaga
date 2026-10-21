<?php

namespace Tests\Feature\Guichet;

use App\Enums\CanalSouscription;
use App\Enums\DocumentAccepte;
use App\Enums\Droit;
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
use App\Models\Duree;
use App\Models\Tranche;
use App\Models\User;
use App\Services\Guichet\SouscriptionGuichet;
use App\Services\Souscriptions\PreparationSouscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\ParametresInvestissementConfirmes;
use Tests\TestCase;

/**
 * Souscription d'actions au guichet (service) : compte créé par l'agent, identité validée sur place, souscription avec
 * les contrôles de la souscription en ligne, encaissement définitif (espèces, mobile money ou carte avec référence).
 */
class SouscriptionGuichetTest extends TestCase
{
    use ParametresInvestissementConfirmes, RefreshDatabase;

    private User $agent;

    private SouscriptionGuichet $guichet;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->agent = User::factory()->role(Role::AgentGuichet)->create();
        $this->guichet = app(SouscriptionGuichet::class);
    }

    private function trancheOuverte(array $canaux = ['ESPECES', 'ORANGE_MONEY', 'CARTE_BANCAIRE', 'VIREMENT']): Tranche
    {
        config(['raaga.phase.presentation_actions' => true, 'raaga.phase.souscription_actions' => true]);
        Cache::flush();
        $this->confirmerParametresInvestissement(10_000, minimum: 1);

        return Tranche::create([
            'statut' => StatutTranche::Ouverte, 'nom' => 'Tranche 1', 'valeur_unitaire' => 10_000, 'min_actions_par_souscription' => 1,
            'objectif_montant' => 3_000_000_000, 'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addDays(30),
            'publics_autorises' => [PublicAutorise::ResidentBf->value], 'mode_saisie' => ModeSaisie::NbActions, 'souscriptions_multiples' => true,
            'regle_arret' => RegleArret::ArretAObjectif, 'methode_allocation' => MethodeAllocation::Prorata, 'regle_si_echec' => RegleSiEchec::RemboursementIntegral,
            'delai_remboursement_jours' => 30, 'frais_paiement_supportes_par' => PayeurFrais::Raaga, 'frais_souscription_type' => TypeFraisSouscription::Aucun,
            'frais_souscription_valeur' => 0, 'canaux_actifs' => $canaux, 'delai_paiement_valeur' => 48, 'delai_paiement_duree_id' => Duree::parCode('HEURE')->id,
            'delai_paiement_secondes' => 172_800, 'version' => 1, 'cree_par' => User::factory()->create()->id,
        ]);
    }

    private function identite(): array
    {
        return ['type_titulaire' => 'PHYSIQUE', 'nom' => 'Sawadogo', 'prenoms' => 'Issa', 'date_naissance' => '1990-05-12',
            'pays_residence' => 'BF', 'region' => config('raaga.inscription.regions')[0], 'conditions' => '1'];
    }

    private function pieces(): array
    {
        return ['recto' => UploadedFile::fake()->image('recto.jpg'), 'verso' => UploadedFile::fake()->image('verso.jpg'), 'selfie' => UploadedFile::fake()->image('selfie.jpg')];
    }

    private function engagements(): array
    {
        return array_map(fn (DocumentAccepte $d) => $d->value, PreparationSouscription::ENGAGEMENTS);
    }

    public function test_seul_l_agent_de_guichet_a_le_droit_de_faire_souscrire(): void
    {
        $this->assertContains(Droit::GuichetSouscrire, Droit::pour(Role::AgentGuichet));
        foreach ([Role::Admin, Role::SuperAdmin, Role::Regulateur, Role::Validateur, Role::ControleurPaiements, Role::Citoyen] as $role) {
            $this->assertNotContains(Droit::GuichetSouscrire, Droit::pour($role), $role->value);
        }
    }

    public function test_parcours_complet_compte_identite_souscription_et_encaissement_en_especes(): void
    {
        $this->trancheOuverte();
        $issa = $this->guichet->creerCompte($this->agent, '+22670111222', $this->identite(), '10.0.0.1', 'guichet');
        $this->assertTrue($issa->hasRole(Role::Citoyen));
        $this->assertSame('SAWADOGO', $issa->nom);
        $this->assertDatabaseHas('audit_logs', ['action' => 'compte.cree', 'acteur_id' => $this->agent->id, 'entite_id' => (string) $issa->id]);

        $this->guichet->validerIdentite($this->agent, $issa, TypeDocumentKyc::Cnib, $this->pieces(),
            ['numero_piece' => 'B12345678', 'piece_expire_le' => now()->addYears(3)->toDateString()]);
        $this->assertSame(StatutKyc::Valide, $issa->fresh()->statut_kyc);
        $this->assertDatabaseHas('audit_logs', ['action' => 'kyc.conforme', 'acteur_id' => $this->agent->id]);

        $s = $this->guichet->souscrire($this->agent, $issa->fresh(), 5, $this->engagements(), null, '10.0.0.1', 'guichet');
        $this->assertSame(CanalSouscription::Guichet, $s->canal);
        $this->assertSame($this->agent->id, $s->agent_id);
        $this->assertSame(50_000, $s->montant);
        // Seuls les moyens encaissables au guichet sont proposés (pas le virement).
        $this->assertSame(['CARTE_BANCAIRE', 'ESPECES', 'ORANGE_MONEY'], collect($this->guichet->moyens($s))->pluck('code')->sort()->values()->all());

        $p = $this->guichet->encaisser($this->agent, $s, 'ESPECES', null);
        $this->assertSame(StatutPaiement::Confirme, $p->statut);
        $this->assertSame($this->agent->id, $p->fresh()->agent_id);
        $this->assertSame(StatutSouscription::Payee, $s->fresh()->statut);
        $this->assertDatabaseHas('audit_logs', ['action' => 'paiement.confirme', 'acteur_id' => $this->agent->id]);
    }

    public function test_mobile_money_et_carte_exigent_une_reference_unique(): void
    {
        $this->trancheOuverte();
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'pays_residence' => 'BF']);
        $s = $this->guichet->souscrire($this->agent, $citoyen, 2, $this->engagements(), null, '10.0.0.1', 'guichet');

        try {
            $this->guichet->encaisser($this->agent, $s, 'ORANGE_MONEY', ' ');
            $this->fail('Référence exigée');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reference', $e->errors());
        }
        $this->guichet->encaisser($this->agent, $s, 'ORANGE_MONEY', 'OM-2026-0001');
        $this->assertSame(StatutSouscription::Payee, $s->fresh()->statut);

        $autre = $this->guichet->souscrire($this->agent, $citoyen, 1, $this->engagements(), null, '10.0.0.1', 'guichet');
        $this->expectException(ValidationException::class);
        $this->guichet->encaisser($this->agent, $autre, 'CARTE_BANCAIRE', 'OM-2026-0001');
    }

    public function test_refus_sans_identite_validee_sans_tranche_ou_sans_moyen_encaissable(): void
    {
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::NonSoumis, 'pays_residence' => 'BF']);
        try {
            $this->guichet->souscrire($this->agent, $citoyen, 1, $this->engagements(), null, '10.0.0.1', 'guichet');
            $this->fail('Aucune tranche');
        } catch (ValidationException $e) {
            $this->assertSame([__('admin.guichet.souscription.erreurs.aucune_tranche')], $e->errors()['nb_actions']);
        }

        $tranche = $this->trancheOuverte(['VIREMENT']);
        try {
            $this->guichet->souscrire($this->agent, $citoyen, 1, $this->engagements(), null, '10.0.0.1', 'guichet');
            $this->fail('Identité non validée');
        } catch (ValidationException $e) {
            $this->assertSame([__('admin.guichet.souscription.erreurs.identite')], $e->errors()['nb_actions']);
        }

        $citoyen->forceFill(['statut_kyc' => StatutKyc::Valide])->save();
        try {
            $this->guichet->souscrire($this->agent, $citoyen, 1, $this->engagements(), null, '10.0.0.1', 'guichet');
            $this->fail('Aucun moyen encaissable');
        } catch (ValidationException $e) {
            $this->assertSame([__('admin.guichet.souscription.erreurs.aucun_moyen')], $e->errors()['nb_actions']);
        }
        // Rien n'a été réservé.
        $this->assertSame(0, $tranche->souscriptions()->count());
    }

    public function test_les_controles_de_la_souscription_en_ligne_s_appliquent(): void
    {
        $this->trancheOuverte();
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'pays_residence' => 'BF']);

        $this->expectException(ValidationException::class);
        $this->guichet->souscrire($this->agent, $citoyen, 1, [DocumentAccepte::AucunDroitBoutique->value], null, '10.0.0.1', 'guichet');
    }

    public function test_un_numero_du_back_office_est_refuse(): void
    {
        User::factory()->role(Role::Validateur)->create(['telephone' => '+22670999888']);

        $this->expectException(ValidationException::class);
        $this->guichet->personne('+22670999888');
    }
}
