<?php

namespace Tests\Feature\Admin;

use App\Enums\CanalSouscription;
use App\Enums\FaceDocument;
use App\Enums\MethodeAllocation;
use App\Enums\ModeSaisie;
use App\Enums\PayeurFrais;
use App\Enums\PublicAutorise;
use App\Enums\RegleArret;
use App\Enums\RegleSiEchec;
use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutKyc;
use App\Enums\StatutPaiement;
use App\Enums\StatutReservationBoutique;
use App\Enums\StatutSouscription;
use App\Enums\StatutTranche;
use App\Enums\TypeDocumentKyc;
use App\Enums\TypeFraisSouscription;
use App\Models\Boutique;
use App\Models\CorrectionPaiement;
use App\Models\Duree;
use App\Models\KycDocument;
use App\Models\Paiement;
use App\Models\Relance;
use App\Models\Remboursement;
use App\Models\ReservationBoutique;
use App\Models\Souscription;
use App\Models\Tranche;
use App\Models\User;
use App\Models\VerificationKyc;
use App\Notifications\AnnulationIdentite;
use App\Notifications\RelancePaiement;
use App\Services\Paiement\ControlePaiements;
use Database\Seeders\ComptesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * CONTRÔLEUR DES PAIEMENTS (rapprochement, paiements manuels, relances, remboursements) et vérification d'identité
 * par l'ADMIN (conforme ou douteux → annulation confirmée par le VALIDATEUR → libération → remboursement).
 */
class ControlePaiementsKycTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private User $controleur;

    private User $admin;

    private Tranche $tranche;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerActeurs();
        $this->controleur = User::factory()->role(Role::ControleurPaiements)->create();
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->tranche = Tranche::create([
            'statut' => StatutTranche::Ouverte, 'nom' => 'Tranche 1', 'valeur_unitaire' => 10_000, 'min_actions_par_souscription' => 1,
            'objectif_montant' => 1_000_000_000, 'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addDays(30),
            'publics_autorises' => [PublicAutorise::ResidentBf->value], 'mode_saisie' => ModeSaisie::NbActions, 'souscriptions_multiples' => true,
            'regle_arret' => RegleArret::ArretAObjectif, 'methode_allocation' => MethodeAllocation::Prorata, 'regle_si_echec' => RegleSiEchec::RemboursementIntegral,
            'delai_remboursement_jours' => 30, 'frais_paiement_supportes_par' => PayeurFrais::Raaga, 'frais_souscription_type' => TypeFraisSouscription::Aucun,
            'frais_souscription_valeur' => 0, 'canaux_actifs' => ['ORANGE_MONEY', 'VIREMENT'], 'delai_paiement_valeur' => 48, 'delai_paiement_duree_id' => Duree::parCode('HEURE')->id, 'delai_paiement_secondes' => 172_800, 'version' => 1,
            'cree_par' => $this->regulateur->id,
        ]);
    }

    private function citoyen(StatutKyc $kyc = StatutKyc::Valide, array $attributs = []): User
    {
        $this->n++;

        return User::factory()->role(Role::Citoyen)->create(['statut_kyc' => $kyc, 'telephone' => '+2267100'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT), ...$attributs]);
    }

    private function souscription(User $citoyen, StatutSouscription $statut = StatutSouscription::Reservee, array $attributs = []): Souscription
    {
        $this->n++;

        return Souscription::create([
            'reference' => 'RAA-B-TEST'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT), 'user_id' => $citoyen->id, 'tranche_id' => $this->tranche->id,
            'nb_actions_demandees' => 5, 'valeur_unitaire' => 10_000, 'montant' => 50_000, 'frais_souscription' => 0, 'canal' => CanalSouscription::Web,
            'statut' => $statut, 'expire_le' => now()->addHours(48), ...$attributs,
        ]);
    }

    private function paiement(Souscription|ReservationBoutique $objet, string $canal, StatutPaiement $statut = StatutPaiement::EnAttente): Paiement
    {
        $this->n++;

        return $objet->paiements()->create(['canal' => $canal, 'montant' => $objet->montantAPayer(), 'frais' => 0, 'reference' => 'PAY-TEST'.$this->n,
            'statut' => $statut, 'date_confirmation' => $statut === StatutPaiement::Confirme ? now() : null]);
    }

    private function dossierKyc(User $citoyen): void
    {
        foreach ([[TypeDocumentKyc::Cnib, FaceDocument::Recto], [TypeDocumentKyc::Cnib, FaceDocument::Verso], [TypeDocumentKyc::Selfie, null]] as [$type, $face]) {
            KycDocument::create(['user_id' => $citoyen->id, 'type' => $type, 'face' => $face, 'chemin_fichier' => 'kyc/test/'.$type->value.($face?->value ?? ''),
                'mime' => 'image/jpeg', 'taille' => 1000, 'empreinte_sha256' => str_repeat('a', 64), 'statut' => StatutDocumentKyc::EnAttente,
                'televerse_par' => $citoyen->id]);
        }
    }

    public function test_droits_par_profil(): void
    {
        $s = $this->souscription($this->citoyen());
        $p = $this->paiement($s, 'VIREMENT');
        $gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $dossier = route('admin.paiements.dossier', ['souscription', $s->id]);

        // CONTRÔLEUR : paiements, dossiers, remboursements ; ni KYC, ni grilles, ni moyens de paiement, ni confirmation.
        $this->en($this->controleur)->get(route('admin.paiements'))->assertOk()->assertSee($s->reference);
        $this->en($this->controleur)->get(route('admin.paiements', ['vue' => 'cautions']))->assertOk();
        $this->en($this->controleur)->get($dossier)->assertOk()->assertSee($p->reference)->assertSee('Contrôler');
        $this->en($this->controleur)->get(route('admin.remboursements'))->assertOk();
        foreach ([route('admin.kyc'), route('admin.boutiques.grilles'), route('admin.referencement.moyens'), route('admin.tranches')] as $url) {
            $this->en($this->controleur)->get($url)->assertForbidden();
        }
        $this->assertFalse($this->controleur->can('moyens_paiement.gerer'));
        $this->assertFalse($this->controleur->can('paiements.corrections_confirmer'));
        $this->assertFalse($this->controleur->can('remboursements.approuver'));

        // GESTIONNAIRE et SUPER_ADMIN : aucun accès aux paiements, aux remboursements ni aux vérifications d'identité.
        foreach ([$gestionnaire, $this->superAdmin] as $user) {
            foreach ([route('admin.paiements'), $dossier, route('admin.remboursements'), route('admin.kyc')] as $url) {
                $this->en($user)->get($url)->assertForbidden();
            }
            $this->en($user)->post(route('admin.paiements.controle', $p), ['controle' => 'RECU'])->assertForbidden();
        }

        // ADMIN : vérifications d'identité seulement ; VALIDATEUR : ni liste des paiements, ni marquage.
        $this->en($this->admin)->get(route('admin.kyc'))->assertOk();
        $this->en($this->admin)->get(route('admin.paiements'))->assertForbidden();
        $this->en($this->validateur)->get(route('admin.paiements'))->assertForbidden();
        $this->en($this->validateur)->post(route('admin.paiements.controle', $p), ['controle' => 'RECU'])->assertForbidden();
        $this->en($this->validateur)->get(route('admin.remboursements'))->assertOk();
    }

    public function test_paiement_manuel_recu_incomplet_rejete_et_paiement_operateur_sans_controle(): void
    {
        $s = $this->souscription($this->citoyen());
        $p = $this->paiement($s, 'ESPECES');

        // Incomplet : motif obligatoire ; le paiement reste en attente.
        $this->en($this->controleur)->post(route('admin.paiements.controle', $p), ['controle' => 'INCOMPLET'])->assertSessionHasErrors('motif');
        $this->en($this->controleur)->post(route('admin.paiements.controle', $p), ['controle' => 'INCOMPLET', 'motif' => 'Reçu de caisse illisible', 'controle_montant_recu' => 20_000])
            ->assertSessionHas('succes');
        $this->assertSame([StatutPaiement::EnAttente, 'INCOMPLET'], [$p->fresh()->statut, $p->fresh()->controle]);

        // Reçu, sur présentation du reçu de caisse : le dossier est payé.
        $this->en($this->controleur)->post(route('admin.paiements.controle', $p), ['controle' => 'RECU', 'controle_reference' => 'CAISSE-0042'])->assertSessionHas('succes');
        $this->assertSame([StatutPaiement::Confirme, 'RECU', 'CAISSE-0042', $this->controleur->id], [$p->fresh()->statut, $p->fresh()->controle, $p->fresh()->controle_reference, $p->fresh()->controle_par]);
        $this->assertSame(StatutSouscription::Payee, $s->fresh()->statut);

        // Rejeté : le paiement échoue, le dossier reste en attente.
        $s2 = $this->souscription($this->citoyen());
        $p2 = $this->paiement($s2, 'VIREMENT');
        $this->en($this->controleur)->post(route('admin.paiements.controle', $p2), ['controle' => 'REJETE', 'motif' => 'Aucun virement au relevé'])->assertSessionHas('succes');
        $this->assertSame([StatutPaiement::Echoue, StatutSouscription::Reservee], [$p2->fresh()->statut, $s2->fresh()->statut]);

        // Paiement d'un opérateur : personne n'a à le contrôler.
        $p3 = $this->paiement($this->souscription($this->citoyen()), 'ORANGE_MONEY');
        $this->en($this->controleur)->post(route('admin.paiements.controle', $p3), ['controle' => 'RECU'])->assertSessionHasErrors('controle');
        $this->assertSame(StatutPaiement::EnAttente, $p3->fresh()->statut);
    }

    public function test_correction_d_un_paiement_confirme_par_le_validateur_sans_auto_validation(): void
    {
        $s = $this->souscription($this->citoyen(), StatutSouscription::Payee, ['payee_le' => now()]);
        $p = $this->paiement($s, 'VIREMENT', StatutPaiement::Confirme);

        $this->en($this->controleur)->post(route('admin.paiements.correction', $p), ['motif' => 'Virement retourné par la banque'])->assertSessionHas('succes');
        $correction = CorrectionPaiement::query()->sole();

        // Le contrôleur ne valide pas sa propre correction (ni droit, ni service).
        $this->en($this->controleur)->post(route('admin.paiements.corrections.confirmer', $correction))->assertForbidden();
        $this->assertThrows(fn () => app(ControlePaiements::class)->confirmerCorrection($correction, $this->controleur), ValidationException::class);
        $this->assertSame(StatutPaiement::Confirme, $p->fresh()->statut);

        // Le VALIDATEUR la voit dans « À valider › Paiements » et la confirme : paiement annulé, dossier de nouveau en attente.
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'paiements'))->assertOk()->assertSee('correction:'.$correction->id);
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['correction:'.$correction->id], 'decision' => 'confirmer', 'mode' => 'ligne'])
            ->assertSessionHas('succes');
        $this->assertSame(CorrectionPaiement::CONFIRMEE, $correction->fresh()->statut);
        $this->assertSame([StatutPaiement::Annule, StatutSouscription::Reservee], [$p->fresh()->statut, $s->fresh()->statut]);
        $this->assertTrue($s->fresh()->expire_le->isFuture());
    }

    public function test_relances_planifiees_et_manuelles_tracees(): void
    {
        Notification::fake();
        $echeance = $this->souscription($this->citoyen(attributs: ['email' => 'awa@exemple.bf']), StatutSouscription::Reservee, ['expire_le' => now()->addHours(10)]);
        $lointaine = $this->souscription($this->citoyen(), StatutSouscription::Reservee, ['expire_le' => now()->addDays(3)]);
        $expiree = $this->souscription($this->citoyen(), StatutSouscription::Reservee, ['expire_le' => now()->subMinutes(5)]);

        $this->artisan('raaga:relancer-paiements')->assertSuccessful();
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();

        // Une seule relance par type et par dossier ; la réservation échue est expirée puis relancée.
        $this->assertSame([Relance::ECHEANCE], Relance::query()->where('souscription_id', $echeance->id)->pluck('type')->all());
        $this->assertSame(0, Relance::query()->where('souscription_id', $lointaine->id)->count());
        $this->assertSame(StatutSouscription::Expiree, $expiree->fresh()->statut);
        $this->assertSame([Relance::EXPIRATION], Relance::query()->where('souscription_id', $expiree->id)->pluck('type')->all());
        $this->assertSame(['notification', 'email'], Relance::query()->where('souscription_id', $echeance->id)->value('canaux'));
        $this->assertNull(Relance::query()->where('souscription_id', $echeance->id)->value('auteur_id'));
        Notification::assertSentTo($echeance->user, RelancePaiement::class, fn ($n, $canaux) => $canaux === ['database', 'mail']);

        // Relance manuelle depuis le dossier : tracée avec son auteur, visible dans le dossier.
        $this->en($this->controleur)->post(route('admin.paiements.relance', ['souscription', $lointaine->id]), ['message' => 'Pensez au virement.'])->assertSessionHas('succes');
        $this->assertSame($this->controleur->id, Relance::query()->where('souscription_id', $lointaine->id)->value('auteur_id'));
        $this->en($this->controleur)->get(route('admin.paiements.dossier', ['souscription', $lointaine->id]))->assertOk()
            ->assertSee('Manuelle')->assertSee($this->controleur->name)->assertSee('Pensez au virement.');
        // Dossier qui n'attend plus de paiement : pas de relance manuelle.
        $this->en($this->controleur)->post(route('admin.paiements.relance', ['souscription', $expiree->id]))->assertSessionHasErrors('relance');
    }

    public function test_circuit_kyc_douteux_annulation_liberation_remboursement(): void
    {
        $citoyen = $this->citoyen(StatutKyc::EnAttente, ['name' => 'Issa DOUTEUX']);
        $this->dossierKyc($citoyen);
        $s = $this->souscription($citoyen, StatutSouscription::Payee, ['payee_le' => now()]);
        $this->paiement($s, 'ORANGE_MONEY', StatutPaiement::Confirme);
        $enAttente = $this->souscription($citoyen);
        $this->paiement($enAttente, 'VIREMENT');

        $campagne = Boutiques::campagneOuverte();
        $boutique = Boutique::query()->firstOrFail();
        $boutique->forceFill(['statut' => StatutBoutique::Reservee])->save();
        $c = ReservationBoutique::create(['reference' => 'BTQ-DOUTE01', 'user_id' => $citoyen->id, 'campagne_boutiques_id' => $campagne->id,
            'boutique_id' => $boutique->id, 'caution' => 150_000, 'statut' => StatutReservationBoutique::Reservee, 'expire_le' => now()->subDay()]);
        $this->paiement($c, 'ORANGE_MONEY', StatutPaiement::Confirme);

        // L'ADMIN voit la pièce, sans aucun montant, et marque le dossier douteux (motif obligatoire).
        $this->en($this->admin)->get(route('admin.kyc'))->assertOk()->assertSee('Issa DOUTEUX')->assertDontSee('FCFA');
        $this->en($this->admin)->get(route('admin.kyc.dossier', $citoyen))->assertOk()->assertSee('Voir la pièce')->assertSee($s->reference)
            ->assertDontSee('FCFA')->assertDontSee('50 000')->assertDontSee('150 000');
        $this->en($this->admin)->post(route('admin.kyc.douteux', $citoyen), ['motif' => ''])->assertSessionHasErrors('motif');
        $this->en($this->admin)->post(route('admin.kyc.douteux', $citoyen), ['motif' => 'Photo de la CNIB retouchée'])->assertSessionHas('succes');
        $v = VerificationKyc::query()->sole();
        $this->assertSame(VerificationKyc::ANNULATION_PROPOSEE, $v->statut);

        // L'ADMIN ne confirme pas lui-même ; le VALIDATEUR la voit dans « À valider › Identités ».
        $this->en($this->admin)->post(route('admin.kyc.annulation.confirmer', $v))->assertForbidden();
        Notification::fake();
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'identites'))->assertOk()->assertSee('annulation:'.$v->id);
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['annulation:'.$v->id], 'decision' => 'confirmer', 'mode' => 'ligne'])
            ->assertSessionHas('succes');

        // Annulation : souscriptions et candidature annulées, boutique libérée, remboursements ouverts, citoyen notifié.
        $this->assertSame(VerificationKyc::ANNULATION_CONFIRMEE, $v->fresh()->statut);
        $this->assertSame(StatutKyc::Rejete, $citoyen->fresh()->statut_kyc);
        $this->assertSame([StatutSouscription::Annulee, StatutSouscription::Annulee], [$s->fresh()->statut, $enAttente->fresh()->statut]);
        $this->assertSame(StatutPaiement::Annule, $enAttente->paiements()->sole()->statut);
        $this->assertSame(StatutReservationBoutique::Annulee, $c->fresh()->statut);
        $this->assertSame(StatutBoutique::Disponible, $boutique->fresh()->statut);
        $this->assertSame(2, Remboursement::query()->where('statut', Remboursement::A_EFFECTUER)->count());
        $this->assertSame(50_000, (int) Remboursement::query()->where('souscription_id', $s->id)->value('montant'));
        Notification::assertSentTo($citoyen, AnnulationIdentite::class);

        // Remboursement suivi par le CONTRÔLEUR, confirmé par le VALIDATEUR.
        $r = Remboursement::query()->where('souscription_id', $s->id)->sole();
        $this->en($this->controleur)->post(route('admin.remboursements.execution', $r), ['mode' => 'Orange Money', 'reference_operation' => 'OM-778899'])->assertSessionHas('succes');
        $this->en($this->controleur)->post(route('admin.remboursements.confirmer', $r))->assertForbidden();
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['remboursement:'.$r->id], 'decision' => 'confirmer', 'mode' => 'ligne'])
            ->assertSessionHas('succes');
        $this->assertSame(Remboursement::EFFECTUE, $r->fresh()->statut);
        $this->assertSame(StatutSouscription::Remboursee, $s->fresh()->statut);
    }

    public function test_dossier_conforme_et_annulation_rejetee(): void
    {
        $citoyen = $this->citoyen(StatutKyc::EnAttente);
        $this->dossierKyc($citoyen);
        $this->en($this->admin)->post(route('admin.kyc.conforme', $citoyen))->assertSessionHas('succes');
        $this->assertSame(StatutKyc::Valide, $citoyen->fresh()->statut_kyc);
        $this->assertSame(0, $citoyen->kycDocuments()->where('statut', '!=', StatutDocumentKyc::Valide)->count());

        // Douteux puis annulation rejetée par le VALIDATEUR : rien n'est annulé, le dossier revient à l'ADMIN.
        $s = $this->souscription($citoyen, StatutSouscription::Payee, ['payee_le' => now()]);
        $this->en($this->admin)->post(route('admin.kyc.douteux', $citoyen), ['motif' => 'Signature différente'])->assertSessionHas('succes');
        $v = VerificationKyc::query()->where('statut', VerificationKyc::ANNULATION_PROPOSEE)->sole();
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['annulation:'.$v->id], 'decision' => 'rejeter', 'motif' => 'Pièce authentique', 'mode' => 'ligne'])
            ->assertSessionHas('succes');
        $this->assertSame(VerificationKyc::ANNULATION_REJETEE, $v->fresh()->statut);
        $this->assertSame([StatutKyc::Valide, StatutSouscription::Payee], [$citoyen->fresh()->statut_kyc, $s->fresh()->statut]);
    }

    public function test_profils_masques_et_compte_de_demonstration(): void
    {
        $this->en($this->superAdmin)->get(route('admin.utilisateurs', ['inviter' => 1]))->assertOk()
            ->assertSee('Contrôleur des paiements')->assertDontSee('value="conformite"', false)->assertDontSee('value="agent_guichet"', false);
        foreach (['conformite', 'agent_guichet'] as $role) {
            $this->en($this->superAdmin)->post(route('admin.utilisateurs.store'), ['nom' => 'X', 'email' => $role.'@exemple.bf', 'role' => $role])
                ->assertSessionHasErrors('role');
        }
        $this->assertSame([Role::Conformite, Role::AgentGuichet], Role::masques());
        $this->assertNotContains(Role::Conformite, Role::attribuablesBackoffice());

        $this->seed(ComptesDemoSeeder::class);
        $this->assertSame(Role::ControleurPaiements, User::query()->where('email', 'paiements@raaga.test')->sole()->codeRole());
    }
}
