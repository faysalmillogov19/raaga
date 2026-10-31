<?php

namespace Tests\Feature\Cartes;

use App\Enums\Role;
use App\Enums\StatutCarte;
use App\Enums\StatutKyc;
use App\Enums\TypeCarte;
use App\Jobs\GenererFichiersCarte;
use App\Models\Carte;
use App\Models\User;
use App\Services\Cartes\Cartes;
use App\Services\Cartes\FichiersCarte;
use App\Services\Cartes\NavigateurChrome;
use App\Services\Plateforme\ParametresPlateforme;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\ChromeFactice;
use Tests\Support\ConnexionBackoffice;
use Tests\Support\ReglagesCarteComplets;
use Tests\TestCase;

/**
 * Fichiers de la carte générés à l'avance (30/10/2026) : job à l'émission et à chaque nouvelle version, aucune
 * génération pendant la requête, réessais puis journalisation, aucune régénération quand un réglage change, commande de
 * support raaga:cartes-fichiers. Chrome simulé.
 */
class CartesFichiersTest extends TestCase
{
    use ConnexionBackoffice;
    use RefreshDatabase;
    use ReglagesCarteComplets;

    private ChromeFactice $chrome;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->completerReglagesCarte();
        $this->chrome = $this->app->make(NavigateurChrome::class);
    }

    private function carte(?User $user = null): Carte
    {
        $user ??= User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide]);
        $carte = Carte::create(['type' => TypeCarte::Commercant, 'user_id' => $user->id, 'statut' => StatutCarte::Active, 'courante' => true,
            'jeton' => Str::random(64), 'emise_le' => now(), 'valide_jusqu_au' => '2028-12-31', 'contenu' => ['nom' => 'Aïcha OUATTARA']]);
        $carte->forceFill(['numero' => sprintf('RG-C-%06d', $carte->id)])->save();

        return $carte;
    }

    private function generer(Carte $carte): void
    {
        $this->app->call([new GenererFichiersCarte($carte->id, $carte->fresh()->version), 'handle']);
    }

    private function pousse(Carte $carte, int $version): bool
    {
        return Queue::pushed(GenererFichiersCarte::class, fn (GenererFichiersCarte $job) => $job->carteId === $carte->id && $job->version === $version)->isNotEmpty();
    }

    public function test_generation_a_l_emission_puis_a_chaque_version(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $carte = app(Cartes::class)->emettrePersonnel($admin, User::factory()->role(Role::SuperAdmin)->create());

        // Émission : un job pour la version 1, rien n'est rendu avant son exécution.
        $this->assertTrue($this->pousse($carte, 1));
        $this->assertSame(0, $this->chrome->rendus);
        $fichiers = app(FichiersCarte::class);
        $this->assertFalse($fichiers->prets($carte));

        $this->generer($carte);
        Storage::disk('local')->assertExists(['cartes/fichiers/'.$carte->id.'/v1/recto.png', 'cartes/fichiers/'.$carte->id.'/v1/verso.png', 'cartes/fichiers/'.$carte->id.'/v1/carte.pdf']);
        $this->assertTrue($fichiers->prets($carte));
        $this->assertSame([1011, 638], array_slice(getimagesizefromstring($fichiers->lire($carte, 'recto')), 0, 2));
        $this->assertStringStartsWith('%PDF', $fichiers->lire($carte, 'pdf'));
        // Déjà prêts : le job ne refait rien.
        $rendus = $this->chrome->rendus;
        $this->generer($carte);
        $this->assertSame($rendus, $this->chrome->rendus);

        // Donnée imprimée modifiée (date de naissance) : nouvelle version, nouveau job.
        $admin->forceFill(['date_naissance' => '1990-05-02'])->save();
        app(Cartes::class)->synchroniser($admin, TypeCarte::Personnel);
        $carte->refresh();
        $this->assertSame([2, '1990-05-02'], [$carte->version, $carte->contenu['date_naissance']]);
        $this->assertTrue($this->pousse($carte, 2));
        $this->assertFalse($fichiers->prets($carte));

        // Job d'une version dépassée : rien n'est rendu.
        $rendus = $this->chrome->rendus;
        $this->app->call([new GenererFichiersCarte($carte->id, 1), 'handle']);
        $this->assertSame($rendus, $this->chrome->rendus);
    }

    public function test_telechargement_d_un_fichier_pret_sans_rendu_pendant_la_requete(): void
    {
        $carte = $this->carte();
        $this->generer($carte);
        $rendus = $this->chrome->rendus;

        $verso = $this->actingAs($carte->user)->get(route('cartes.fichier', [$carte, 'png', 'face' => 'verso']))->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->assertSame(app(FichiersCarte::class)->lire($carte, 'verso'), $verso->getContent());
        $this->assertStringContainsString('-v1-verso.png', $verso->headers->get('Content-Disposition'));
        $this->get(route('cartes.fichier', [$carte, 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame($rendus, $this->chrome->rendus);
    }

    public function test_fichier_pas_encore_pret_message_et_job_sans_rendu_pendant_la_requete(): void
    {
        $carte = $this->carte();
        Queue::fake([GenererFichiersCarte::class]);

        $this->actingAs($carte->user)->from(route('espace.carte', ['type' => 'commercant']))->get(route('cartes.fichier', [$carte, 'png']))
            ->assertRedirect(route('espace.carte', ['type' => 'commercant']))
            ->assertSessionHas('erreur', 'Votre carte est en cours de préparation. Elle sera disponible prochainement.');
        $this->assertTrue($this->pousse($carte, 1));
        $this->assertSame(0, $this->chrome->rendus);

        // « Ma carte » demande aussi les fichiers manquants, pour qu'ils soient prêts au premier téléchargement.
        Queue::fake([GenererFichiersCarte::class]);
        $this->get(route('espace.carte', ['type' => 'commercant']))->assertOk();
        $this->assertTrue($this->pousse($carte, 1));
        $this->assertSame(0, $this->chrome->rendus);

        // Un job par carte et par version (verrou d'unicité).
        $job = new GenererFichiersCarte($carte->id, 1);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame($carte->id.'-v1', $job->uniqueId());
    }

    public function test_trois_essais_puis_la_cause_est_journalisee_avec_le_numero(): void
    {
        $carte = $this->carte();
        $job = new GenererFichiersCarte($carte->id, 1);
        $this->assertSame(3, $job->tries);

        // Chrome en panne : l'essai échoue (la file d'attente réessaie), puis l'échec définitif est journalisé.
        $this->chrome->panne = true;
        try {
            $this->app->call([$job, 'handle']);
            $this->fail('L’essai aurait dû échouer.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Chrome ne démarre pas', $e->getMessage());
        }
        Log::shouldReceive('error')->once()->with('Fichiers de la carte non générés après 3 essais.', [
            'carte' => $carte->numero, 'version' => 1, 'erreur' => 'Rendu de la carte impossible : Chrome ne démarre pas (RAAGA_CHROME_BINAIRE). Devtools could not start.',
        ]);
        $job->failed($e);
        $this->assertFalse(app(FichiersCarte::class)->prets($carte));
    }

    public function test_reglage_manquant_avertissement_sans_nouvel_essai(): void
    {
        $this->completerReglagesCarte(['cachetAutorite' => null]);
        $carte = $this->carte();

        Log::shouldReceive('warning')->once()->with('Fichiers de la carte non générés : réglages manquants.', ['carte' => $carte->numero, 'version' => 1, 'manquants' => ['cachetAutorite']]);
        $this->generer($carte);
        $this->assertSame(0, $this->chrome->rendus);
    }

    public function test_aucune_regeneration_quand_un_reglage_change(): void
    {
        $carte = $this->carte();
        $this->generer($carte);
        $avant = app(FichiersCarte::class)->lire($carte, 'pdf');
        $rendus = $this->chrome->rendus;
        Queue::fake([GenererFichiersCarte::class]);

        // Nouvelle signature et nouveau cachet : la carte émise garde ses réglages figés et ses fichiers.
        Storage::disk('local')->put('plateforme/signature-nouvelle.png', $this->pngTest(900, 300, [200, 0, 0]));
        $parametres = app(ParametresPlateforme::class);
        $parametres->enregistrer('carte', [...$parametres->section('carte'), 'signatureAutorite' => 'plateforme/signature-nouvelle.png', 'nomAutorite' => 'Issa KABORÉ'], null);

        Queue::assertNotPushed(GenererFichiersCarte::class);
        $this->assertSame($rendus, $this->chrome->rendus);
        $this->assertSame($avant, app(FichiersCarte::class)->lire($carte->fresh(), 'pdf'));
        $this->assertTrue(app(FichiersCarte::class)->prets($carte->fresh()));
    }

    public function test_commande_de_support_regenere_une_carte_ou_toutes(): void
    {
        $premiere = $this->carte();
        $seconde = $this->carte();
        $suspendue = $this->carte();
        $suspendue->forceFill(['statut' => StatutCarte::Suspendue])->save();
        $fichiers = app(FichiersCarte::class);

        $this->artisan('raaga:cartes-fichiers')->assertFailed()->expectsOutputToContain('Indiquez le numéro d’une carte');
        $this->artisan('raaga:cartes-fichiers RG-C-999999')->assertFailed()->expectsOutputToContain('introuvable');

        // Une carte, numéro saisi en minuscules ; sa génération en échec est retirée de la liste des échecs.
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'exception' => 'Chrome',
            'payload' => json_encode(['displayName' => GenererFichiersCarte::class, 'data' => ['command' => serialize(new GenererFichiersCarte($premiere->id, 1))]]), 'failed_at' => now()]);
        $this->artisan('raaga:cartes-fichiers '.strtolower($premiere->numero))->assertSuccessful()
            ->expectsOutputToContain($premiere->numero.' (version 1) : fichiers générés')->expectsOutputToContain('Cartes traitées : 1');
        $this->assertTrue($fichiers->prets($premiere));
        $this->assertFalse($fichiers->prets($seconde));
        $this->assertSame(0, DB::table('failed_jobs')->count());

        // Toutes les cartes actives sans fichiers (la carte suspendue est laissée de côté).
        $this->artisan('raaga:cartes-fichiers --toutes --manquants')->assertSuccessful()
            ->expectsOutputToContain('Cartes traitées : 1 ; déjà prêtes : 1 ; en échec : 0.');
        $this->assertTrue($fichiers->prets($seconde));
        $this->assertFalse($fichiers->prets($suspendue));

        // Chrome en panne : échec signalé, code d'erreur.
        $this->chrome->panne = true;
        $this->artisan('raaga:cartes-fichiers --toutes')->assertFailed()->expectsOutputToContain('en échec : 2.');
    }

    public function test_echec_definitif_message_au_titulaire_alerte_et_relance_au_back_office(): void
    {
        $carte = $this->carte();
        $this->chrome->panne = true;
        $job = new GenererFichiersCarte($carte->id, 1);
        try {
            $this->app->call([$job, 'handle']);
        } catch (RuntimeException $e) {
            $job->failed($e);
        }
        $carte->refresh();
        $this->assertTrue($carte->fichiersEnEchec());
        $this->assertStringContainsString('Chrome ne démarre pas', $carte->fichiers_echec_cause);

        // Titulaire : « n'a pas pu être préparée », sans nouvelle génération automatique (ni au clic, ni à l'ouverture).
        Queue::fake([GenererFichiersCarte::class]);
        $this->actingAs($carte->user)->from(route('espace.carte', ['type' => 'commercant']))->get(route('cartes.fichier', [$carte, 'pdf']))
            ->assertRedirect(route('espace.carte', ['type' => 'commercant']))
            ->assertSessionHas('erreur', 'Votre carte n’a pas pu être préparée. Contactez l’assistance.');
        $this->get(route('espace.carte', ['type' => 'commercant']))->assertOk()->assertSee('data-carte-echec', false)
            ->assertSee('Votre carte n’a pas pu être préparée. Contactez l’assistance.');
        Queue::assertNotPushed(GenererFichiersCarte::class);

        // Back-office : alerte au SUPER_ADMIN et à l'agent de guichet (référence, cause, bouton), pas à l'ADMIN ni au VALIDATEUR.
        foreach ([Role::Admin, Role::Validateur] as $role) {
            $this->app['auth']->forgetGuards();
            $this->connecterBackoffice($role);
            $this->get(route('admin.tableau'))->assertOk()->assertDontSee('data-cartes-echec', false);
            $this->post(route('admin.cartes.fichiers.relancer', $carte))->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->get(route('admin.tableau'))->assertOk()->assertSee('data-cartes-echec', false)
            ->assertSee('1 carte dont les fichiers n’ont pas pu être générés')->assertSee($carte->numero)
            ->assertSee('Chrome ne démarre pas')->assertSee('Relancer la génération')
            ->assertSee(route('admin.cartes.fichiers.relancer', $carte), false);
        $this->app['auth']->forgetGuards();
        $agent = $this->connecterBackoffice(Role::AgentGuichet);
        $this->get(route('admin.tableau'))->assertOk()->assertSee('data-cartes-echec', false)->assertSee($carte->numero);

        // Impression au guichet : refusée, avec renvoi vers la relance.
        $this->from(route('admin.guichet.carte', $carte->jeton))->post(route('admin.guichet.imprimer', $carte->jeton), ['piece_verifiee' => '1'])
            ->assertSessionHasErrors(['piece_verifiee' => 'Les fichiers de cette carte n’ont pas pu être générés : relancez la génération depuis le tableau de bord.']);

        // Relance par l'agent : échec effacé, job relancé pour cette carte, relance tracée avec son auteur.
        $this->from(route('admin.tableau'))->post(route('admin.cartes.fichiers.relancer', $carte))->assertRedirect(route('admin.tableau'))
            ->assertSessionHas('succes');
        $this->assertFalse($carte->fresh()->fichiersEnEchec());
        $this->assertTrue($this->pousse($carte, 1));
        $this->assertDatabaseHas('audit_logs', ['action' => 'carte.fichiers_relances', 'acteur_id' => $agent->id, 'entite_id' => $carte->id]);
        $this->get(route('admin.tableau'))->assertOk()->assertDontSee('data-cartes-echec', false);
        // Plus en échec : nouvelle relance refusée.
        $this->from(route('admin.tableau'))->post(route('admin.cartes.fichiers.relancer', $carte))->assertSessionHas('erreur', 'La génération de cette carte n’est pas en échec.');

        // Génération réussie ensuite : fichiers servis.
        $this->chrome->panne = false;
        $this->generer($carte);
        $this->app['auth']->forgetGuards();
        $this->actingAs($carte->user)->get(route('cartes.fichier', [$carte, 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_plusieurs_ouvertures_de_ma_carte_pendant_une_generation_un_seul_job(): void
    {
        $carte = $this->carte();
        // Émission : un job, dont le verrou d'unicité (carte et version) reste pris tant qu'il n'est pas traité.
        Queue::assertPushedTimes(GenererFichiersCarte::class, 1);

        $this->actingAs($carte->user);
        foreach (range(1, 4) as $ouverture) {
            $this->get(route('espace.carte', ['type' => 'commercant']))->assertOk();
            $this->get(route('cartes.fichier', [$carte, 'png']))->assertSessionHas('erreur', __('carte.preparation'));
        }
        Queue::assertPushedTimes(GenererFichiersCarte::class, 1);
        $this->assertSame(0, $this->chrome->rendus);

        // Nouvelle version : un nouveau job, lui aussi unique.
        $carte->forceFill(['version' => 2])->save();
        $this->get(route('espace.carte', ['type' => 'commercant']))->assertOk();
        $this->get(route('espace.carte', ['type' => 'commercant']))->assertOk();
        Queue::assertPushedTimes(GenererFichiersCarte::class, 2);
        $this->assertTrue($this->pousse($carte, 2));
    }

    public function test_seul_le_titulaire_telecharge_les_fichiers_stockes(): void
    {
        $carte = $this->carte();
        $this->generer($carte);
        $autre = $this->carte();
        $this->generer($autre);
        $url = route('cartes.fichier', [$carte, 'pdf']);

        // Visiteur non connecté : renvoyé à la connexion, aucun fichier.
        $reponse = $this->get($url)->assertRedirect(route('connexion'));
        $this->assertStringNotContainsString('%PDF', (string) $reponse->getContent());
        // Adresse directe du fichier stocké : refusée (disque privé, servi seulement par lien signé, jamais émis pour les
        // cartes) ou introuvable (hors du dossier public).
        foreach (['/storage/cartes/fichiers/'.$carte->id.'/v1/carte.pdf', '/storage/app/private/cartes/fichiers/'.$carte->id.'/v1/recto.png', '/cartes/fichiers/'.$carte->id.'/v1/carte.pdf'] as $direct) {
            $reponse = $this->get($direct);
            $this->assertContains($reponse->getStatusCode(), [403, 404], $direct);
            $this->assertStringNotContainsString('%PDF', (string) $reponse->getContent());
        }
        $this->actingAs($carte->user)->get('/storage/cartes/fichiers/'.$carte->id.'/v1/carte.pdf')->assertForbidden();
        $this->app['auth']->forgetGuards();

        // Autre titulaire, en tapant l'adresse de la carte d'un autre (PDF et chaque face) : refusé.
        $this->actingAs($autre->user);
        foreach ([route('cartes.fichier', [$carte, 'pdf']), route('cartes.fichier', [$carte, 'png']), route('cartes.fichier', [$carte, 'png', 'face' => 'verso'])] as $adresse) {
            $this->get($adresse)->assertForbidden();
        }
        $this->get(route('cartes.fichier', [$autre, 'pdf']))->assertOk();
        $this->get(route('cartes.fichier', [999999, 'pdf']))->assertNotFound();

        // Back-office : ni l'agent de guichet, ni le SUPER_ADMIN, ni l'ADMIN ne téléchargent la carte d'un titulaire par
        // cette adresse ; l'agent de guichet imprime depuis le guichet (droit cartes.imprimer), le SUPER_ADMIN non.
        foreach ([Role::AgentGuichet, Role::SuperAdmin, Role::Admin] as $role) {
            $this->app['auth']->forgetGuards();
            $this->connecterBackoffice($role);
            $this->get($url)->assertForbidden();
            $impression = $this->post(route('admin.guichet.imprimer', $carte->jeton), ['piece_verifiee' => '1']);
            $role === Role::AgentGuichet ? $impression->assertOk()->assertHeader('Content-Type', 'application/pdf') : $impression->assertForbidden();
        }

        // Impression au guichet sans connexion au back-office : renvoyé à la connexion du back-office, aucun fichier.
        $this->app['auth']->forgetGuards();
        $this->post(route('admin.guichet.imprimer', $carte->jeton), ['piece_verifiee' => '1'])->assertRedirect(route('admin.connexion'));
        // Titulaire lui-même : carte révoquée, plus de fichier.
        $carte->forceFill(['statut' => StatutCarte::Revoquee, 'courante' => null])->save();
        $this->actingAs($carte->user)->get($url)->assertNotFound();
    }

    public function test_impression_au_guichet_sert_le_pdf_pret(): void
    {
        $carte = $this->carte();
        $this->generer($carte);
        $rendus = $this->chrome->rendus;
        $this->connecterBackoffice(Role::AgentGuichet);

        $pdf = $this->post(route('admin.guichet.imprimer', $carte->jeton), ['piece_verifiee' => '1'])->assertOk();
        $this->assertSame(app(FichiersCarte::class)->lire($carte, 'pdf'), $pdf->getContent());
        $this->assertSame($rendus, $this->chrome->rendus);
    }
}
