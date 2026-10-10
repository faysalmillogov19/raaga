<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Backoffice\DoubleAuthentification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class ConnexionBackofficeTest extends TestCase
{
    use RefreshDatabase;

    private const MOT_DE_PASSE = 'Mot-de-passe-solide-2026';

    private Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->google2fa = new Google2FA;
    }

    private function compte(Role $role = Role::Validateur, bool $totp = true, array $attributs = []): User
    {
        $user = User::factory()->role($role)->create(['email' => 'agent@raaga.test', 'password' => self::MOT_DE_PASSE, ...$attributs]);

        if ($totp) {
            $user->forceFill(['totp_secret' => $this->google2fa->generateSecretKey(32), 'totp_active_le' => now()])->save();
        }

        return $user;
    }

    /** Code TOTP d'un pas donné (0 = maintenant, 1 = pas suivant, toléré par la fenêtre). */
    private function codeTotp(User $user, int $decalage = 0): string
    {
        return $this->google2fa->oathTotp($user->fresh()->totp_secret, $this->google2fa->getTimestamp() + $decalage);
    }

    private function motDePasse(string $motDePasse = self::MOT_DE_PASSE)
    {
        return $this->from('/admin/connexion')->post('/admin/connexion', ['email' => 'agent@raaga.test', 'password' => $motDePasse]);
    }

    public function test_la_page_de_connexion_s_affiche(): void
    {
        $this->get('/admin/connexion')->assertOk()->assertSee('Connexion au back-office')->assertSee('Raaga · Back-office');
    }

    public function test_connexion_complete_avec_totp(): void
    {
        $user = $this->compte();

        $this->motDePasse()->assertRedirect(route('admin.connexion.totp'));
        $this->assertGuest(); // Pas encore connecté après le seul mot de passe.

        $this->post('/admin/connexion/totp', ['code' => $this->codeTotp($user)])->assertRedirect(route('admin.tableau'));
        $this->assertAuthenticatedAs($user);
        $this->get('/admin')->assertOk();

        $this->assertDatabaseHas('connexions_backoffice', ['user_id' => $user->id, 'etape' => 'totp', 'succes' => true]);
        $this->assertTrue(AuditLog::query()->where('action', 'backoffice.connexion')->where('acteur_id', $user->id)->exists());
        $this->assertNotNull($user->fresh()->derniere_connexion_le);
    }

    public function test_un_mauvais_mot_de_passe_donne_un_message_neutre_et_est_journalise(): void
    {
        $user = $this->compte();

        $this->motDePasse('mauvais-mot-de-passe')->assertSessionHasErrors(['email' => 'Identifiants incorrects.']);
        $this->post('/admin/connexion', ['email' => 'inconnu@raaga.test', 'password' => 'x'])
            ->assertSessionHasErrors(['email' => 'Identifiants incorrects.']);

        $this->assertDatabaseHas('connexions_backoffice', ['user_id' => $user->id, 'succes' => false, 'motif' => 'mot_de_passe']);
        $this->assertDatabaseHas('connexions_backoffice', ['user_id' => null, 'email' => 'inconnu@raaga.test', 'motif' => 'compte_inconnu']);
        $this->assertGuest();
    }

    public function test_un_profil_hors_back_office_ou_desactive_est_refuse(): void
    {
        $this->compte(Role::Citoyen);
        $this->motDePasse()->assertSessionHasErrors(['email' => 'Identifiants incorrects.']);
        $this->get('/admin/connexion/totp')->assertRedirect(route('admin.connexion'));

        DB::table('connexions_backoffice')->delete();
        User::query()->delete();

        $this->compte(Role::SuperAdmin, attributs: ['actif' => false]);
        $this->motDePasse()->assertSessionHasErrors(['email' => 'Identifiants incorrects.']);
        $this->assertDatabaseHas('connexions_backoffice', ['motif' => 'desactive']);
        $this->assertGuest();
    }

    public function test_un_mauvais_code_totp_est_refuse(): void
    {
        $user = $this->compte();
        $this->motDePasse();

        $faux = $this->codeTotp($user) === '000000' ? '111111' : '000000';
        $this->post('/admin/connexion/totp', ['code' => $faux])->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertDatabaseHas('connexions_backoffice', ['user_id' => $user->id, 'etape' => 'totp', 'motif' => 'code_totp']);
    }

    public function test_un_code_totp_deja_utilise_ne_peut_pas_resservir(): void
    {
        $user = $this->compte();
        $code = $this->codeTotp($user);

        $this->motDePasse();
        $this->post('/admin/connexion/totp', ['code' => $code])->assertRedirect(route('admin.tableau'));
        $this->post('/admin/deconnexion');

        $this->motDePasse();
        $this->post('/admin/connexion/totp', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_cinq_echecs_verrouillent_le_compte_quinze_minutes(): void
    {
        $this->compte();

        for ($i = 0; $i < 5; $i++) {
            $this->motDePasse('mauvais');
        }

        $this->motDePasse()->assertSessionHasErrors('email');
        $this->assertStringContainsString('Compte verrouillé', session('errors')->first('email'));
        $this->assertGuest();

        $this->travel(16)->minutes();
        $this->motDePasse()->assertRedirect(route('admin.connexion.totp'));
    }

    public function test_les_echecs_totp_comptent_aussi_pour_le_verrouillage(): void
    {
        $user = $this->compte();
        $faux = $this->codeTotp($user) === '000000' ? '111111' : '000000';

        $this->motDePasse();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/connexion/totp', ['code' => $faux]);
        }

        $this->assertTrue($user->fresh()->estVerrouille());
        $this->motDePasse()->assertSessionHasErrors('email');
    }

    public function test_premier_acces_enrolement_totp_et_dix_codes_de_secours(): void
    {
        $user = $this->compte(Role::SuperAdmin, totp: false);

        $this->motDePasse()->assertRedirect(route('admin.connexion.enrolement'));
        $this->get('/admin/connexion/enrolement')->assertOk()->assertSee('<svg', false)->assertSee('Activer la double authentification');

        $secret = session('backoffice.secret_enrolement');
        $code = $this->google2fa->oathTotp($secret, $this->google2fa->getTimestamp());
        $this->post('/admin/connexion/enrolement', ['code' => $code])->assertRedirect(route('admin.connexion.codes-secours'));

        $codes = session('backoffice.codes_secours');
        $this->assertCount(10, $codes);
        $this->get('/admin/connexion/codes-secours')->assertOk()->assertSee($codes[0]);
        $this->assertGuest();

        $this->post('/admin/connexion/codes-secours')->assertRedirect(route('admin.tableau'));
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->fresh()->totpActive());

        // Les codes de secours ne sont jamais réaffichés.
        $this->get('/admin/connexion/codes-secours')->assertRedirect(route('admin.connexion'));
    }

    public function test_un_code_de_secours_ne_sert_qu_une_fois(): void
    {
        $user = $this->compte(totp: false);
        $codes = app(DoubleAuthentification::class)->activer($user, $this->google2fa->generateSecretKey(32));

        $this->motDePasse();
        $this->post('/admin/connexion/totp', ['code' => strtolower($codes[3])])->assertRedirect(route('admin.tableau'));
        $this->post('/admin/deconnexion');

        $this->motDePasse();
        $this->post('/admin/connexion/totp', ['code' => $codes[3]])->assertSessionHasErrors('code');
        $this->assertCount(9, $user->fresh()->codes_secours);
    }

    public function test_la_dispense_de_totp_n_existe_qu_en_local(): void
    {
        config(['raaga.backoffice.totp_facultatif_en_local' => true]);
        $this->compte(totp: false);

        // Environnement de test (≠ local) : l'enrôlement reste obligatoire.
        $this->motDePasse()->assertRedirect(route('admin.connexion.enrolement'));

        // En « local », Laravel réactive la protection CSRF (désactivée uniquement en « testing ») :
        // la requête envoie donc un vrai jeton, comme le navigateur.
        $this->app['env'] = 'local';
        $this->get('/admin/connexion');
        $this->post('/admin/connexion', ['email' => 'agent@raaga.test', 'password' => self::MOT_DE_PASSE, '_token' => session()->token()])
            ->assertRedirect(route('admin.tableau'));
        $this->assertAuthenticated();
    }

    public function test_deconnexion_apres_trente_minutes_d_inactivite(): void
    {
        $user = $this->compte();
        $this->motDePasse();
        $this->post('/admin/connexion/totp', ['code' => $this->codeTotp($user)]);

        $this->travel(29)->minutes();
        $this->get('/admin')->assertOk();

        $this->travel(31)->minutes();
        $this->get('/admin')->assertRedirect(route('admin.connexion'));
        $this->assertGuest();
    }

    public function test_la_session_dure_huit_heures_au_maximum(): void
    {
        $user = $this->compte();
        $this->motDePasse();
        $this->post('/admin/connexion/totp', ['code' => $this->codeTotp($user)]);

        // Activité régulière, mais session ouverte depuis plus de 8 h.
        for ($h = 0; $h < 16; $h++) {
            $this->travel(29)->minutes();
            $this->get('/admin')->assertOk();
        }
        $this->travel(29)->minutes();
        $this->get('/admin')->assertRedirect(route('admin.connexion'));
    }

    public function test_un_compte_desactive_en_cours_de_session_est_deconnecte(): void
    {
        $user = $this->compte();
        $this->motDePasse();
        $this->post('/admin/connexion/totp', ['code' => $this->codeTotp($user)]);

        $user->forceFill(['actif' => false])->save();

        // En production chaque requête relit l'utilisateur ; le test garde l'instance en mémoire.
        $this->app['auth']->forgetGuards();
        $this->get('/admin')->assertRedirect(route('admin.connexion'));
        $this->assertGuest();
    }

    public function test_l_ancienne_connexion_breeze_ne_contourne_pas_la_double_authentification(): void
    {
        $this->compte();

        $this->post('/login', ['email' => 'agent@raaga.test', 'password' => self::MOT_DE_PASSE])
            ->assertStatus(301)
            ->assertRedirect('/connexion');

        $this->assertGuest();
    }

    public function test_l_etape_totp_expire_apres_cinq_minutes(): void
    {
        $user = $this->compte();
        $this->motDePasse();

        $this->travel(6)->minutes();
        $this->post('/admin/connexion/totp', ['code' => $this->codeTotp($user)])->assertRedirect(route('admin.connexion'));
        $this->assertGuest();
    }
}
