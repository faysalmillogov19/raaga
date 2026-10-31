<?php

namespace Tests\Feature;

use App\Contracts\PaymentProvider;
use App\Enums\Role;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutKyc;
use App\Jobs\GenererFichiersCarte;
use App\Models\AuditLog;
use App\Models\MoyenPaiement;
use App\Models\Niveau;
use App\Models\Paiement;
use App\Models\ReservationBoutique;
use App\Models\TypeBoutique;
use App\Models\Unite;
use App\Models\User;
use App\Services\Cartes\NavigateurChrome;
use App\Services\Plateforme\ParametresPlateforme;
use Database\Seeders\ComptesDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReferentielsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * Verrous de production : comptes de démonstration, paiement simulé, contrôle à deux niveaux (bloquant, avertissement,
 * --strict), verrou métier « paiements fermés », seeders de référentiel idempotents, ordre de deploy.sh.
 */
class VerrousProductionTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private const COMPTES = [
        'raaga.compte_sequestre' => ['titulaire' => 'Raaga SA', 'banque' => 'Banque A', 'numero' => 'BF00 1'],
        'raaga.compte_location' => ['titulaire' => 'Raaga SA location', 'banque' => 'Banque B', 'numero' => 'BF00 2'],
        'raaga.cartes.cle_portiques' => 'cle-des-portiques',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // File d'attente réelle, comme en production (les tests tournent par défaut en « sync »).
        config(['queue.default' => 'database']);
    }

    /** Opérateur de paiement réel (doublure). */
    private function operateurReel(): void
    {
        config(['raaga.paiement.fournisseur' => 'operateur_test']);
        $this->app->instance(PaymentProvider::class, new class implements PaymentProvider
        {
            public function estSimule(): bool
            {
                return false;
            }

            public function initier(Paiement $paiement): void {}
        });
    }

    public function test_le_seeder_de_demo_refuse_de_s_executer_en_production(): void
    {
        $this->app['env'] = 'production';

        $this->assertThrows(fn () => $this->app->make(ComptesDemoSeeder::class)->run(), RuntimeException::class, 'comptes de démonstration');
        // Le seeder complet installe le reste (rôles, référentiels, moyens de paiement), sans aucun compte de démonstration.
        $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();
        $this->assertSame(0, User::query()->where('email', 'like', '%@raaga.test')->count());
        $this->assertGreaterThan(0, MoyenPaiement::query()->count());
    }

    public function test_la_commande_verifie_puis_desactive_les_comptes_de_demo(): void
    {
        $this->seed(ComptesDemoSeeder::class);
        $reel = User::factory()->role(Role::Validateur)->create(['email' => 'mariam.traore@raaga.bf', 'password' => 'un-vrai-mot-de-passe']);
        $copie = User::factory()->role(Role::Regulateur)->create(['email' => 'clarisse@raaga.bf', 'password' => 'clarisse@raaga.bf']); // mot de passe = e-mail
        $demo = User::query()->where('email', 'like', '%@raaga.test')->count();

        $this->artisan('raaga:comptes-demo')->assertFailed()->expectsOutputToContain('Relancez avec --desactiver');
        $this->artisan('raaga:comptes-demo --desactiver')->assertSuccessful()->expectsOutputToContain(($demo + 1).' compte(s) de démonstration désactivé(s)');
        $this->assertSame(0, User::query()->where('actif', true)->where('email', 'like', '%@raaga.test')->count());
        $this->assertFalse($copie->fresh()->actif);
        $this->assertTrue($reel->fresh()->actif);
        $this->assertSame($demo + 1, AuditLog::query()->where('action', 'compte.demo_desactive')->count());
        $this->artisan('raaga:comptes-demo')->assertSuccessful()->expectsOutputToContain('Aucun compte de démonstration actif');
    }

    public function test_bloquants_demo_comptes_et_paiement_simule(): void
    {
        $this->seed(ComptesDemoSeeder::class);
        config(['raaga.demo' => true, 'raaga.paiement.fournisseur' => 'simule', ...self::COMPTES]);

        $this->artisan('raaga:verifier-production')->assertFailed()
            ->expectsOutputToContain('BLOQUANT : RAAGA_DEMO=true')
            ->expectsOutputToContain('compte(s) de démonstration actif(s)')
            ->expectsOutputToContain('BLOQUANT : RAAGA_PAIEMENT_FOURNISSEUR=simule')
            ->expectsOutputToContain('Mise en production refusée : 3 point(s) bloquant(s).');
    }

    /** Logo de l'onglet « Identité » déposé, de la hauteur donnée (le logo fourni avec le code ne fait que 96 px de haut). */
    private function deposerLogo(int $hauteur): void
    {
        $image = imagecreatetruecolor($hauteur * 3, $hauteur);
        ob_start();
        imagepng($image);
        Storage::disk('local')->put('plateforme/logoHorizontal-test.png', (string) ob_get_clean());
        $parametres = app(ParametresPlateforme::class);
        $parametres->enregistrer('identite', [...$parametres->section('identite'), 'logoHorizontal' => 'plateforme/logoHorizontal-test.png'], null);
    }

    public function test_armoiries_et_logo_par_defaut_en_basse_resolution_avertissement(): void
    {
        Storage::fake('local');
        config(['raaga.demo' => false, ...self::COMPTES]);
        $this->operateurReel();

        // Rien de déposé : armoiries fournies (998 px de haut) acceptées, logo fourni (96 px) signalé.
        $this->artisan('raaga:verifier-production')->assertSuccessful()
            ->expectsOutputToContain('AVERTISSEMENT : Logo par défaut en basse résolution (96 px de haut, 600 attendus')
            ->doesntExpectOutputToContain('Armoiries par défaut en basse résolution')
            ->expectsOutputToContain('Aucun point bloquant (1 avertissement(s)');

        // Logo déposé : plus d'avertissement, même trop petit (seuls les fichiers par défaut sont contrôlés ici).
        $this->deposerLogo(120);
        $this->operateurReel();
        $this->artisan('raaga:verifier-production --strict')->assertSuccessful()->expectsOutputToContain('Prêt pour la production');
    }

    public function test_avertissements_sans_blocage_puis_strict(): void
    {
        Storage::fake('local');
        $this->deposerLogo(600);
        // Aucun opérateur réel, comptes de destination et clé des portiques manquants : avertissements, code 0 (services fermés).
        config(['raaga.demo' => false, 'raaga.paiement.fournisseur' => null, 'raaga.compte_sequestre' => [], 'raaga.compte_location' => ['titulaire' => 'Raaga SA'],
            'raaga.cartes.cle_portiques' => '']);
        $commande = $this->artisan('raaga:verifier-production')->assertSuccessful()
            ->expectsOutputToContain('AVERTISSEMENT : Aucun opérateur de paiement réel configuré : paiements par opérateur fermés')
            ->expectsOutputToContain('AVERTISSEMENT : Variable manquante : RAAGA_CLE_PORTIQUES')
            ->expectsOutputToContain('Aucun point bloquant (7 avertissement(s)');
        foreach (['RAAGA_SEQUESTRE_TITULAIRE', 'RAAGA_SEQUESTRE_BANQUE', 'RAAGA_SEQUESTRE_NUMERO', 'RAAGA_COMPTE_LOCATION_BANQUE', 'RAAGA_COMPTE_LOCATION_NUMERO'] as $variable) {
            $commande->expectsOutputToContain('Variable manquante : '.$variable);
        }
        $commande->run();

        // --strict : les avertissements deviennent bloquants.
        $this->artisan('raaga:verifier-production --strict')->assertFailed()
            ->expectsOutputToContain('BLOQUANT (--strict) : Aucun opérateur de paiement réel')
            ->expectsOutputToContain('BLOQUANT (--strict) : Variable manquante : RAAGA_CLE_PORTIQUES')
            ->expectsOutputToContain('Mise en production refusée : 7 point(s) bloquant(s).');

        // Seule la clé des portiques manque : avertissement, bloquant avec --strict.
        config([...self::COMPTES, 'raaga.cartes.cle_portiques' => null]);
        $this->operateurReel();
        $this->artisan('raaga:verifier-production')->assertSuccessful()->expectsOutputToContain('Aucun point bloquant (1 avertissement(s)');
        $this->artisan('raaga:verifier-production --strict')->assertFailed()->expectsOutputToContain('Mise en production refusée : 1 point(s) bloquant(s).');

        // Tout est prêt : réussi, même en --strict.
        config(self::COMPTES);
        $this->operateurReel();
        $this->artisan('raaga:verifier-production --strict')->assertSuccessful()->expectsOutputToContain('Prêt pour la production');
    }

    public function test_verrou_metier_paiements_fermes(): void
    {
        $this->creerActeurs();
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class); // en « production », le jeton CSRF n'est plus ignoré par les tests
        config(['raaga.paiement.fournisseur' => null, ...self::COMPTES]);
        $mobile = Boutiques::campagneOuverte(['statut' => StatutCampagneBoutiques::Brouillon, 'nom' => 'Mobile 2027', 'canaux_actifs' => ['ORANGE_MONEY']]);
        $virement = Boutiques::campagneOuverte(['statut' => StatutCampagneBoutiques::Brouillon, 'nom' => 'Virement 2027', 'canaux_actifs' => ['VIREMENT']]);
        $confirmee = Boutiques::campagneOuverte(['statut' => StatutCampagneBoutiques::Confirmee, 'nom' => 'Confirmée 2027', 'canaux_actifs' => ['ORANGE_MONEY']]);
        $message = 'Paiements fermés : aucun opérateur de paiement réel n’est configuré.';

        // Sans opérateur réel, une campagne qui n'accepte que le mobile money ne se propose ni ne s'ouvre ; message sur la fiche.
        $this->en($this->regulateur)->post(route('admin.boutiques.soumettre', $mobile))->assertSessionHas('erreur', fn ($m) => str_starts_with($m, $message));
        $this->assertSame(StatutCampagneBoutiques::Brouillon, $mobile->fresh()->statut);
        // Date d'ouverture passée : l'ouverture automatique attend, la fiche affiche la raison.
        $this->artisan('raaga:campagnes-boutiques')->assertSuccessful();
        $this->assertSame(StatutCampagneBoutiques::Confirmee, $confirmee->fresh()->statut);
        $this->en($this->validateur)->get(route('admin.boutiques.show', $confirmee))->assertSee('data-ouverture-attente', false)->assertSee($message);
        $this->en($this->regulateur)->get(route('admin.boutiques.show', $mobile))->assertSee('data-paiements-fermes', false)->assertSee('aucun opérateur de paiement réel');

        // Avec le virement (paiement manuel, compte de location renseigné), la campagne est ouvrable même sans opérateur.
        $this->assertNull($virement->raisonPaiementsFermes());
        $this->en($this->regulateur)->get(route('admin.boutiques.show', $virement))->assertDontSee('data-paiements-fermes', false);
        $this->en($this->regulateur)->post(route('admin.boutiques.soumettre', $virement));
        $this->assertStringNotContainsString('Paiements fermés', (string) session('erreur'));

        // Citoyen, sans opérateur : le virement se paie (aucun opérateur), le mobile money est refusé.
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+22670123456']);
        $ouverte = Boutiques::campagneOuverte(['nom' => 'Ouverte 2027']);
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('B'));
        $candidature = ReservationBoutique::query()->sole();
        $url = '/boutiques/reservations/'.$candidature->reference.'/caution';
        $this->actingAs($citoyen)->get($url)->assertOk()->assertSee('value="VIREMENT"', false)->assertDontSee('value="ORANGE_MONEY"', false);
        $this->actingAs($citoyen)->post($url, ['canal' => 'ORANGE_MONEY', 'telephone' => '70123456'])->assertSessionHasErrors(['canal' => 'Moyen de paiement indisponible']);
        $this->assertSame(0, Paiement::query()->count());
        $this->actingAs($citoyen)->post($url, ['canal' => 'VIREMENT'])->assertSessionHasNoErrors();
        $this->assertNotNull(Paiement::query()->sole()->reference_versement);
        $this->actingAs($citoyen)->post($url.'/changer');

        // Compte des fonds de location non renseigné : tout est fermé, même le virement et même avec un opérateur réel.
        $this->operateurReel();
        config(['raaga.compte_location' => []]);
        $this->en($this->regulateur)->post(route('admin.boutiques.soumettre', $virement->fresh()->statut === StatutCampagneBoutiques::Brouillon ? $virement : $mobile))
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'les coordonnées du compte des fonds de location'));
        $this->actingAs($citoyen)->get($url)->assertOk()->assertSee('Moyen de paiement indisponible');
        $this->actingAs($citoyen)->post($url, ['canal' => 'VIREMENT'])->assertSessionHasErrors(['canal' => 'Moyen de paiement indisponible']);

        // Opérateur réel et comptes renseignés : le mobile money s'ouvre aussi.
        config(self::COMPTES);
        $this->assertNull($mobile->fresh()->raisonPaiementsFermes());
        $this->actingAs($citoyen)->get($url)->assertSee('value="ORANGE_MONEY"', false);
    }

    public function test_seeders_de_referentiel_idempotents_apres_modification_par_le_gestionnaire(): void
    {
        $this->creerActeurs();
        $gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->seed(ReferentielsSeeder::class);

        // Le GESTIONNAIRE renomme un niveau par défaut (validé), désactive un type par défaut (validé), ajoute une zone.
        $r1 = Niveau::query()->where('code', 'R1')->sole();
        $this->en($gestionnaire)->put(route('admin.referencement.zones.update', ['niveaux', $r1->id]), ['libelle' => 'Premier étage', 'rang' => 1])->assertSessionHas('succes');
        $this->en($this->validateur)->post(route('admin.referencement.zones.confirmer', ['niveaux', $r1->id]))->assertSessionHas('succes');
        $mag = TypeBoutique::query()->where('code', 'MAG')->sole();
        $this->en($gestionnaire)->post(route('admin.sites.types.desactiver', $mag))->assertSessionHas('succes');
        $this->en($this->validateur)->post(route('admin.sites.types.confirmer', $mag))->assertSessionHas('succes');
        $this->en($gestionnaire)->post(route('admin.referencement.zones.store', 'zones'), ['code' => 'KIO', 'libelle' => 'Kiosques'])->assertSessionHas('succes');
        // Une unité par défaut a disparu : le seeder doit la recréer, et seulement elle.
        $redevance = Unite::query()->where('code', 'REDEVANCE')->sole();
        DB::table('unites')->where('id', $redevance->id)->delete();

        $tables = ['niveaux', 'zones', 'types_boutiques', 'unites', 'moyens_paiement', 'parametres_plateforme'];
        $etat = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($l) => (array) $l)->all()])->all();
        $avant = $etat();

        $this->seed(ReferentielsSeeder::class);
        $this->seed(ReferentielsSeeder::class);
        $apres = $etat();

        // Rien de modifié ni de supprimé ; seule l'unité manquante est recréée.
        foreach ($tables as $t) {
            $this->assertSame($avant[$t], array_slice($apres[$t], 0, count($avant[$t])), $t);
        }
        $this->assertSame([['code' => 'REDEVANCE']], array_map(fn ($l) => ['code' => $l['code']], array_slice($apres['unites'], count($avant['unites']))));
        foreach (array_diff($tables, ['unites']) as $t) {
            $this->assertCount(count($avant[$t]), $apres[$t], $t);
        }
        $this->assertSame('Premier étage', $r1->fresh()->libelle);
        $this->assertFalse($mag->fresh()->actif);
        $this->assertTrue(DB::table('zones')->where('code', 'KIO')->exists());
    }

    public function test_fichiers_des_cartes_file_d_attente_chrome_et_echecs(): void
    {
        Storage::fake('local');
        $this->deposerLogo(600);
        config(['raaga.demo' => false, ...self::COMPTES]);
        $this->operateurReel();

        // File d'attente « sync » : les fichiers seraient générés pendant la requête.
        config(['queue.default' => 'sync']);
        $this->artisan('raaga:verifier-production')->assertFailed()
            ->expectsOutputToContain('BLOQUANT : QUEUE_CONNECTION=sync : les fichiers des cartes doivent être générés en tâche de fond');
        config(['queue.default' => 'database']);

        // Chrome ne démarre pas (essai réel simulé) : bloquant.
        $this->app->make(NavigateurChrome::class)->panne = true;
        $this->artisan('raaga:verifier-production')->assertFailed()->expectsOutputToContain('BLOQUANT : Chrome ne démarre pas (RAAGA_CHROME_BINAIRE)');
        $this->app->make(NavigateurChrome::class)->panne = false;

        // Générations de carte en échec (avec leur nombre) et tâche en attente depuis plus de 10 minutes : avertissements.
        foreach ([1, 2] as $carte) {
            DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'exception' => 'Chrome',
                'payload' => json_encode(['displayName' => GenererFichiersCarte::class]), 'failed_at' => now()]);
        }
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subMinutes(15)->getTimestamp(), 'created_at' => now()->subMinutes(15)->getTimestamp()]);
        $this->artisan('raaga:verifier-production')->assertSuccessful()
            ->expectsOutputToContain('AVERTISSEMENT : 2 génération(s) de fichiers de carte en échec')
            ->expectsOutputToContain('AVERTISSEMENT : 1 tâche(s) en attente depuis plus de 10 minutes')
            ->expectsOutputToContain('Aucun point bloquant (2 avertissement(s)');
    }

    public function test_ordre_de_deploy_sh(): void
    {
        $script = file_get_contents(base_path('deploy.sh'));

        $etapes = [
            'set -euo pipefail', 'git pull --ff-only', 'composer install --no-dev --optimize-autoloader',
            'php artisan raaga:verifier-production', 'pg_dump', 'php artisan down', "trap 'php artisan up' EXIT", 'php artisan migrate --force',
            'php artisan db:seed --class=ReferentielsSeeder --force', 'php artisan storage:link', 'php artisan config:cache', 'php artisan route:cache',
            'php artisan view:cache', 'php artisan queue:restart', 'systemctl reload',
        ];
        $positions = array_map(fn ($e) => strpos($script, $e), $etapes);
        $etapes[] = 'php artisan up (final)';
        $positions[] = strrpos($script, 'php artisan up');
        foreach ($etapes as $i => $etape) {
            $this->assertNotFalse($positions[$i], $etape);
        }
        // Dans cet ordre ; « up » final après le rechargement de php-fpm.
        $this->assertSame($positions, collect($positions)->sort()->values()->all());
        // Assets statiques : ni Node, ni npm, ni compilation.
        foreach (['node', 'npm', 'NODE_MIN', 'vite'] as $absent) {
            $this->assertStringNotContainsString($absent, $script);
        }
        $this->assertStringContainsString('/data/backups', $script);
        $this->assertStringNotContainsString('raaga:verifier-production --strict', $script); // bloquants seulement
        $this->assertStringNotContainsString('ComptesDemoSeeder', $script);
    }
}
