<?php

namespace Tests\Feature\Compte;

use App\Contracts\SmsProvider;
use App\Enums\FaceDocument;
use App\Enums\Role;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutKyc;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Kyc\VerificationIdentite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ConnexionBackoffice;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

class VerificationTest extends TestCase
{
    use ConnexionBackoffice;
    use RefreshDatabase;

    private FakeSmsProvider $sms;

    private User $citoyen;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->sms = new FakeSmsProvider;
        $this->app->instance(SmsProvider::class, $this->sms);
        $this->citoyen = User::factory()->role(Role::Citoyen)->create(['telephone' => '+22670123456', 'statut_kyc' => StatutKyc::NonSoumis]);
    }

    private function photo(string $nom = 'piece.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($nom, 800, 500);
    }

    private function fichierReel(string $nom, string $contenu): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'kyc');
        file_put_contents($chemin, $contenu);

        return new UploadedFile($chemin, $nom, null, null, true);
    }

    private function deposerCnib(): void
    {
        $this->actingAs($this->citoyen)->post('/espace/verification', [
            'type_piece' => 'CNIB', 'recto' => $this->photo('recto.jpg'), 'verso' => $this->photo('verso.png'), 'selfie' => $this->photo('selfie.jpg'),
        ])->assertRedirect(route('espace.verification'))->assertSessionHasNoErrors();
    }

    public function test_depot_complet_d_une_cnib(): void
    {
        $this->actingAs($this->citoyen)->get('/espace/verification')->assertOk()
            ->assertSee('Non soumise')->assertSee('capture="environment"', false)->assertSee('capture="user"', false)
            ->assertSee('accept="image/jpeg,image/png"', false);

        $this->deposerCnib();

        $documents = $this->citoyen->kycDocuments()->get();
        $this->assertCount(3, $documents);
        $this->assertEqualsCanonicalizing(
            ['CNIB-RECTO', 'CNIB-VERSO', 'SELFIE-'],
            $documents->map(fn (KycDocument $d) => $d->type->value.'-'.$d->face?->value)->all(),
        );
        foreach ($documents as $document) {
            $this->assertSame(StatutDocumentKyc::EnAttente, $document->statut);
            $this->assertStringStartsWith('kyc/'.$this->citoyen->id.'/', $document->chemin_fichier);
            Storage::disk('local')->assertExists($document->chemin_fichier);
            $this->assertFileDoesNotExist(public_path($document->chemin_fichier));
        }

        $this->assertSame(StatutKyc::EnAttente, $this->citoyen->fresh()->statut_kyc);
        $this->assertCount(1, $this->sms->envoyes);
        $this->assertStringContainsString('Vérification sous 48 heures', $this->sms->envoyes[0]['message']);

        $this->actingAs($this->citoyen)->get('/espace/verification')->assertOk()
            ->assertSee('En attente')->assertSee('Vérification sous 48 heures')->assertDontSee('Envoyer pour vérification');

        // Passeport : recto seul + selfie.
        $autre = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::NonSoumis]);
        $this->actingAs($autre)->post('/espace/verification', ['type_piece' => 'PASSEPORT', 'recto' => $this->photo(), 'selfie' => $this->photo()])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $autre->kycDocuments()->count());
    }

    public function test_refus_d_un_fichier_trop_lourd_ou_au_mauvais_format(): void
    {
        $this->actingAs($this->citoyen)->from('/espace/verification')->post('/espace/verification', [
            'type_piece' => 'CNIB',
            'recto' => UploadedFile::fake()->image('lourd.jpg')->size(6000),
            'verso' => UploadedFile::fake()->create('piece.pdf', 100, 'application/pdf'),
            // Vrai fichier : le type est lu dans le contenu (un SVG renommé en .png est refusé).
            'selfie' => $this->fichierReel('selfie.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ])->assertRedirect('/espace/verification')
            ->assertSessionHasErrors([
                'recto' => 'Fichier trop lourd : 5 Mo au maximum.',
                'verso' => 'Format refusé : envoyez une photo JPG ou PNG.',
                'selfie' => 'Format refusé : envoyez une photo JPG ou PNG.',
            ]);

        // Pièce manquante : verso exigé pour une CNIB.
        $this->actingAs($this->citoyen)->post('/espace/verification', ['type_piece' => 'CNIB', 'recto' => $this->photo(), 'selfie' => $this->photo()])
            ->assertSessionHasErrors(['verso' => 'Ajoutez cette photo.']);

        $this->assertSame(0, KycDocument::count());
        $this->assertSame(StatutKyc::NonSoumis, $this->citoyen->fresh()->statut_kyc);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], $this->sms->envoyes);
    }

    public function test_les_fichiers_ne_sont_servis_qu_au_titulaire_a_l_admin_et_au_validateur(): void
    {
        $this->deposerCnib();
        $document = $this->citoyen->kycDocuments()->firstOrFail();

        $this->actingAs($this->citoyen)->get(route('espace.verification.fichier', $document))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');

        $autre = User::factory()->role(Role::Citoyen)->create();
        $this->actingAs($autre)->get(route('espace.verification.fichier', $document))->assertForbidden();

        // ADMIN connecté au back-office : la pièce lui est servie au back-office (vérification d'identité), jamais par l'espace du citoyen.
        $this->app['auth']->forgetGuards();
        $this->connecterBackoffice(Role::Admin);
        $this->get(route('espace.verification.fichier', $document))->assertForbidden();
        $this->get(route('admin.kyc.piece', $document))->assertOk();

        // SUPER_ADMIN : refusé.
        $this->app['auth']->forgetGuards();
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->get(route('admin.kyc.piece', $document))->assertForbidden();

        // VALIDATEUR connecté au back-office : autorisé, consultation journalisée.
        $this->app['auth']->forgetGuards();
        $this->connecterBackoffice(Role::Validateur);
        $this->get(route('admin.kyc.piece', $document))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'kyc.piece_consultee', 'entite_id' => $document->id]);

        // Aucun accès direct au disque privé par une URL publique.
        $this->app['auth']->forgetGuards();
        $this->assertContains($this->get('/storage/'.$document->chemin_fichier)->getStatusCode(), [403, 404]);
        $this->get('/'.$document->chemin_fichier)->assertNotFound();
    }

    public function test_reprise_apres_rejet_seule_la_piece_rejetee_est_renvoyee(): void
    {
        $this->deposerCnib();
        $service = app(VerificationIdentite::class);
        $validateur = User::factory()->role(Role::Validateur)->create();
        $dossier = $service->dossier($this->citoyen->fresh());

        // Seul un VALIDATEUR décide.
        $this->assertThrows(fn () => $service->decider($dossier['recto'], StatutDocumentKyc::Valide, null, User::factory()->role(Role::Admin)->create()), AuthorizationException::class);

        $service->decider($dossier['recto'], StatutDocumentKyc::Valide, null, $validateur);
        $service->decider($dossier['selfie'], StatutDocumentKyc::Valide, null, $validateur);
        $service->decider($dossier['verso'], StatutDocumentKyc::Rejete, 'Photo floue, texte illisible.', $validateur);

        $this->assertSame(StatutKyc::Rejete, $this->citoyen->fresh()->statut_kyc);
        $this->assertStringContainsString('refusée', end($this->sms->envoyes)['message']);

        $this->actingAs($this->citoyen->fresh())->get('/espace/verification')->assertOk()
            ->assertSee('Rejetée')->assertSee('Motif : Photo floue, texte illisible.')
            ->assertSee('Renvoyer les pièces refusées')
            ->assertSee('name="verso"', false)->assertDontSee('name="recto"', false)->assertDontSee('name="selfie"', false);

        $this->actingAs($this->citoyen->fresh())->post('/espace/verification', ['verso' => $this->photo('verso2.jpg')])->assertSessionHasNoErrors();

        $this->assertSame(4, $this->citoyen->kycDocuments()->count());
        $this->assertSame(StatutKyc::EnAttente, $this->citoyen->fresh()->statut_kyc);
        $nouveau = $service->dossier($this->citoyen->fresh())['verso'];
        $this->assertSame(StatutDocumentKyc::EnAttente, $nouveau->statut);
        $this->assertSame(FaceDocument::Verso, $nouveau->face);

        $service->decider($nouveau, StatutDocumentKyc::Valide, null, $validateur);
        $this->assertSame(StatutKyc::Valide, $this->citoyen->fresh()->statut_kyc);
        $this->assertStringContainsString('identité est vérifiée', end($this->sms->envoyes)['message']);
    }
}
