<?php

namespace Tests\Feature;

use App\Console\Commands\RecetteComptes;
use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Enums\TypeTitulaire;
use App\Models\User;
use Database\Seeders\ComptesDemoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Environnement de recette (réunion du 05/11/2026) : bandeau « ENVIRONNEMENT DE TEST » seulement avec APP_ENV=recette ;
 * comptes de recette (mots de passe générés, affichés une fois) ; jamais de comptes de démonstration en recette ; remise
 * à zéro refusée hors recette et sans le nom exact de la base.
 */
class RecetteTest extends TestCase
{
    use RefreshDatabase;

    private function enRecette(): void
    {
        $this->app['env'] = 'recette';
    }

    public function test_bandeau_seulement_en_recette(): void
    {
        $this->get('/')->assertOk()->assertDontSee('data-bandeau-recette', false)->assertDontSee('ENVIRONNEMENT DE TEST');
        $this->get(route('admin.connexion'))->assertOk()->assertDontSee('data-bandeau-recette', false);

        $this->enRecette();
        foreach (['/', route('connexion'), route('admin.connexion'), '/page-inexistante-recette'] as $url) {
            $this->get($url)->assertSee('data-bandeau-recette', false)->assertSee('ENVIRONNEMENT DE TEST');
        }
    }

    public function test_comptes_de_recette_mots_de_passe_generes_affiches_une_fois(): void
    {
        Storage::fake('local');
        $this->artisan('raaga:recette-comptes')->assertFailed();
        $this->assertSame(0, User::query()->where('email', 'like', '%'.RecetteComptes::DOMAINE)->count());

        $this->enRecette();
        $this->artisan('raaga:recette-comptes')->assertSuccessful()->expectsOutputToContain('Mots de passe affichés une seule fois');
        foreach (array_keys(RecetteComptes::BACKOFFICE) as $code) {
            $user = User::query()->where('email', str_replace('_', '-', $code).RecetteComptes::DOMAINE)->firstOrFail();
            $this->assertSame(Role::from($code), $user->codeRole());
            // Jamais le mot de passe en clair ni égal à l'adresse.
            $this->assertNotSame($user->email, $user->getAttributes()['password']);
            $this->assertFalse(Hash::check($user->email, $user->password));
        }
        $physique = User::query()->where('telephone', '+22662000001')->firstOrFail();
        $sansIfu = User::query()->where('telephone', '+22662000002')->firstOrFail();
        $morale = User::query()->where('telephone', '+22662000003')->firstOrFail();
        $this->assertSame([StatutKyc::Valide, '62000001R'], [$physique->statut_kyc, $physique->ifu]);
        $this->assertNull($sansIfu->ifu);
        $this->assertSame([TypeTitulaire::Morale, '62000003R'], [$morale->type_titulaire, $morale->ifu]);

        // Relancée : mots de passe gardés ; --nouveaux-mots-de-passe : changés.
        $avant = User::query()->where('email', 'admin'.RecetteComptes::DOMAINE)->value('password');
        $this->artisan('raaga:recette-comptes')->assertSuccessful()->expectsOutputToContain('inchangé');
        $this->assertSame($avant, User::query()->where('email', 'admin'.RecetteComptes::DOMAINE)->value('password'));
        $this->artisan('raaga:recette-comptes', ['--nouveaux-mots-de-passe' => true])->assertSuccessful();
        $this->assertNotSame($avant, User::query()->where('email', 'admin'.RecetteComptes::DOMAINE)->value('password'));
        $this->assertSame(3, User::query()->where('telephone', 'like', '+2266200000%')->count());
    }

    public function test_compte_nominatif_d_un_testeur(): void
    {
        $this->enRecette();
        $this->artisan('raaga:recette-comptes', ['--testeur' => 'Zongo Aminata', '--profil' => 'AGENT_GUICHET'])->assertSuccessful()
            ->expectsOutputToContain('zongo.aminata.agent-guichet'.RecetteComptes::DOMAINE);
        $user = User::query()->where('email', 'zongo.aminata.agent-guichet'.RecetteComptes::DOMAINE)->firstOrFail();
        $this->assertSame(['Zongo Aminata', Role::AgentGuichet], [$user->name, $user->codeRole()]);
        // Seul ce compte est créé ; profil inconnu ou nom manquant : refus.
        $this->assertSame(1, User::query()->where('email', 'like', '%'.RecetteComptes::DOMAINE)->count());
        $this->artisan('raaga:recette-comptes', ['--testeur' => 'Zongo Aminata', '--profil' => 'CITOYEN'])->assertFailed();
        $this->artisan('raaga:recette-comptes', ['--profil' => 'ADMIN'])->assertFailed();
    }

    public function test_page_des_sms_en_recette_protegee_par_le_mot_de_passe_commun(): void
    {
        // Hors recette (journal des SMS actif) : ouverte, sans mot de passe.
        config(['raaga.sms.pilote' => 'log', 'raaga.sms.journal_recette' => true]);
        $this->get(route('recette.sms'))->assertOk()->assertDontSee('data-acces-sms-recette', false);

        // Recette sans mot de passe défini : fermée.
        $this->enRecette();
        config(['raaga.sms.mot_de_passe_recette' => null]);
        $this->get(route('recette.sms'))->assertNotFound();

        // Recette avec mot de passe : formulaire, refus d'un mauvais mot de passe, puis accès sans compte.
        config(['raaga.sms.mot_de_passe_recette' => 'testeurs-raaga']);
        // Hors environnement « testing », le jeton CSRF serait exigé : il n'est pas l'objet de ce test.
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->get(route('recette.sms'))->assertOk()->assertSee('data-acces-sms-recette', false)->assertDontSee('Mise à jour automatique');
        $this->getJson(route('recette.sms'))->assertUnauthorized();
        $this->from(route('recette.sms'))->post(route('recette.sms.acceder'), ['mot_de_passe' => 'faux'])->assertSessionHasErrors('mot_de_passe');
        $this->post(route('recette.sms.acceder'), ['mot_de_passe' => 'testeurs-raaga'])->assertRedirect(route('recette.sms'));
        $this->get(route('recette.sms'))->assertOk()->assertSee('Mise à jour automatique');
        $this->assertGuest();
    }

    public function test_jamais_de_comptes_de_demonstration_en_recette(): void
    {
        $this->enRecette();
        $this->expectException(\RuntimeException::class);
        $this->seed(ComptesDemoSeeder::class);
    }

    public function test_remise_a_zero_refusee_hors_recette_ou_sans_le_nom_de_la_base(): void
    {
        $this->artisan('raaga:recette-reinitialiser')->assertFailed()->expectsOutputToContain('réservée à l’environnement de recette');

        $this->enRecette();
        User::factory()->create(['email' => 'temoin@recette.raaga.test']);
        $base = (string) config('database.connections.'.config('database.default').'.database');
        $this->artisan('raaga:recette-reinitialiser')
            ->expectsQuestion(__('recette.reinitialiser.confirmation', ['base' => $base]), 'mauvaise_base')
            ->expectsOutputToContain('Remise à zéro annulée')->assertFailed();
        $this->assertDatabaseHas('users', ['email' => 'temoin@recette.raaga.test']);
    }
}
