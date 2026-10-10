<?php

namespace Tests\Feature\Institutionnel;

use App\Enums\Role;
use App\Enums\StatutAppelFonds;
use App\Enums\StatutConvention;
use App\Enums\StatutInstitution;
use App\Enums\TypeDocumentKyb;
use App\Http\Controllers\Admin\TranchesController;
use App\Models\AppelFonds;
use App\Models\Convention;
use App\Models\DistributionDividende;
use App\Models\Institution;
use App\Models\InstitutionDocument;
use App\Models\LigneRegistreA;
use App\Models\Tranche;
use App\Models\User;
use App\Notifications\AlerteRetardAppelFonds;
use App\Notifications\AppelFondsNotification;
use App\Notifications\InvitationBackoffice;
use App\Services\Dividendes\SimulationDistribution;
use App\Services\Institutionnels\RegistreA;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Investisseurs institutionnels (actions A) : parcours complet, contrôles et cloisonnement.
 */
class ActionsATest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $regulateur;

    private User $validateur;

    private Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
        $this->google2fa = new Google2FA;
        $this->superAdmin = User::factory()->role(Role::SuperAdmin)->create();
        $this->regulateur = User::factory()->role(Role::Regulateur)->create();
        $this->validateur = User::factory()->role(Role::Validateur)->create();
    }

    // ---------------------------------------------------------------- Aides

    /** Session complète (mot de passe + TOTP) dans l'espace du compte. */
    private function en(User $user): static
    {
        $this->app['auth']->forgetGuards();
        $cle = $user->estInstitutionnel() ? 'institutionnel' : 'backoffice';

        return $this->actingAs($user->fresh())->withSession([$cle => ['totp_ok' => true, 'debut' => now()->timestamp, 'activite' => now()->timestamp]]);
    }

    private function pdf(string $nom = 'piece.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($nom, 50, 'application/pdf');
    }

    private function creerInstitution(string $denomination = 'Caisse nationale', string $email = 'dg@caisse.test'): array
    {
        $this->en($this->superAdmin)->post(route('admin.institutions.store'), [
            'denomination' => $denomination, 'sigle' => 'CN', 'rccm' => 'BF-OUA-2000-B-1', 'ifu' => '000111X',
            'signataire_nom' => 'Directeur général', 'signataire_email' => $email,
        ])->assertSessionHas('succes');

        $institution = Institution::query()->where('denomination', $denomination)->firstOrFail();
        $signataire = User::query()->where('email', $email)->firstOrFail();

        return [$institution, $signataire];
    }

    private function activerTotp(User $user): void
    {
        $user->forceFill(['totp_secret' => $this->google2fa->generateSecretKey(32), 'totp_active_le' => now()])->save();
    }

    private function codeTotp(User $user): string
    {
        return $this->google2fa->oathTotp($user->fresh()->totp_secret, $this->google2fa->getTimestamp());
    }

    private function verifierDossier(Institution $institution, User $signataire): void
    {
        foreach (TypeDocumentKyb::cases() as $type) {
            $this->en($signataire)->post(route('institutionnel.organisation.pieces'), ['type' => $type->value, 'fichier' => $this->pdf()])->assertSessionHas('succes');
        }
        $this->assertSame(StatutInstitution::DossierEnCours, $institution->fresh()->statut);

        foreach (InstitutionDocument::query()->where('institution_id', $institution->id)->get() as $piece) {
            $this->en($this->validateur)->post(route('admin.kyb.valider', $piece))->assertSessionHas('succes');
        }
        $this->assertSame(StatutInstitution::Verifiee, $institution->fresh()->statut);
    }

    private function formulaireConvention(Institution $institution, array $surcharges = []): array
    {
        return [
            'institution_id' => $institution->id,
            'reference' => 'CONV-A-2026-001',
            'nb_actions' => 1000,
            'prix_unitaire' => 10_000,
            'montant_engage' => 10_000_000,
            'mode_inscription' => 'PRORATA',
            'droits_particuliers' => 'Un siège au conseil d’administration.',
            'echeancier' => [
                ['libelle' => 'Premier appel', 'echeance' => now()->addMonth()->toDateString(), 'type' => 'pourcentage', 'valeur' => '40'],
                ['libelle' => 'Solde', 'echeance' => now()->addMonths(3)->toDateString(), 'type' => 'montant', 'valeur' => '6000000'],
            ],
            'pdf' => $this->pdf('convention.pdf'),
            ...$surcharges,
        ];
    }

    private function conventionConfirmee(Institution $institution): Convention
    {
        $this->en($this->regulateur)->post(route('admin.conventions.store'), $this->formulaireConvention($institution))->assertSessionHasNoErrors();
        $convention = Convention::query()->where('institution_id', $institution->id)->firstOrFail();
        $this->en($this->regulateur)->post(route('admin.conventions.soumettre', $convention))->assertSessionHas('succes');
        $this->en($this->validateur)->post(route('admin.conventions.confirmer', $convention))->assertSessionHas('succes');

        return $convention->fresh();
    }

    private function payer(User $signataire, AppelFonds $appel): void
    {
        $this->en($signataire)->post(route('institutionnel.appels.declarer', $appel), [
            'date_virement' => now()->toDateString(), 'montant' => $appel->montant, 'reference' => $appel->reference_virement, 'avis' => $this->pdf('avis.pdf'),
        ])->assertSessionHas('succes');
        $this->assertSame(StatutAppelFonds::Declare, $appel->fresh()->statut);

        $declaration = $appel->declarations()->firstOrFail();
        $this->en($this->validateur)->post(route('admin.appels-fonds.rapprocher', $declaration), [
            'montant_recu' => $appel->montant, 'recu_le' => now()->toDateString(), 'reference_releve' => 'RELEVE-'.$appel->numero,
        ])->assertSessionHas('succes');
        $this->assertSame(StatutAppelFonds::Recu, $appel->fresh()->statut);
    }

    // ---------------------------------------------------------------- Tests

    public function test_sur_mobile_le_menu_deroulant_reprend_toutes_les_rubriques_avec_libelles(): void
    {
        [, $signataire] = $this->creerInstitution();

        $page = $this->en($signataire)->get(route('institutionnel.tableau'))->assertOk()
            ->assertSee('class="site-public bo bo-inst font-public"', false)
            ->assertSee('aria-controls="menu-institutionnel-mobile"', false);

        preg_match('/<nav id="menu-institutionnel-mobile".*?<\/nav>/s', $page->getContent(), $menu);
        $this->assertNotEmpty($menu);

        foreach (__('institutionnel.menu') as $libelle) {
            $this->assertStringContainsString(e($libelle), $menu[0]);
        }
        $this->assertStringContainsString('aria-current="page"', $menu[0]);
    }

    public function test_parcours_complet_jusqu_a_l_attestation_d_actionnaire(): void
    {
        // 1. Création par le SUPER_ADMIN et invitation du premier signataire (lien vers l'espace institutionnel).
        [$institution, $signataire] = $this->creerInstitution();
        $this->assertSame(StatutInstitution::Invitee, $institution->statut);
        $this->assertSame(Role::InstitutionSignataire, $signataire->codeRole());

        $lien = null;
        Notification::assertSentTo($signataire, InvitationBackoffice::class, function (InvitationBackoffice $n) use ($signataire, &$lien) {
            $lien = $n->toMail($signataire)->actionUrl;

            return true;
        });
        $this->assertStringContainsString('/institutionnel/invitation/', $lien);

        // Le lien définit le mot de passe puis mène à l'enrôlement TOTP de l'espace institutionnel.
        $this->get($lien)->assertOk();
        $this->post($lien, ['password' => 'Mot-de-passe-2026!', 'password_confirmation' => 'Mot-de-passe-2026!'])
            ->assertRedirect(route('institutionnel.connexion.enrolement'));
        $this->activerTotp($signataire);

        // 2. Dossier KYB déposé puis vérifié pièce par pièce par le VALIDATEUR.
        $this->verifierDossier($institution, $signataire);

        // 3. Convention saisie (RÉGULATEUR), confirmée (VALIDATEUR) : les appels de fonds sont créés.
        $convention = $this->conventionConfirmee($institution);
        $this->assertSame(StatutConvention::Confirmee, $convention->statut);
        $appels = $convention->appels()->get();
        $this->assertSame([4_000_000, 6_000_000], $appels->pluck('montant')->all());

        // 4. Signature électronique par le signataire (code TOTP).
        $this->en($signataire)->get(route('institutionnel.convention'))->assertOk()->assertSee('CONV-A-2026-001')->assertSee('Signer la convention');
        $this->en($signataire)->post(route('institutionnel.convention.signer', $convention), ['code' => $this->codeTotp($signataire)])->assertSessionHas('succes');
        $convention->refresh();
        $this->assertTrue($convention->estSignee());
        $this->assertSame(64, strlen($convention->signature_empreinte));
        $this->assertSame(StatutInstitution::Active, $institution->fresh()->statut);

        // 5. Deux appels de fonds déclarés puis reçus : actions A inscrites au prorata.
        $this->en($signataire)->get(route('institutionnel.appels.avis', $appels[0]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->payer($signataire, $appels[0]);
        $this->assertSame(400, (int) LigneRegistreA::query()->sum('nb_actions'));
        $this->payer($signataire, $appels[1]);

        $lignes = LigneRegistreA::query()->orderBy('id')->get();
        $this->assertSame([400, 600], $lignes->pluck('nb_actions')->all());
        $this->assertSame([1, 401], $lignes->pluck('numero_debut')->all());
        $this->assertSame(1000, $lignes->last()->numero_fin);
        $this->assertSame($lignes->first()->empreinte, $lignes->last()->empreinte_precedente);
        $this->assertTrue(RegistreA::chaineIntegre());

        // 6. Attestations générées, téléchargeables par l'institution.
        foreach ($lignes as $ligne) {
            Storage::disk('local')->assertExists($ligne->attestation_chemin);
            $this->assertSame($ligne->attestation_empreinte, hash('sha256', Storage::disk('local')->get($ligne->attestation_chemin)));
        }
        $this->en($signataire)->get(route('institutionnel.actions'))->assertOk()->assertSee($lignes->first()->numero_certificat)->assertSee("1\u{00A0}000");
        $this->en($signataire)->get(route('institutionnel.actions.attestation', $lignes->first()))->assertOk();
        $this->en($signataire)->get(route('institutionnel.tableau'))->assertOk()->assertSee("10\u{00A0}000\u{00A0}000");

        // Toutes les rubriques de l'espace s'ouvrent.
        foreach (['projet', 'assemblees', 'dividendes', 'organisation', 'contact', 'appels', 'convention'] as $rubrique) {
            $this->en($signataire)->get(route('institutionnel.'.$rubrique))->assertOk();
        }
    }

    public function test_un_echeancier_dont_la_somme_differe_du_montant_engage_est_refuse(): void
    {
        [$institution] = $this->creerInstitution();

        $this->en($this->regulateur)->post(route('admin.conventions.store'), $this->formulaireConvention($institution, [
            'echeancier' => [
                ['libelle' => 'Premier appel', 'echeance' => now()->addMonth()->toDateString(), 'type' => 'pourcentage', 'valeur' => '40'],
                ['libelle' => 'Solde', 'echeance' => now()->addMonths(3)->toDateString(), 'type' => 'montant', 'valeur' => '5000000'],
            ],
        ]))->assertSessionHasErrors(['echeancier' => "La somme des appels de fonds (9\u{00A0}000\u{00A0}000\u{00A0}FCFA) doit être égale au montant engagé (10\u{00A0}000\u{00A0}000\u{00A0}FCFA)."]);

        $this->en($this->regulateur)->post(route('admin.conventions.store'), $this->formulaireConvention($institution, ['montant_engage' => 9_000_000]))
            ->assertSessionHasErrors('montant_engage');

        $this->assertSame(0, Convention::query()->count());
    }

    public function test_une_convention_ne_peut_pas_etre_signee_tant_que_le_dossier_n_est_pas_verifie(): void
    {
        [$institution, $signataire] = $this->creerInstitution();
        $this->activerTotp($signataire);
        $convention = $this->conventionConfirmee($institution);

        $this->en($signataire)->post(route('institutionnel.convention.signer', $convention), ['code' => $this->codeTotp($signataire)])
            ->assertSessionHas('erreur', __('institutionnel.regles.kyb_non_verifie'));
        $this->en($signataire)->post(route('institutionnel.convention.deposer', $convention), ['convention_signee' => $this->pdf()])
            ->assertSessionHas('erreur', __('institutionnel.regles.kyb_non_verifie'));

        $this->assertFalse($convention->fresh()->estSignee());
        $this->assertNull($convention->fresh()->signature_deposee_le);
    }

    public function test_une_institution_n_accede_a_aucune_donnee_d_une_autre_institution_ni_des_citoyens(): void
    {
        [$institutionA, $signataireA] = $this->creerInstitution('Caisse A', 'a@caisse.test');
        [$institutionB, $signataireB] = $this->creerInstitution('Caisse B', 'b@caisse.test');
        $this->activerTotp($signataireA);
        $this->verifierDossier($institutionA, $signataireA);
        $conventionA = $this->conventionConfirmee($institutionA);
        $this->en($signataireA)->post(route('institutionnel.convention.signer', $conventionA), ['code' => $this->codeTotp($signataireA)]);
        $appelA = $conventionA->appels()->first();
        $this->payer($signataireA, $appelA);
        $ligneA = LigneRegistreA::query()->firstOrFail();
        $pieceA = InstitutionDocument::query()->where('institution_id', $institutionA->id)->firstOrFail();
        $citoyen = User::factory()->role(Role::Citoyen)->create();

        foreach ([
            ['get', route('institutionnel.appels.show', $appelA)],
            ['get', route('institutionnel.appels.avis', $appelA)],
            ['post', route('institutionnel.appels.declarer', $appelA)],
            ['get', route('institutionnel.convention.pdf', $conventionA)],
            ['post', route('institutionnel.convention.signer', $conventionA)],
            ['get', route('institutionnel.actions.attestation', $ligneA)],
            ['post', route('institutionnel.organisation.desactiver', $signataireA)],
            ['get', route('admin.kyb.piece', $pieceA)],
            ['get', route('admin.institutions.show', $institutionA)],
            ['get', route('admin.assistance.show', $citoyen)],
            ['get', route('admin.registre-a')],
            ['get', route('espace.profil')],
        ] as [$methode, $url]) {
            $this->en($signataireB)->{$methode}($url, ['motif' => 'Tentative interdite', 'code' => '000000'])->assertForbidden();
        }

        // Ses propres pages ne montrent rien de l'institution A.
        $this->en($signataireB)->get(route('institutionnel.appels'))->assertOk()->assertDontSee($appelA->reference_virement);
        $this->en($signataireB)->get(route('institutionnel.actions'))->assertOk()->assertDontSee($ligneA->numero_certificat);
        $this->en($signataireB)->get(route('institutionnel.convention'))->assertOk()->assertDontSee('CONV-A-2026-001');
    }

    public function test_le_lecteur_ne_peut_ni_signer_ni_declarer_ni_gerer_les_utilisateurs(): void
    {
        [$institution, $signataire] = $this->creerInstitution();
        $this->en($signataire)->post(route('institutionnel.organisation.inviter'), ['nom' => 'Lecteur', 'email' => 'lecteur@caisse.test', 'role' => 'institution_lecteur'])
            ->assertSessionHas('succes');
        $lecteur = User::query()->where('email', 'lecteur@caisse.test')->firstOrFail();
        $this->activerTotp($lecteur);
        $this->verifierDossier($institution, $signataire);
        $convention = $this->conventionConfirmee($institution);
        $appel = $convention->appels()->first();

        $this->en($lecteur)->get(route('institutionnel.convention'))->assertOk()->assertDontSee('Signer la convention');
        $this->en($lecteur)->post(route('institutionnel.convention.signer', $convention), ['code' => $this->codeTotp($lecteur)])->assertForbidden();
        $this->en($lecteur)->post(route('institutionnel.convention.deposer', $convention), ['convention_signee' => $this->pdf()])->assertForbidden();
        $this->en($lecteur)->post(route('institutionnel.appels.declarer', $appel), ['date_virement' => now()->toDateString(), 'montant' => 1, 'reference' => 'X'])->assertForbidden();
        $this->en($lecteur)->post(route('institutionnel.organisation.inviter'), ['nom' => 'X', 'email' => 'x@caisse.test', 'role' => 'institution_signataire'])->assertForbidden();
        $this->en($lecteur)->post(route('institutionnel.organisation.desactiver', $signataire), ['motif' => 'Tentative interdite'])->assertForbidden();
        $this->en($lecteur)->post(route('institutionnel.organisation.pieces'), ['type' => 'RIB', 'fichier' => $this->pdf()])->assertForbidden();

        // Le dernier signataire actif ne peut pas être désactivé.
        $this->en($signataire)->post(route('institutionnel.organisation.desactiver', $lecteur), ['motif' => 'Départ de la personne'])->assertSessionHas('succes');
        $this->assertFalse($lecteur->fresh()->actif);
        $this->assertNull($convention->fresh()->signee_le);
    }

    public function test_le_regulateur_ne_peut_pas_confirmer_et_le_validateur_ne_peut_pas_saisir(): void
    {
        [$institution, $signataire] = $this->creerInstitution();
        $this->en($signataire)->post(route('institutionnel.organisation.pieces'), ['type' => 'RIB', 'fichier' => $this->pdf()]);
        $piece = InstitutionDocument::query()->firstOrFail();
        $this->en($this->regulateur)->post(route('admin.conventions.store'), $this->formulaireConvention($institution))->assertSessionHasNoErrors();
        $convention = Convention::query()->firstOrFail();
        $this->en($this->regulateur)->post(route('admin.conventions.soumettre', $convention));

        $this->en($this->regulateur)->post(route('admin.conventions.confirmer', $convention))->assertForbidden();
        $this->en($this->regulateur)->post(route('admin.conventions.rejeter', $convention), ['motif' => 'Essai de rejet'])->assertForbidden();
        $this->en($this->regulateur)->post(route('admin.kyb.valider', $piece))->assertForbidden();
        $this->en($this->regulateur)->get(route('admin.kyb.show', $institution))->assertOk()->assertDontSee('>Valider<', false);

        $this->en($this->validateur)->get(route('admin.conventions.create'))->assertForbidden();
        $this->en($this->validateur)->post(route('admin.conventions.store'), $this->formulaireConvention($institution, ['reference' => 'AUTRE']))->assertForbidden();
        $this->en($this->validateur)->put(route('admin.conventions.update', $convention), $this->formulaireConvention($institution))->assertForbidden();

        foreach ([$this->superAdmin, User::factory()->role(Role::Admin)->create()] as $support) {
            $this->en($support)->post(route('admin.conventions.store'), $this->formulaireConvention($institution, ['reference' => 'AUTRE']))->assertForbidden();
            $this->en($support)->post(route('admin.conventions.confirmer', $convention))->assertForbidden();
        }

        $this->assertSame(StatutConvention::Soumise, $convention->fresh()->statut);
        $this->assertSame(1, Convention::query()->count());
    }

    public function test_les_registres_a_et_b_restent_separes_et_la_repartition_est_exacte(): void
    {
        [$institution, $signataire] = $this->creerInstitution();
        $this->activerTotp($signataire);
        $this->verifierDossier($institution, $signataire);
        $convention = $this->conventionConfirmee($institution);
        $this->en($signataire)->post(route('institutionnel.convention.signer', $convention), ['code' => $this->codeTotp($signataire)]);
        foreach ($convention->appels as $appel) {
            $this->payer($signataire, $appel);
        }

        // Registre B (citoyens) : 3 000 actions, dans sa propre table.
        $citoyen = User::factory()->role(Role::Citoyen)->create();
        $tranche = Tranche::create([...TranchesController::valeursParDefaut(), 'nom' => 'Tranche 1', 'statut' => 'BROUILLON', 'version' => 0, 'cree_par' => $this->regulateur->id]);
        DB::table('lignes_registre')->insert([
            'numero_certificat' => 'B-2026-000001', 'user_id' => $citoyen->id, 'tranche_id' => $tranche->id,
            'nb_actions' => 3000, 'numero_action_debut' => 1, 'numero_action_fin' => 3000, 'date_inscription' => now(),
        ]);

        $this->assertSame(1000, (int) LigneRegistreA::query()->sum('nb_actions'));
        $this->assertSame(3000, (int) DB::table('lignes_registre')->sum('nb_actions'));
        $this->assertSame(0, DB::table('lignes_registre')->where('numero_certificat', 'like', 'A-%')->count());

        $repartition = RegistreA::repartitionCapital();
        $this->assertSame(['a' => 1000, 'b' => 3000, 'total' => 4000, 'pct_a' => 25.0, 'pct_b' => 75.0], $repartition);

        $this->en($this->regulateur)->get(route('admin.registre-a'))->assertOk()->assertSee('25,00')->assertSee('75,00')->assertSee('Chaîne d’intégrité vérifiée', false);
        $this->en($this->regulateur)->get(route('admin.registre-a.export'))->assertOk();
        $this->en($this->validateur)->get(route('admin.registre-a.export'))->assertForbidden();
        $this->en($signataire)->get(route('institutionnel.tableau'))->assertOk()->assertSee('25,00');
    }

    /** Institution détenant 1 000 actions A (convention signée, deux appels reçus). */
    private function institutionActionnaire(): array
    {
        [$institution, $signataire] = $this->creerInstitution();
        $this->activerTotp($signataire);
        $this->verifierDossier($institution, $signataire);
        $convention = $this->conventionConfirmee($institution);
        $this->en($signataire)->post(route('institutionnel.convention.signer', $convention), ['code' => $this->codeTotp($signataire)]);
        foreach ($convention->appels as $appel) {
            $this->payer($signataire, $appel);
        }

        return [$institution, $signataire];
    }

    /** Paramétrage de dividendes A confirmé : 1 000 000 FCFA, IRVM 12,5 %, arrêté demain. */
    private function dividendesA(): DistributionDividende
    {
        $this->en($this->regulateur)->post(route('admin.dividendes.store'), [
            'classe_actions' => 'A', 'exercice' => 2025, 'ag_date' => now()->toDateString(), 'ag_pv_numero' => 'PV-AG-A',
            'pv' => $this->pdf('pv.pdf'), 'resultat_distribuable' => 5_000_000, 'mode_calcul' => 'MONTANT_GLOBAL',
            'montant_global' => 1_000_000, 'date_arrete' => now()->addDay()->toDateString(), 'date_mise_en_paiement' => now()->addDays(10)->toDateString(),
            'taux_irvm' => '12.5', 'frais_supportes_par' => 'RAAGA', 'seuil_minimal' => 0, 'canaux_autorises' => ['VIREMENT'],
            'canal_defaut' => 'VIREMENT', 'tentatives' => 1, 'delai_tentatives_heures' => 24,
            'conservation_non_reclames_mois' => 60, 'traitement_non_reclames' => 'CONSIGNATION',
        ])->assertSessionHasNoErrors();
        $distribution = DistributionDividende::query()->where('classe_actions', 'A')->firstOrFail();
        $this->en($this->regulateur)->post(route('admin.dividendes.soumettre', $distribution), ['montant_conforme_pv' => '1'])->assertSessionHas('succes');
        $this->en($this->validateur)->post(route('admin.dividendes.confirmer', $distribution))->assertSessionHas('succes');

        return $distribution->fresh();
    }

    public function test_les_dividendes_des_actions_a_sont_calcules_sur_le_registre_a(): void
    {
        [$institution] = $this->institutionActionnaire();
        $distribution = $this->dividendesA();

        $resultat = app(SimulationDistribution::class)->simuler($distribution);
        $this->assertSame(1, $resultat->nbBeneficiaires);
        $this->assertSame(1000, $resultat->nbActions);
        $ligne = app(SimulationDistribution::class)->ligneInstitution($distribution, $institution->id);
        $this->assertSame([1_000_000, 125_000, 875_000], [$ligne->brut, $ligne->retenue, $ligne->net]);
        $this->assertSame('VIREMENT', $ligne->canal);
        $this->assertSame(1_000_000, $resultat->totalNetAPayer + $resultat->totalRetenue + $resultat->totalReporte + $resultat->totalConsigne + $resultat->reliquat);
    }

    public function test_l_institution_consulte_son_avis_de_distribution_et_jamais_celui_d_une_autre(): void
    {
        [, $signataire] = $this->institutionActionnaire();
        [, $autre] = $this->creerInstitution('Caisse B', 'b@caisse.test');
        $distribution = $this->dividendesA();

        $this->en($signataire)->get(route('institutionnel.dividendes'))->assertOk()
            ->assertSee("875\u{00A0}000\u{00A0}FCFA")->assertSee("125\u{00A0}000\u{00A0}FCFA");
        $this->en($signataire)->get(route('institutionnel.dividendes.avis', $distribution))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // L'institution B ne détient aucune action A : aucun avis, et l'avis de A lui est refusé.
        $this->en($autre)->get(route('institutionnel.dividendes'))->assertOk()->assertDontSee("875\u{00A0}000");
        $this->en($autre)->get(route('institutionnel.dividendes.avis', $distribution))->assertForbidden();
    }

    public function test_un_avenant_peut_remplacer_le_pdf_et_la_signature_doit_etre_refaite(): void
    {
        [$institution, $signataire] = $this->creerInstitution();
        $this->activerTotp($signataire);
        $this->verifierDossier($institution, $signataire);
        $convention = $this->conventionConfirmee($institution);
        $this->en($signataire)->post(route('institutionnel.convention.signer', $convention), ['code' => $this->codeTotp($signataire)]);
        $ancienne = $convention->fresh()->pdf_empreinte;

        $nouveauPdf = UploadedFile::fake()->createWithContent('convention-v2.pdf', '%PDF-1.4 version 2');
        $formulaire = collect($this->formulaireConvention($institution, ['droits_particuliers' => 'Deux sièges au conseil.', 'pdf' => $nouveauPdf]))
            ->except('institution_id')->all();
        $this->en($this->regulateur)->post(route('admin.conventions.avenant.store', $convention), [...$formulaire, 'motif' => 'Avenant n° 1 signé par les parties'])
            ->assertSessionHas('succes');

        // Tant que l'avenant n'est pas confirmé, rien ne change.
        $this->assertSame($ancienne, $convention->fresh()->pdf_empreinte);
        $this->assertTrue($convention->fresh()->estSignee());

        $avenant = $convention->avenants()->firstOrFail();
        $this->en($this->validateur)->post(route('admin.conventions.avenant.confirmer', [$convention, $avenant]))->assertSessionHas('succes');

        $convention->refresh();
        $this->assertSame(hash('sha256', '%PDF-1.4 version 2'), $convention->pdf_empreinte);
        $this->assertSame('Deux sièges au conseil.', $convention->droits_particuliers);
        $this->assertFalse($convention->estSignee());
        $this->assertSame('%PDF-1.4 version 2', $this->en($signataire)->get(route('institutionnel.convention.pdf', $convention))->streamedContent());
    }

    public function test_le_rappel_et_la_relance_des_appels_de_fonds(): void
    {
        [$institution, $signataire] = $this->creerInstitution();
        $this->activerTotp($signataire);
        $this->verifierDossier($institution, $signataire);
        $convention = $this->conventionConfirmee($institution);
        $this->en($signataire)->post(route('institutionnel.convention.signer', $convention), ['code' => $this->codeTotp($signataire)]);
        $appel = $convention->appels()->first();

        $this->travelTo($appel->echeance->copy()->subDays(15)->setTime(7, 0));
        $this->artisan('raaga:appels-de-fonds')->assertSuccessful();
        Notification::assertSentTo($signataire, AppelFondsNotification::class);

        $this->travelTo($appel->echeance->copy()->addDays(2)->setTime(7, 0));
        $this->artisan('raaga:appels-de-fonds')->assertSuccessful();
        $this->assertSame(StatutAppelFonds::EnRetard, $appel->fresh()->statut);
        Notification::assertSentTo($this->validateur, AlerteRetardAppelFonds::class);
        $this->assertContains('alerte', $appel->fresh()->rappels);
    }

    public function test_les_comptes_institutionnels_et_back_office_ne_se_connectent_que_dans_leur_espace(): void
    {
        [, $signataire] = $this->creerInstitution();
        $signataire->forceFill(['password' => 'Mot-de-passe-2026!'])->save();
        $this->regulateur->forceFill(['password' => 'Mot-de-passe-2026!'])->save();

        $this->post(route('admin.connexion'), ['email' => $signataire->email, 'password' => 'Mot-de-passe-2026!'])->assertSessionHasErrors('email');
        $this->post(route('institutionnel.connexion'), ['email' => $this->regulateur->email, 'password' => 'Mot-de-passe-2026!'])->assertSessionHasErrors('email');
        $this->post(route('institutionnel.connexion'), ['email' => $signataire->email, 'password' => 'Mot-de-passe-2026!'])
            ->assertRedirect(route('institutionnel.connexion.enrolement'));

        $this->en($signataire)->get('/admin')->assertForbidden();
        $this->en($this->regulateur)->get('/institutionnel')->assertForbidden();
    }
}
