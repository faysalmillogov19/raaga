<?php

namespace Tests\Feature\Cartes;

use App\Enums\Droit;
use App\Enums\Role;
use App\Enums\StatutCarte;
use App\Enums\StatutKyc;
use App\Enums\TypeCarte;
use App\Models\AuditLog;
use App\Models\Carte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/**
 * Vérification des cartes Raaga : page publique sans donnée personnelle, détail réservé aux agents autorisés
 * (cartes.verifier) avec scan tracé, interface des portiques protégée par une clé, Agent de guichet au back-office.
 */
class CartesVerificationTest extends TestCase
{
    use ConnexionBackoffice;
    use RefreshDatabase;

    private User $titulaire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->titulaire = User::factory()->role(Role::Citoyen)->create(['nom' => 'OUEDRAOGO', 'prenoms' => 'Awa', 'name' => 'Awa OUEDRAOGO',
            'telephone' => '+22670123456', 'statut_kyc' => StatutKyc::Valide, 'identite_validee' => ['nom' => 'Awa OUEDRAOGO']]);
    }

    private function carte(StatutCarte $statut = StatutCarte::Active, ?string $valideJusquAu = null): Carte
    {
        $carte = Carte::create(['type' => TypeCarte::Commercant, 'user_id' => $this->titulaire->id, 'statut' => $statut,
            'courante' => $statut->courante() ? true : null, 'jeton' => Str::random(64), 'emise_le' => now(),
            'valide_jusqu_au' => $valideJusquAu ?? now()->addYears(2)->toDateString(),
            'contenu' => ['nom' => 'Awa OUEDRAOGO', 'emplacements' => [['code' => 'RAA-R1-A-045', 'site' => 'Marché de Rood Woko']]]]);
        $carte->forceFill(['numero' => sprintf('RG-C-%06d', $carte->id)])->save();

        return $carte;
    }

    public function test_sans_connexion_la_page_dit_seulement_valide_ou_non_valide(): void
    {
        $carte = $this->carte();
        $url = $carte->urlVerification();

        // Le QR code ne porte que l'adresse de vérification et un jeton aléatoire long : aucune donnée personnelle.
        $this->assertSame(url('/c/'.$carte->jeton), $url);
        $this->assertSame(64, strlen($carte->jeton));
        foreach (['OUEDRAOGO', 'Awa', '70123456', $carte->numero, 'RAA-R1', 'user', 'type'] as $donnee) {
            $this->assertStringNotContainsString($donnee, $url);
        }

        $page = $this->get($url)->assertOk()->assertSee('Carte valide')->assertSee('data-verification="valide"', false)
            ->assertSee('noindex', false);
        foreach (['OUEDRAOGO', 'Awa', '70 12 34 56', $carte->numero, 'RAA-R1-A-045', 'Rood Woko', 'Commerçant', '/photo'] as $donnee) {
            $page->assertDontSee($donnee, false);
        }

        // Jeton inconnu : même page, « Carte non valide ».
        $this->get('/c/'.Str::random(64))->assertOk()->assertSee('Carte non valide')->assertDontSee('Carte valide<', false);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_une_carte_en_attente_suspendue_revoquee_ou_expiree_est_refusee_au_scan(): void
    {
        foreach ([StatutCarte::EnAttente, StatutCarte::Suspendue, StatutCarte::Revoquee, StatutCarte::Expiree] as $statut) {
            Carte::query()->delete();
            $this->get($this->carte($statut)->urlVerification())->assertOk()->assertSee('Carte non valide')
                ->assertSee('data-verification="non_valide"', false);
        }
        // Active mais date de validité dépassée : refusée aussi.
        Carte::query()->delete();
        $this->get($this->carte(StatutCarte::Active, now()->subDay()->toDateString())->urlVerification())->assertSee('Carte non valide');

        // Révocation immédiate : la même adresse est refusée dès le changement de statut.
        Carte::query()->delete();
        $carte = $this->carte();
        $this->get($carte->urlVerification())->assertSee('Carte valide');
        $carte->forceFill(['statut' => StatutCarte::Revoquee, 'courante' => null, 'revoquee_le' => now()])->save();
        $this->get($carte->urlVerification())->assertSee('Carte non valide');
    }

    public function test_l_agent_connecte_voit_le_detail_et_chaque_scan_est_trace(): void
    {
        $carte = $this->carte();
        $agent = $this->connecterBackoffice(Role::AgentGuichet, ['name' => 'Moussa KONÉ']);

        // L'adresse du QR code redirige l'agent vers la page du back-office.
        $this->get($carte->urlVerification())->assertRedirect(route('admin.guichet.carte', $carte->jeton));
        $this->get(route('admin.guichet.carte', $carte->jeton))->assertOk()
            ->assertSee('VALIDE')->assertSee('data-verdict="valide"', false)->assertSee('guichet-verdict-valide', false)
            ->assertSee('Awa OUEDRAOGO')->assertSee('Commerçant')->assertSee('RAA-R1-A-045')->assertSee('Marché de Rood Woko')
            ->assertSee($carte->numero)->assertSee('Active')
            ->assertSee(route('admin.guichet.titulaire', $this->titulaire), false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'carte.scannee', 'acteur_id' => $agent->id, 'entite_id' => $carte->id]);

        // Carte suspendue : NON VALIDE en rouge.
        $carte->forceFill(['statut' => StatutCarte::Suspendue, 'suspendue_le' => now()])->save();
        $this->get(route('admin.guichet.carte', $carte->jeton))->assertOk()->assertSee('NON VALIDE')
            ->assertSee('guichet-verdict-invalide', false)->assertSee('Suspendue');

        // Jeton inconnu : NON VALIDE, scan tracé quand même.
        $this->get(route('admin.guichet.carte', $inconnu = Str::random(64)))->assertOk()->assertSee('NON VALIDE')->assertSee('Carte inconnue');
        $this->assertSame(3, AuditLog::query()->where('action', 'carte.scannee')->count());

        // Recherche par numéro de carte ou par adresse scannée.
        $this->post(route('admin.guichet.rechercher'), ['carte' => strtolower($carte->numero)])->assertRedirect(route('admin.guichet.carte', $carte->jeton));
        $this->post(route('admin.guichet.rechercher'), ['carte' => $carte->urlVerification()])->assertRedirect(route('admin.guichet.carte', $carte->jeton));
        $this->from(route('admin.guichet'))->post(route('admin.guichet.rechercher'), ['carte' => 'RG-C-999999'])
            ->assertRedirect(route('admin.guichet'))->assertSessionHasErrors('carte');
    }

    public function test_l_agent_accede_au_dossier_du_titulaire_sans_aucun_montant(): void
    {
        $this->carte();
        $this->connecterBackoffice(Role::AgentGuichet);

        $this->get(route('admin.guichet.titulaire', $this->titulaire))->assertOk()
            ->assertSee('Awa OUEDRAOGO')->assertSee('+226 70 12 34 56')->assertSee('RG-C-')->assertDontSee('FCFA');

        // Seuls les titulaires d'une carte : pas d'annuaire des comptes.
        $autre = User::factory()->role(Role::Citoyen)->create();
        $this->get(route('admin.guichet.titulaire', $autre))->assertNotFound();
    }

    public function test_seuls_l_agent_de_guichet_et_l_admin_verifient_les_cartes(): void
    {
        $carte = $this->carte();

        // ADMIN : autorisé.
        $this->connecterBackoffice(Role::Admin);
        $this->get(route('admin.guichet.carte', $carte->jeton))->assertOk()->assertSee('VALIDE');
        $this->get(route('admin.guichet'))->assertOk();

        foreach ([Role::SuperAdmin, Role::Validateur, Role::Regulateur, Role::GestionnaireSites, Role::ControleurPaiements] as $role) {
            $this->app['auth']->forgetGuards();
            $this->connecterBackoffice($role);
            $this->get(route('admin.guichet.carte', $carte->jeton))->assertForbidden();
            // Connecté sans le droit : la page publique, sans détail.
            $this->get($carte->urlVerification())->assertOk()->assertSee('Carte valide')->assertDontSee('Awa OUEDRAOGO');
        }

        // Le titulaire connecté (citoyen) : page publique, sans détail.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->titulaire)->get($carte->urlVerification())->assertOk()->assertSee('Carte valide');
        $this->get(route('admin.guichet.carte', $carte->jeton))->assertForbidden();
    }

    public function test_interface_des_portiques_avec_et_sans_cle(): void
    {
        $carte = $this->carte();
        $url = route('cartes.portique', $carte->jeton);

        // Aucune clé configurée : toujours refusé.
        config(['raaga.cartes.cle_portiques' => null]);
        $this->getJson($url)->assertUnauthorized()->assertExactJson(['erreur' => 'cle_invalide']);

        config(['raaga.cartes.cle_portiques' => 'cle-secrete-des-portiques']);
        $this->getJson($url)->assertUnauthorized();
        $this->getJson($url, ['X-Cle-Portique' => 'mauvaise'])->assertUnauthorized();

        // Bonne clé : valide ou non valide, et rien d'autre.
        $this->getJson($url, ['X-Cle-Portique' => 'cle-secrete-des-portiques'])->assertOk()->assertExactJson(['valide' => true]);
        $carte->forceFill(['statut' => StatutCarte::Revoquee, 'courante' => null])->save();
        $this->getJson($url, ['X-Cle-Portique' => 'cle-secrete-des-portiques'])->assertOk()->assertExactJson(['valide' => false]);
        $this->getJson(route('cartes.portique', Str::random(64)), ['X-Cle-Portique' => 'cle-secrete-des-portiques'])->assertExactJson(['valide' => false]);
    }

    public function test_l_agent_de_guichet_est_un_profil_du_back_office(): void
    {
        $this->assertTrue(Role::AgentGuichet->estBackoffice());
        $this->assertNotContains(Role::AgentGuichet, Role::grandPublic());
        $this->assertNotContains(Role::AgentGuichet, Role::masques());
        $this->assertContains(Role::AgentGuichet, Role::attribuablesBackoffice());
        $this->assertSame('admin.tableau', Role::AgentGuichet->dashboardRoute());

        $agent = User::factory()->role(Role::AgentGuichet)->create();
        foreach (Droit::cases() as $droit) {
            $this->assertSame(in_array($droit, [Droit::TableauDeBordVoir, Droit::CartesVerifier, Droit::CartesImprimer], true), $agent->can($droit->value), $droit->value);
        }

        // L'ancienne adresse /guichet renvoie au back-office ; le menu propose « Guichet ».
        $this->get('/guichet')->assertRedirect('/admin');
        $this->connecterBackoffice(Role::AgentGuichet);
        $this->get(route('admin.tableau'))->assertOk()->assertSee(route('admin.guichet'), false)->assertSee('Agent de guichet');
        $this->assertSame('AGENT DE GUICHET', Role::AgentGuichet->badge());
        $this->assertSame('bo-badge-guichet', Role::AgentGuichet->badgeClasse());
    }
}
