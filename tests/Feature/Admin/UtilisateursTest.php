<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationBackoffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

class UtilisateursTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function lienEnvoye(User $user): string
    {
        $lien = null;
        Notification::assertSentTo($user, InvitationBackoffice::class, function (InvitationBackoffice $n) use ($user, &$lien) {
            $lien = $n->toMail($user)->actionUrl;

            return true;
        });

        return $lien;
    }

    private function sessionOuverte(User $user): void
    {
        DB::table('sessions')->insert(['id' => 'session-'.$user->id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    }

    // ---------------------------------------------------------------- Ce que les autres profils ne peuvent pas faire

    public static function profilsSansDroit(): array
    {
        return ['ADMIN' => [Role::Admin], 'VALIDATEUR' => [Role::Validateur], 'REGULATEUR' => [Role::Regulateur]];
    }

    #[DataProvider('profilsSansDroit')]
    public function test_seul_le_super_admin_accede_aux_utilisateurs(Role $role): void
    {
        $cible = User::factory()->role(Role::Validateur)->create();
        $this->connecterBackoffice($role);

        $this->get('/admin/utilisateurs')->assertForbidden();
        $this->get('/admin/utilisateurs/inviter')->assertForbidden();
        $this->get("/admin/utilisateurs/{$cible->id}")->assertForbidden();

        $this->post('/admin/utilisateurs', ['nom' => 'X', 'email' => 'x@raaga.test', 'role' => 'validateur'])->assertForbidden();
        $this->post("/admin/utilisateurs/{$cible->id}/role", ['role' => 'superadmin'])->assertForbidden();
        $this->post("/admin/utilisateurs/{$cible->id}/desactiver", ['motif' => 'Tentative interdite'])->assertForbidden();
        $this->post("/admin/utilisateurs/{$cible->id}/reactiver", ['motif' => 'Tentative interdite'])->assertForbidden();
        $this->post("/admin/utilisateurs/{$cible->id}/totp")->assertForbidden();
        $this->post("/admin/utilisateurs/{$cible->id}/deverrouiller")->assertForbidden();
        $this->post("/admin/utilisateurs/{$cible->id}/lien")->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'x@raaga.test']);
        $this->assertSame(Role::Validateur, $cible->fresh()->codeRole());
        $this->assertTrue($cible->fresh()->actif);
        Notification::assertNothingSent();
    }

    // ---------------------------------------------------------------- Rubriques, recherche, filtres

    public function test_l_onglet_ne_liste_que_les_comptes_back_office_et_le_grand_public_passe_a_l_assistance(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin, ['name' => 'Aminata SAWADOGO']);
        User::factory()->role(Role::Regulateur)->create(['name' => 'Clarisse SOMÉ']);
        User::factory()->role(Role::Admin)->create(['name' => 'Rasmata OUÉDRAOGO']);
        User::factory()->role(Role::Citoyen)->create(['name' => 'Awa OUÉDRAOGO', 'telephone' => '+22670123456']);
        User::factory()->role(Role::AgentGuichet)->create(['name' => 'Moussa KONÉ']);
        User::factory()->role(Role::Investisseur)->create(['name' => 'Issa TRAORÉ']);

        // L'Agent de guichet est un profil du back-office (17/10/2026).
        $this->get('/admin/utilisateurs')->assertOk()
            ->assertSee('Aminata SAWADOGO')->assertSee('Clarisse SOMÉ')->assertSee('Rasmata OUÉDRAOGO')->assertSee('Moussa KONÉ')
            ->assertSee('bo-badge bo-badge-admin', false)
            ->assertDontSee('Awa OUÉDRAOGO')->assertDontSee('Grand public');

        // Grand public = citoyens souscripteurs, et rien d'autre.
        $this->get('/admin/assistance')->assertOk()
            ->assertSee('Awa OUÉDRAOGO')->assertDontSee('Moussa KONÉ')->assertSee('+226 70 12 34 56')
            ->assertDontSee('Issa TRAORÉ')->assertDontSee('Clarisse SOMÉ')
            ->assertDontSee('investisseurs')->assertDontSee('porteurs de projet');
    }

    public function test_recherche_par_nom_ou_telephone_et_filtres_type_et_kyc(): void
    {
        $this->connecterBackoffice(Role::Admin);
        User::factory()->role(Role::Citoyen)->create(['name' => 'Awa OUÉDRAOGO', 'telephone' => '+22670123456', 'statut_kyc' => StatutKyc::Valide]);
        User::factory()->role(Role::Citoyen)->create(['name' => 'Ali ZONGO', 'telephone' => '+22665000000', 'statut_kyc' => StatutKyc::EnAttente]);

        $this->get('/admin/assistance?q=ouédraogo')->assertSee('Awa OUÉDRAOGO')->assertDontSee('Ali ZONGO');
        $this->get('/admin/assistance?q=70 12 34')->assertSee('Awa OUÉDRAOGO')->assertDontSee('Ali ZONGO');
        $this->get('/admin/assistance?kyc=EN_ATTENTE')->assertSee('Ali ZONGO')->assertDontSee('Awa OUÉDRAOGO');
    }

    public function test_la_fiche_grand_public_est_en_lecture_seule_et_quitte_l_onglet_utilisateurs(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $citoyen = User::factory()->role(Role::Citoyen)->create(['nom' => 'OUÉDRAOGO', 'prenoms' => 'Awa', 'telephone' => '+22670123456']);
        $agentBackoffice = User::factory()->role(Role::Validateur)->create();

        $this->get("/admin/assistance/{$citoyen->id}")->assertOk()
            ->assertSee('OUÉDRAOGO')
            ->assertSee('Les pièces d’identité ne sont jamais affichées', false)
            ->assertSee('Désactiver le compte')
            ->assertDontSee('Changer le rôle');

        // L'onglet Utilisateurs ne gère plus que le back-office ; l'assistance ne voit jamais un compte back-office.
        $this->get("/admin/utilisateurs/{$citoyen->id}")->assertNotFound();
        $this->post("/admin/utilisateurs/{$citoyen->id}/desactiver", ['motif' => 'Mauvaise porte'])->assertNotFound();
        $this->get("/admin/assistance/{$agentBackoffice->id}")->assertNotFound();
        $this->post("/admin/assistance/{$agentBackoffice->id}/desactiver", ['motif' => 'Mauvaise porte'])->assertNotFound();
        $this->assertTrue($citoyen->fresh()->actif);
        $this->assertTrue($agentBackoffice->fresh()->actif);
    }

    // ---------------------------------------------------------------- Invitation

    public function test_le_super_admin_invite_un_compte_back_office(): void
    {
        $sa = $this->connecterBackoffice(Role::SuperAdmin);

        $this->post('/admin/utilisateurs', ['nom' => 'Mariam TRAORÉ', 'email' => 'Mariam@Raaga.test', 'role' => 'validateur'])
            ->assertRedirect();

        $user = User::query()->where('email', 'mariam@raaga.test')->firstOrFail();
        $this->assertSame(Role::Validateur, $user->codeRole());
        $this->assertNull($user->password);
        $this->assertSame(1, Invitation::query()->where('user_id', $user->id)->count());
        $this->assertStringContainsString('/admin/invitation/', $this->lienEnvoye($user));
        $this->assertTrue(AuditLog::query()->where('action', 'utilisateur.invite')->where('acteur_id', $sa->id)->where('entite_id', (string) $user->id)->exists());
    }

    public function test_on_ne_peut_inviter_que_des_profils_back_office_avec_une_adresse_nouvelle(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        User::factory()->create(['email' => 'pris@raaga.test']);

        $this->post('/admin/utilisateurs', ['nom' => 'X', 'email' => 'x@raaga.test', 'role' => 'citoyen'])->assertSessionHasErrors('role');
        $this->post('/admin/utilisateurs', ['nom' => 'X', 'email' => 'pris@raaga.test', 'role' => 'validateur'])
            ->assertSessionHasErrors(['email' => 'Un compte existe déjà avec cette adresse électronique.']);
    }

    public function test_le_lien_d_invitation_definit_le_mot_de_passe_puis_mene_a_la_double_authentification(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->post('/admin/utilisateurs', ['nom' => 'Mariam TRAORÉ', 'email' => 'mariam@raaga.test', 'role' => 'validateur']);
        $user = User::query()->where('email', 'mariam@raaga.test')->firstOrFail();
        $lien = $this->lienEnvoye($user);
        $this->post('/admin/deconnexion');

        $this->get($lien)->assertOk()->assertSee('Définir votre mot de passe')->assertSee('mariam@raaga.test');

        $this->post($lien, ['password' => 'court1', 'password_confirmation' => 'court1'])->assertSessionHasErrors('password');

        $this->post($lien, ['password' => 'Mot-de-passe-2026', 'password_confirmation' => 'Mot-de-passe-2026'])
            ->assertRedirect(route('admin.connexion.enrolement'));

        $this->assertNotNull($user->fresh()->password);
        $this->get('/admin/connexion/enrolement')->assertOk()->assertSee('Activer la double authentification');

        // Le lien ne sert qu'une fois.
        $this->get($lien)->assertStatus(410)->assertSee('Lien invalide ou expiré');
    }

    public function test_le_lien_expire_apres_48_heures_et_un_nouveau_lien_annule_l_ancien(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->post('/admin/utilisateurs', ['nom' => 'Mariam TRAORÉ', 'email' => 'mariam@raaga.test', 'role' => 'validateur']);
        $user = User::query()->where('email', 'mariam@raaga.test')->firstOrFail();
        $premier = $this->lienEnvoye($user);

        $this->post("/admin/utilisateurs/{$user->id}/lien")->assertSessionHas('succes');
        $this->get($premier)->assertStatus(410);

        $this->travel(49)->hours();
        $dernier = Invitation::query()->where('user_id', $user->id)->latest('id')->first();
        $this->assertFalse($dernier->estValide());
    }

    // ---------------------------------------------------------------- Rôle

    public function test_changer_le_role_deconnecte_la_personne_et_est_trace(): void
    {
        $sa = $this->connecterBackoffice(Role::SuperAdmin);
        $cible = User::factory()->role(Role::Validateur)->create();
        $this->sessionOuverte($cible);

        $this->post("/admin/utilisateurs/{$cible->id}/role", ['role' => 'regulateur'])->assertSessionHas('succes');

        $this->assertSame(Role::Regulateur, $cible->fresh()->codeRole());
        $this->assertDatabaseMissing('sessions', ['user_id' => $cible->id]);
        $audit = AuditLog::query()->where('action', 'utilisateur.role_modifie')->firstOrFail();
        $this->assertSame(['role' => 'validateur'], $audit->ancienne_valeur);
        $this->assertSame(['role' => 'regulateur'], $audit->nouvelle_valeur);
        $this->assertSame($sa->id, $audit->acteur_id);
    }

    public function test_le_super_admin_ne_peut_pas_changer_son_propre_role(): void
    {
        $sa = $this->connecterBackoffice(Role::SuperAdmin);
        User::factory()->role(Role::SuperAdmin)->count(2)->create();

        $this->post("/admin/utilisateurs/{$sa->id}/role", ['role' => 'validateur'])
            ->assertSessionHas('erreur', 'Vous ne pouvez pas effectuer cette action sur votre propre compte.');

        $this->assertSame(Role::SuperAdmin, $sa->fresh()->codeRole());
    }

    public function test_il_reste_toujours_au_moins_deux_super_admins_actifs(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $autre = User::factory()->role(Role::SuperAdmin)->create();

        $this->post("/admin/utilisateurs/{$autre->id}/role", ['role' => 'validateur'])
            ->assertSessionHas('erreur', 'Action refusée : il doit toujours rester au moins 2 super administrateurs actifs.');
        $this->post("/admin/utilisateurs/{$autre->id}/desactiver", ['motif' => 'Départ de l’agent'])->assertSessionHas('erreur');
        $this->assertSame(Role::SuperAdmin, $autre->fresh()->codeRole());
        $this->assertTrue($autre->fresh()->actif);

        // Avec un troisième SUPER_ADMIN actif, le retrait devient possible.
        User::factory()->role(Role::SuperAdmin)->create();
        $this->post("/admin/utilisateurs/{$autre->id}/role", ['role' => 'validateur'])->assertSessionHas('succes');
    }

    // ---------------------------------------------------------------- Désactivation, réactivation

    public function test_desactiver_exige_un_motif_deconnecte_et_est_trace(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $cible = User::factory()->role(Role::Validateur)->create();
        $this->sessionOuverte($cible);

        $this->post("/admin/utilisateurs/{$cible->id}/desactiver", ['motif' => ''])->assertSessionHasErrors('motif');
        $this->assertTrue($cible->fresh()->actif);

        $this->post("/admin/utilisateurs/{$cible->id}/desactiver", ['motif' => 'Fin de mission'])->assertSessionHas('succes');
        $this->assertFalse($cible->fresh()->actif);
        $this->assertDatabaseMissing('sessions', ['user_id' => $cible->id]);
        $this->assertSame('Fin de mission', AuditLog::query()->where('action', 'utilisateur.desactive')->first()->nouvelle_valeur['motif']);

        $this->post("/admin/utilisateurs/{$cible->id}/reactiver", ['motif' => 'Retour de mission'])->assertSessionHas('succes');
        $this->assertTrue($cible->fresh()->actif);
    }

    public function test_le_super_admin_ne_peut_pas_se_desactiver(): void
    {
        $sa = $this->connecterBackoffice(Role::SuperAdmin);
        User::factory()->role(Role::SuperAdmin)->count(2)->create();

        $this->post("/admin/utilisateurs/{$sa->id}/desactiver", ['motif' => 'Essai sur moi-même'])->assertSessionHas('erreur');
        $this->assertTrue($sa->fresh()->actif);
    }

    public function test_un_citoyen_desactive_ne_peut_plus_se_connecter_et_est_deconnecte(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $citoyen = User::factory()->role(Role::Citoyen)->create(['telephone' => '+22670123456']);

        $this->post("/admin/assistance/{$citoyen->id}/desactiver", ['motif' => 'Fraude signalée'])->assertSessionHas('succes');
        $this->post('/admin/deconnexion');

        $this->from('/connexion')->post('/connexion/telephone', ['telephone' => '70 12 34 56'])
            ->assertSessionHasErrors(['telephone' => 'Ce compte a été désactivé. Contactez l’assistance au numéro vert.']);

        // Déjà connecté au moment de la désactivation : déconnecté à la requête suivante.
        $this->actingAs($citoyen->fresh())->get('/espace')->assertRedirect(route('connexion'));
        $this->assertGuest();
    }

    // ---------------------------------------------------------------- Double authentification, verrouillage

    public function test_reinitialiser_la_double_authentification(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $cible = User::factory()->role(Role::Validateur)->create();
        $cible->forceFill(['totp_secret' => 'JBSWY3DPEHPK3PXP', 'totp_active_le' => now(), 'codes_secours' => ['x']])->save();
        $this->sessionOuverte($cible);

        $this->post("/admin/utilisateurs/{$cible->id}/totp")->assertSessionHas('succes');

        $this->assertFalse($cible->fresh()->totpActive());
        $this->assertNull($cible->fresh()->codes_secours);
        $this->assertDatabaseMissing('sessions', ['user_id' => $cible->id]);
    }

    public function test_deverrouiller_un_compte(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $cible = User::factory()->role(Role::Validateur)->create();
        $cible->forceFill(['verrouille_jusqua' => now()->addMinutes(10), 'echecs_connexion' => 3])->save();

        $this->post("/admin/utilisateurs/{$cible->id}/deverrouiller")->assertSessionHas('succes');
        $this->assertFalse($cible->fresh()->estVerrouille());
    }

    // ---------------------------------------------------------------- Commande serveur

    public function test_la_commande_cree_le_premier_super_admin_et_refuse_ensuite_sans_force(): void
    {
        $this->artisan('raaga:creer-superadmin', ['nom' => 'Aminata SAWADOGO', 'email' => 'aminata@raaga.bf'])
            ->expectsOutputToContain('/admin/invitation/')
            ->assertSuccessful();

        $user = User::query()->where('email', 'aminata@raaga.bf')->firstOrFail();
        $this->assertSame(Role::SuperAdmin, $user->codeRole());
        $this->assertSame(1, Invitation::query()->where('user_id', $user->id)->whereNull('annulee_le')->count());

        $this->artisan('raaga:creer-superadmin', ['nom' => 'Issouf KABORÉ', 'email' => 'issouf@raaga.bf'])->assertFailed();
        $this->artisan('raaga:creer-superadmin', ['nom' => 'Issouf KABORÉ', 'email' => 'issouf@raaga.bf', '--force' => true])->assertSuccessful();
    }
}
