<?php

namespace Tests\Feature;

use App\Contracts\SmsProvider;
use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ConnexionBackoffice;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

/**
 * Chaque utilisateur met à jour ses propres informations personnelles, jamais son rôle.
 */
class ProfilPersonnelTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    // ---------------------------------------------------------------- Back-office

    public function test_un_compte_back_office_modifie_son_nom_et_son_adresse(): void
    {
        $user = $this->connecterBackoffice(Role::Validateur, ['name' => 'Mariam T.', 'email' => 'mariam@raaga.test', 'password' => 'Ancien-mdp-2026']);

        $this->get('/admin/profil')->assertOk()->assertSee('Mon profil')->assertSee('mariam@raaga.test');

        $this->post('/admin/profil', ['name' => 'Mariam TRAORÉ', 'email' => 'm.traore@raaga.test', 'mot_de_passe_actuel' => 'mauvais'])
            ->assertSessionHasErrorsIn('informations', 'mot_de_passe_actuel');

        $this->post('/admin/profil', ['name' => 'Mariam TRAORÉ', 'email' => 'M.Traore@raaga.test', 'mot_de_passe_actuel' => 'Ancien-mdp-2026'])
            ->assertSessionHas('succes');

        $this->assertSame('Mariam TRAORÉ', $user->fresh()->name);
        $this->assertSame('m.traore@raaga.test', $user->fresh()->email);
        $this->assertSame(['champs' => ['name', 'email']], AuditLog::query()->where('action', 'profil.modifie')->first()->nouvelle_valeur);
    }

    public function test_un_compte_back_office_change_son_mot_de_passe_selon_les_regles(): void
    {
        $user = $this->connecterBackoffice(Role::Regulateur, ['password' => 'Ancien-mdp-2026']);
        DB::table('sessions')->insert(['id' => 'autre-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);

        $this->post('/admin/profil/mot-de-passe', ['mot_de_passe_actuel' => 'Ancien-mdp-2026', 'password' => 'court1', 'password_confirmation' => 'court1'])
            ->assertSessionHasErrorsIn('motDePasse', 'password');

        $this->post('/admin/profil/mot-de-passe', ['mot_de_passe_actuel' => 'Ancien-mdp-2026', 'password' => 'Nouveau-mdp-2026', 'password_confirmation' => 'Nouveau-mdp-2026'])
            ->assertSessionHas('succes');

        $this->assertTrue(Hash::check('Nouveau-mdp-2026', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'autre-session']);
    }

    public function test_le_profil_ne_permet_pas_de_changer_son_propre_role(): void
    {
        $user = $this->connecterBackoffice(Role::SuperAdmin, ['password' => 'Ancien-mdp-2026']);

        $this->post('/admin/profil', ['name' => $user->name, 'email' => $user->email, 'role' => 'validateur', 'role_id' => 999, 'mot_de_passe_actuel' => 'Ancien-mdp-2026']);

        $this->assertSame(Role::SuperAdmin, $user->fresh()->codeRole());
    }

    public function test_les_pages_breeze_renvoient_le_back_office_vers_son_profil(): void
    {
        $this->connecterBackoffice(Role::Validateur);

        $this->get('/profile')->assertRedirect(route('admin.profil'));
        $this->patch('/profile', ['name' => 'X', 'email' => 'x@raaga.test'])->assertRedirect(route('admin.profil'));
        $this->put('/password', ['current_password' => 'password', 'password' => 'court', 'password_confirmation' => 'court'])->assertForbidden();
    }

    // ---------------------------------------------------------------- Citoyens

    private function citoyen(array $attributs = []): User
    {
        return User::factory()->role(Role::Citoyen)->create([
            'name' => 'Awa OUÉDRAOGO', 'nom' => 'OUÉDRAOGO', 'prenoms' => 'Awa', 'telephone' => '+22670123456',
            'date_naissance' => '1990-05-12', 'pays_residence' => 'BF', 'region' => 'Centre', ...$attributs,
        ]);
    }

    private function identite(array $modifs = []): array
    {
        return ['nom' => 'Ouédraogo', 'prenoms' => 'Awa', 'date_naissance' => '1990-05-12', 'pays_residence' => 'BF', 'region' => 'Centre', 'email' => '', ...$modifs];
    }

    public function test_un_citoyen_met_a_jour_sa_residence_sans_toucher_a_son_kyc(): void
    {
        $citoyen = $this->citoyen(['statut_kyc' => StatutKyc::Valide]);

        $this->actingAs($citoyen)->get('/espace/profil')->assertOk()->assertSee('Mes informations personnelles')->assertSee('+226 70 12 34 56');

        $this->actingAs($citoyen)->post('/espace/profil', $this->identite(['pays_residence' => 'FR', 'region' => '', 'email' => 'awa@exemple.bf']))
            ->assertSessionHas('succes', 'Vos informations ont été enregistrées.');

        $citoyen->refresh();
        $this->assertSame('FR', $citoyen->pays_residence);
        $this->assertNull($citoyen->region);
        $this->assertSame('awa@exemple.bf', $citoyen->email);
        $this->assertSame(StatutKyc::Valide, $citoyen->statut_kyc);
    }

    public function test_modifier_son_identite_remet_la_verification_kyc_a_faire(): void
    {
        $citoyen = $this->citoyen(['statut_kyc' => StatutKyc::Valide]);

        $this->actingAs($citoyen)->post('/espace/profil', $this->identite(['prenoms' => 'Awa Marie']))
            ->assertSessionHas('succes', 'Vos informations ont été enregistrées. Votre identité a changé : faites-la vérifier à nouveau pour pouvoir souscrire.');

        $citoyen->refresh();
        $this->assertSame('Awa Marie', $citoyen->prenoms);
        $this->assertSame('Awa Marie OUÉDRAOGO', $citoyen->name);
        $this->assertSame(StatutKyc::NonSoumis, $citoyen->statut_kyc);
        $this->assertTrue(AuditLog::query()->where('action', 'profil.modifie')->first()->nouvelle_valeur['kyc_a_refaire']);
    }

    public function test_les_regles_de_l_inscription_s_appliquent_au_profil(): void
    {
        $citoyen = $this->citoyen();

        $this->actingAs($citoyen)->post('/espace/profil', $this->identite(['date_naissance' => now()->subYears(16)->toDateString()]))
            ->assertSessionHasErrors('date_naissance');
        $this->actingAs($citoyen)->post('/espace/profil', $this->identite(['region' => '']))->assertSessionHasErrors('region');
        $this->assertSame('1990-05-12', $citoyen->fresh()->date_naissance->toDateString());
    }

    public function test_changer_de_telephone_exige_un_code_envoye_au_nouveau_numero(): void
    {
        $sms = new FakeSmsProvider;
        $this->app->instance(SmsProvider::class, $sms);
        $citoyen = $this->citoyen();
        User::factory()->create(['telephone' => '+22665000000']);

        $this->actingAs($citoyen)->post('/espace/profil/telephone', ['telephone' => '65 00 00 00'])
            ->assertSessionHasErrors(['telephone' => 'Ce numéro est déjà associé à un autre compte.']);

        $this->actingAs($citoyen)->post('/espace/profil/telephone', ['telephone' => '76 54 32 10'])->assertRedirect();
        $this->assertSame('+22676543210', $sms->envoyes[0]['telephone']);
        $this->assertSame('+22670123456', $citoyen->fresh()->telephone);

        $this->actingAs($citoyen)->post('/espace/profil/telephone/confirmer', ['code' => $sms->dernierCode() === '000000' ? '111111' : '000000'])
            ->assertSessionHasErrors('code');
        $this->assertSame('+22670123456', $citoyen->fresh()->telephone);

        $this->actingAs($citoyen)->post('/espace/profil/telephone/confirmer', ['code' => $sms->dernierCode()])
            ->assertSessionHas('succes');
        $this->assertSame('+22676543210', $citoyen->fresh()->telephone);
        $this->assertTrue(AuditLog::query()->where('action', 'profil.telephone_modifie')->exists());
    }

    public function test_un_citoyen_ne_peut_pas_changer_son_role(): void
    {
        $citoyen = $this->citoyen();

        $this->actingAs($citoyen)->post('/espace/profil', $this->identite(['role' => 'superadmin', 'role_id' => 1]));

        $this->assertSame(Role::Citoyen, $citoyen->fresh()->codeRole());
    }

    public function test_le_profil_citoyen_est_reserve_aux_citoyens(): void
    {
        $this->get('/espace/profil')->assertRedirect(route('connexion'));

        $this->actingAs(User::factory()->role(Role::Investisseur)->create())->get('/espace/profil')->assertForbidden();
        $this->actingAs($this->citoyen())->get('/profile')->assertRedirect(route('espace.profil'));
    }

    // ---------------------------------------------------------------- Autres comptes (profil Breeze)

    public function test_les_autres_comptes_modifient_leur_profil_breeze(): void
    {
        $investisseur = User::factory()->role(Role::Investisseur)->create(['email' => 'issa@raaga.test']);

        $this->actingAs($investisseur)->patch('/profile', ['name' => 'Issa TRAORÉ', 'email' => 'issa@raaga.test'])
            ->assertRedirect('/profile');

        $this->assertSame('Issa TRAORÉ', $investisseur->fresh()->name);
        $this->assertSame(Role::Investisseur, $investisseur->fresh()->codeRole());
    }
}
