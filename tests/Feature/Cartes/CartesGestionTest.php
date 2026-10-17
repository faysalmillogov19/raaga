<?php

namespace Tests\Feature\Cartes;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutCarte;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutKyc;
use App\Enums\StatutReservationBoutique;
use App\Enums\TypeCarte;
use App\Models\Boutique;
use App\Models\Carte;
use App\Models\KycDocument;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Notifications\CarteEmise;
use App\Services\Boutiques\ReservationsBoutiques;
use App\Services\Cartes\Cartes;
use App\Support\ATraiter;
use App\Support\AValider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * Gestion des cartes : révocation manuelle (ADMIN avec motif, confirmée par le VALIDATEUR), réémission après une perte
 * (nouveau numéro, nouveau QR code), photo du personnel validée par l'ADMIN, priorité des dossiers d'identité des
 * commerçants attribués au tableau de bord de l'ADMIN.
 */
class CartesGestionTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerActeurs();
        $this->admin = User::factory()->role(Role::Admin)->create();
        Notification::fake();
    }

    private function citoyen(StatutKyc $kyc = StatutKyc::Valide): User
    {
        return User::factory()->role(Role::Citoyen)->create(['statut_kyc' => $kyc, 'nom' => 'OUEDRAOGO', 'prenoms' => 'Awa',
            'piece_expire_le' => now()->addYears(10)->toDateString(),
            'identite_validee' => $kyc === StatutKyc::Valide ? ['nom' => 'OUEDRAOGO', 'prenoms' => 'Awa'] : null]);
    }

    private function attribuer(User $citoyen, Boutique $boutique): ReservationBoutique
    {
        $campagne = Boutiques::campagneOuverte();
        $boutique->forceFill(['statut' => StatutBoutique::Reservee])->save();
        $r = ReservationBoutique::create(['reference' => 'BTQ-'.strtoupper(substr(md5((string) $boutique->id), 0, 8)), 'user_id' => $citoyen->id,
            'campagne_boutiques_id' => $campagne->id, 'boutique_id' => $boutique->id, 'caution' => 540_000, 'avance' => 135_000, 'mois_avance' => 3,
            'statut' => StatutReservationBoutique::Reservee, 'expire_le' => now()->subDay(), 'avance_payee_le' => now(), 'reference_avance' => 'PAY-AV']);
        app(ReservationsBoutiques::class)->attribuer($r, now()->toDateString(), $this->validateur);

        return $r->fresh();
    }

    public function test_revocation_manuelle_demandee_par_l_admin_et_confirmee_par_le_validateur(): void
    {
        $citoyen = $this->citoyen();
        Boutiques::campagneOuverte();
        $this->attribuer($citoyen, Boutiques::boutique('A', 1));
        $carte = Cartes::courante($citoyen, TypeCarte::Commercant);

        // Motif obligatoire ; le VALIDATEUR ne demande pas, l'ADMIN ne confirme pas.
        $this->en($this->admin)->post(route('admin.cartes.revocation', $carte), ['motif' => ''])->assertSessionHasErrors('motif');
        $this->en($this->validateur)->post(route('admin.cartes.revocation', $carte), ['motif' => 'Fraude constatée'])->assertForbidden();
        $this->en($this->admin)->post(route('admin.cartes.revocation', $carte), ['motif' => 'Prêt de la carte à un tiers'])
            ->assertRedirect(route('admin.guichet.titulaire', $citoyen))->assertSessionHas('succes');
        $this->assertDatabaseHas('audit_logs', ['action' => 'carte.revocation_demandee', 'acteur_id' => $this->admin->id, 'entite_id' => $carte->id]);

        // En attente : la carte reste valide ; la demande apparaît dans « À valider » du VALIDATEUR.
        $this->assertTrue($carte->fresh()->estValide());
        $file = collect(AValider::pour($this->validateur)['cartes'] ?? []);
        $this->assertSame(['revocation:'.$carte->id], $file->pluck('cle')->all());
        $this->en($this->admin)->get(route('admin.guichet.titulaire', $citoyen))->assertSee('data-revocation-en-attente', false)->assertSee('Prêt de la carte à un tiers');
        $this->en($this->admin)->post(route('admin.cartes.revocation.confirmer', $carte))->assertForbidden();

        // Confirmée depuis « À valider » : révoquée, refusée au scan immédiatement.
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['revocation:'.$carte->id], 'decision' => 'confirmer', 'mode' => 'ligne'])
            ->assertSessionHas('succes');
        $carte->refresh();
        $this->assertSame(StatutCarte::Revoquee, $carte->statut);
        $this->assertSame(Cartes::MOTIF_MANUEL, $carte->motif);
        $this->assertNull(Cartes::courante($citoyen, TypeCarte::Commercant));
        $this->app['auth']->forgetGuards();
        $this->get($carte->urlVerification())->assertSee('Carte non valide');

        // Plus d'émission automatique : une nouvelle attribution ne recrée pas de carte.
        $this->attribuer($citoyen, Boutiques::boutique('A', 2));
        $this->assertNull(Cartes::courante($citoyen, TypeCarte::Commercant));

        // Seule une réémission par l'ADMIN redonne une carte (nouveau numéro, nouveau QR code).
        $this->en($this->admin)->post(route('admin.cartes.reemettre', [$citoyen, 'commercant']))->assertSessionHas('succes');
        $nouvelle = Cartes::courante($citoyen, TypeCarte::Commercant);
        $this->assertNotNull($nouvelle);
        $this->assertNotSame($carte->numero, $nouvelle->numero);
        $this->assertCount(2, $nouvelle->contenu['emplacements']);
    }

    public function test_le_validateur_peut_rejeter_la_revocation(): void
    {
        $citoyen = $this->citoyen();
        Boutiques::campagneOuverte();
        $this->attribuer($citoyen, Boutiques::boutique('A', 1));
        $carte = Cartes::courante($citoyen, TypeCarte::Commercant);
        app(Cartes::class)->demanderRevocation($carte, 'Signalement à vérifier', $this->admin);

        $this->en($this->validateur)->post(route('admin.cartes.revocation.rejeter', $carte), ['motif' => ''])->assertSessionHasErrors('motif');
        $this->en($this->validateur)->post(route('admin.cartes.revocation.rejeter', $carte), ['motif' => 'Signalement infondé'])->assertSessionHas('succes');
        $carte->refresh();
        $this->assertSame(StatutCarte::Active, $carte->statut);
        $this->assertNull($carte->revocation_demandee_le);
        $this->assertSame([], AValider::pour($this->validateur)['cartes'] ?? []);
        $this->assertDatabaseHas('audit_logs', ['action' => 'carte.revocation_rejetee', 'entite_id' => $carte->id]);
    }

    public function test_reemission_apres_perte_nouveau_numero_et_nouveau_qr_code(): void
    {
        $citoyen = $this->citoyen();
        Boutiques::campagneOuverte();
        $this->attribuer($citoyen, Boutiques::boutique('A', 1));
        $ancienne = Cartes::courante($citoyen, TypeCarte::Commercant);

        $this->en($this->admin)->post(route('admin.cartes.reemettre', [$citoyen, 'commercant']))
            ->assertRedirect(route('admin.guichet.titulaire', $citoyen))->assertSessionHas('succes');
        $nouvelle = Cartes::courante($citoyen, TypeCarte::Commercant);

        $this->assertSame(StatutCarte::Revoquee, $ancienne->fresh()->statut);
        $this->assertSame('perte', $ancienne->fresh()->motif);
        $this->assertNotSame($ancienne->numero, $nouvelle->numero);
        $this->assertNotSame($ancienne->jeton, $nouvelle->jeton);
        $this->assertSame($ancienne->id, $nouvelle->remplace_carte_id);
        $this->assertSame(StatutCarte::Active, $nouvelle->statut);
        Notification::assertSentToTimes($citoyen, CarteEmise::class, 2);

        // L'ancien QR code est refusé, le nouveau accepté.
        $this->app['auth']->forgetGuards();
        $this->get($ancienne->urlVerification())->assertSee('Carte non valide');
        $this->get($nouvelle->urlVerification())->assertSee('Carte valide');

        // Le VALIDATEUR ne réémet pas ; aucune réémission sans carte.
        $this->en($this->validateur)->post(route('admin.cartes.reemettre', [$citoyen, 'commercant']))->assertForbidden();
        $this->en($this->admin)->post(route('admin.cartes.reemettre', [$citoyen, 'actionnaire']))->assertNotFound();
    }

    public function test_photo_du_personnel_validee_par_l_admin(): void
    {
        Storage::fake('local');
        $agent = User::factory()->role(Role::AgentGuichet)->create(['name' => 'Moussa KONÉ']);
        $carte = app(Cartes::class)->emettrePersonnel($agent, $this->superAdmin);
        $this->assertNull($carte->contenu['photo']);

        // Dépôt par le titulaire : en attente, la carte ne change pas.
        $this->en($agent)->post(route('admin.carte.photo'), ['photo' => UploadedFile::fake()->image('moi.jpg', 600, 750)])->assertSessionHas('succes');
        $photo = KycDocument::query()->where('user_id', $agent->id)->sole();
        $this->assertSame(StatutDocumentKyc::EnAttente, $photo->statut);
        $this->assertNull($carte->fresh()->contenu['photo']);
        $this->en($agent)->get(route('admin.carte'))->assertOk()->assertSee('Dernière photo : En attente');
        // Le titulaire n'a pas accès à la validation ; ni le VALIDATEUR.
        $this->en($agent)->post(route('admin.cartes.photos.valider', $photo))->assertForbidden();
        $this->en($this->validateur)->get(route('admin.cartes.photos'))->assertForbidden();

        // L'ADMIN valide : la carte porte la photo, nouvelle version.
        $this->en($this->admin)->get(route('admin.cartes.photos'))->assertOk()->assertSee('Moussa KONÉ')->assertSee(route('admin.kyc.piece', $photo), false);
        $this->en($this->admin)->post(route('admin.cartes.photos.valider', $photo))->assertSessionHas('succes');
        $carte->refresh();
        $this->assertSame($photo->id, $carte->contenu['photo']);
        $this->assertSame(2, $carte->version);
        $this->assertDatabaseHas('audit_logs', ['action' => 'carte.photo_validee', 'entite_id' => $photo->id]);

        // Nouvelle photo refusée : la carte garde la photo validée.
        $this->en($agent)->post(route('admin.carte.photo'), ['photo' => UploadedFile::fake()->image('autre.png', 600, 750)]);
        $refusee = KycDocument::query()->where('user_id', $agent->id)->latest('id')->first();
        $this->en($this->admin)->post(route('admin.cartes.photos.refuser', $refusee), ['motif' => 'Photo floue'])->assertSessionHas('succes');
        $this->assertSame(StatutDocumentKyc::Rejete, $refusee->fresh()->statut);
        $this->assertSame($photo->id, $carte->fresh()->contenu['photo']);
        $this->en($agent)->get(route('admin.carte'))->assertSee('Motif du refus : Photo floue');

        // Photo d'un citoyen : jamais par cet écran.
        $citoyen = $this->citoyen();
        $selfie = KycDocument::query()->forceCreate(['user_id' => $citoyen->id, 'type' => 'SELFIE', 'statut' => StatutDocumentKyc::EnAttente,
            'chemin_fichier' => 'kyc/x.jpg', 'mime' => 'image/jpeg', 'taille' => 10, 'nom_original' => 'x.jpg', 'empreinte_sha256' => str_repeat('a', 64)]);
        $this->en($this->admin)->post(route('admin.cartes.photos.valider', $selfie))->assertNotFound();
    }

    public function test_le_tableau_de_bord_de_l_admin_signale_d_abord_les_commercants_attribues(): void
    {
        // Le plus ancien dossier en attente : un citoyen sans boutique.
        $simple = $this->citoyen(StatutKyc::EnAttente);
        $simple->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();
        // Plus récent, mais commerçant déjà attribué : en tête.
        $commercant = $this->citoyen(StatutKyc::EnAttente);
        Boutiques::campagneOuverte();
        $this->attribuer($commercant, Boutiques::boutique('A', 1));

        $kyc = collect(ATraiter::pour($this->admin))->filter(fn ($e) => str_contains($e['element'], 'KYC'))->values();
        $this->assertCount(2, $kyc);
        $this->assertSame('Dossier KYC d’un commerçant attribué', $kyc[0]['element']);
        $this->assertStringContainsString('Prioritaire', $kyc[0]['statut']);
        $this->assertSame(route('admin.kyc.dossier', $commercant), $kyc[0]['url']);
        $this->assertSame('Dossier KYC', $kyc[1]['element']);
        // En tête de toute la liste « À traiter ».
        $this->assertSame('Dossier KYC d’un commerçant attribué', ATraiter::pour($this->admin)[0]['element']);
    }
}
