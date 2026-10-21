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

    public function test_scans_sans_connexion_limites_par_adresse_ip_avec_message_clair(): void
    {
        config(['raaga.cartes.tentatives_par_minute' => 30]);
        $carte = $this->carte();

        // 30 vérifications par minute et par adresse IP, puis 429 avec un message clair et le délai.
        for ($i = 0; $i < 30; $i++) {
            $this->get('/c/'.Str::random(64))->assertOk();
        }
        $this->get($carte->urlVerification())->assertStatus(429)->assertHeader('Retry-After')
            ->assertSee('Trop de vérifications depuis cette connexion. Réessayez dans', false)->assertDontSee('Carte valide');
        // Une autre adresse IP n'est pas concernée.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->get($carte->urlVerification())->assertOk()->assertSee('Carte valide');
    }

    public function test_portique_bonne_cle_jamais_limitee_mauvaises_cles_limitees(): void
    {
        config(['raaga.cartes.tentatives_par_minute' => 30, 'raaga.cartes.cle_portiques' => 'cle-secrete-des-portiques']);
        $carte = $this->carte();
        $bonne = ['X-Cle-Portique' => 'cle-secrete-des-portiques'];

        // 100 vérifications d'un portique avec la bonne clé : toutes passent.
        for ($i = 0; $i < 100; $i++) {
            $this->getJson(route('cartes.portique', $carte->jeton), $bonne)->assertOk()->assertExactJson(['valide' => true]);
        }

        // Mauvaise clé ou sans clé : 30 essais par minute et par adresse IP, puis 429 (JSON, délai d'attente).
        for ($i = 0; $i < 30; $i++) {
            $this->getJson(route('cartes.portique', $carte->jeton), $i % 2 ? ['X-Cle-Portique' => 'mauvaise-'.$i] : [])->assertUnauthorized();
        }
        $this->getJson(route('cartes.portique', $carte->jeton), ['X-Cle-Portique' => 'encore-une'])->assertStatus(429)->assertHeader('Retry-After')
            ->assertJsonPath('erreur', 'trop_de_tentatives')->assertJsonMissingPath('valide');
        // La bonne clé passe toujours depuis cette même adresse.
        $this->getJson(route('cartes.portique', $carte->jeton), $bonne)->assertOk()->assertExactJson(['valide' => true]);
        // Une autre adresse IP garde ses essais.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])->getJson(route('cartes.portique', $carte->jeton), ['X-Cle-Portique' => 'x'])->assertUnauthorized();
    }

    public function test_agents_connectes_limites_par_compte_et_non_par_adresse_ip(): void
    {
        config(['raaga.cartes.tentatives_par_minute' => 30, 'raaga.cartes.tentatives_agents_par_minute' => 300]);
        $carte = $this->carte();

        // Deux agents derrière la même adresse IP (même guichet) : bien au-delà de 30 scans chacun (QR code puis page du
        // guichet), aucun blocage.
        $scanner = fn () => $this->followingRedirects()->get($carte->urlVerification());
        $agentA = $this->connecterBackoffice(Role::AgentGuichet);
        for ($i = 0; $i < 40; $i++) {
            $scanner()->assertOk()->assertSee('data-verdict="valide"', false);
        }
        $this->app['auth']->forgetGuards();
        $this->connecterBackoffice(Role::AgentGuichet);
        for ($i = 0; $i < 40; $i++) {
            $scanner()->assertOk()->assertSee('data-verdict="valide"', false);
        }

        // La limite d'un agent est comptée sur son compte, une fois par scan (réglable, ici 50) ; l'autre agent et
        // l'ADMIN ne sont pas touchés.
        config(['raaga.cartes.tentatives_agents_par_minute' => 50]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($agentA);
        for ($i = 0; $i < 10; $i++) {
            $scanner()->assertOk();
        }
        $scanner()->assertStatus(429)->assertSee('Trop de vérifications');
        $this->app['auth']->forgetGuards();
        $this->connecterBackoffice(Role::Admin);
        $scanner()->assertOk()->assertSee('data-verdict="valide"', false);

        // Les scans des agents n'entament pas la limite des visiteurs sans connexion de la même adresse.
        $this->app['auth']->forgetGuards();
        $this->get($carte->urlVerification())->assertOk()->assertSee('Carte valide');
    }

    public function test_derriere_nginx_chaque_visiteur_a_son_propre_compteur(): void
    {
        config(['raaga.cartes.tentatives_par_minute' => 30]);
        $carte = $this->carte();
        // Requêtes relayées par Nginx (proxy de confiance 127.0.0.1) : l'adresse retenue est celle de X-Forwarded-For.
        $nginx = fn (string $visiteur) => $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->withHeaders(['X-Forwarded-For' => $visiteur]);

        for ($i = 0; $i < 30; $i++) {
            $nginx('41.138.10.1')->get($carte->urlVerification())->assertOk();
        }
        $nginx('41.138.10.1')->get($carte->urlVerification())->assertStatus(429);
        // Un autre visiteur, derrière le même Nginx : son propre compteur.
        $nginx('41.138.10.2')->get($carte->urlVerification())->assertOk()->assertSee('Carte valide');
        $this->assertSame('41.138.10.2', request()->ip());

        // Un en-tête X-Forwarded-For venu d'une adresse qui n'est pas un proxy de confiance est ignoré.
        $this->withServerVariables(['REMOTE_ADDR' => '196.28.1.1'])->withHeaders(['X-Forwarded-For' => '41.138.10.1'])
            ->get($carte->urlVerification())->assertOk();
        $this->assertSame('196.28.1.1', request()->ip());
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
            $this->assertSame(in_array($droit, [Droit::TableauDeBordVoir, Droit::CartesVerifier, Droit::CartesImprimer, Droit::GuichetSouscrire], true), $agent->can($droit->value), $droit->value);
        }

        // L'ancienne adresse /guichet renvoie au back-office ; le menu propose « Guichet ».
        $this->get('/guichet')->assertRedirect('/admin');
        $this->connecterBackoffice(Role::AgentGuichet);
        $this->get(route('admin.tableau'))->assertOk()->assertSee(route('admin.guichet'), false)->assertSee('Agent de guichet');
        $this->assertSame('AGENT DE GUICHET', Role::AgentGuichet->badge());
        $this->assertSame('bo-badge-guichet', Role::AgentGuichet->badgeClasse());
    }
}
