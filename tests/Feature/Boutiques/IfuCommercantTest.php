<?php

namespace Tests\Feature\Boutiques;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutCarte;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutKyc;
use App\Enums\StatutReservationBoutique;
use App\Enums\TypeCarte;
use App\Enums\TypeDocumentKyc;
use App\Models\AuditLog;
use App\Models\Boutique;
use App\Models\Carte;
use App\Models\KycDocument;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Services\Boutiques\ReservationsBoutiques;
use App\Services\Cartes\Cartes;
use App\Services\Cartes\RenduCarte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\Support\DossierKyc;
use Tests\Support\ReglagesCarteComplets;
use Tests\TestCase;

/**
 * IFU (comité restreint du 03/11/2026) : demandé avec son attestation à la première réservation d'une personne physique
 * (saisie normalisée, unique, journalisée), attestation vérifiée par le VALIDATEUR, corrigeable avec une attestation
 * refusée ; numéro de la carte de commerçant, cherché au guichet, affiché au dossier et dans le profil (lecture seule) ;
 * plus d'identifiant fiscal Raaga ; rattrapage des cartes déjà émises.
 */
class IfuCommercantTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase, ReglagesCarteComplets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerActeurs();
        Notification::fake();
        Boutiques::campagneOuverte();
    }

    private function citoyen(array $attributs = []): User
    {
        static $n = 0;
        $n++;

        $user = User::factory()->role(Role::Citoyen)->create(['telephone' => '+2267200'.sprintf('%04d', $n), 'statut_kyc' => StatutKyc::Valide, 'nom' => 'OUEDRAOGO', 'prenoms' => 'Awa',
            'piece_expire_le' => now()->addYears(10)->toDateString(), 'identite_validee' => ['nom' => 'OUEDRAOGO', 'prenoms' => 'Awa'], ...$attributs]);
        // IFU donné : attestation validée (formalisé ; sans elle, la carte reste en attente depuis le 05/11/2026).
        if (filled($user->ifu)) {
            Boutiques::ifuVerifie($user);
        }

        return $user;
    }

    /** Réservation réservée, avance payée, puis attribuée. */
    private function attribuer(User $citoyen, Boutique $boutique): void
    {
        $campagne = Boutiques::campagneOuverte();
        $boutique->forceFill(['statut' => StatutBoutique::Reservee])->save();
        $reservation = ReservationBoutique::create(['reference' => 'BTQ-'.strtoupper(substr(md5((string) $boutique->id), 0, 8)), 'user_id' => $citoyen->id,
            'campagne_boutiques_id' => $campagne->id, 'boutique_id' => $boutique->id, 'caution' => 540_000, 'avance' => 135_000, 'mois_avance' => 3,
            'statut' => StatutReservationBoutique::Reservee, 'expire_le' => now()->subDay(), 'avance_payee_le' => now(), 'reference_avance' => 'PAY-AV']);
        app(ReservationsBoutiques::class)->attribuer($reservation, now()->toDateString(), $this->validateur);
    }

    /** Candidature à la boutique : pièce d'identité, données de la carte, et ce qui est donné en plus. */
    private function candidater(User $citoyen, Boutique $boutique, array $donnees = []): TestResponse
    {
        return $this->actingAs($citoyen)->post(route('boutiques.reserver', $boutique->code), [
            'type_piece' => 'CNIB', 'numero_piece' => Boutiques::nip('B'.sprintf('%07d', $citoyen->id)), 'piece' => UploadedFile::fake()->image('piece.jpg'),
            ...Boutiques::donneesCommercant(), ...$donnees,
        ]);
    }

    public function test_premiere_reservation_ifu_et_attestation_obligatoires_puis_verifiees(): void
    {
        Storage::fake('local');
        $citoyen = $this->citoyen(['ifu' => null]);
        $boutique = Boutiques::boutique('A', 1);

        $this->actingAs($citoyen)->get(route('boutiques.boutique', $boutique->code))->assertOk()
            ->assertSee('data-ifu-reservation', false)->assertSee('Votre numéro IFU')->assertSee('Numéro IFU')->assertDontSee('Numéro Raaga (IFU)')->assertSee('name="ifu"', false)->assertSee('name="attestation_ifu"', false);

        // IFU sans attestation, IFU déjà pris : rien n'est enregistré. (Sans IFU du tout : réservation possible avec un
        // récépissé de préinscription depuis le 05/11/2026, voir FormalisationTest.)
        $this->candidater($citoyen, $boutique, ['ifu' => '00004742U'])->assertSessionHasErrors('attestation_ifu');
        $this->citoyen(['ifu' => '00009999Z']);
        $this->candidater($citoyen, $boutique, ['ifu' => '0000 9999 z', 'attestation_ifu' => UploadedFile::fake()->create('ifu.pdf', 50, 'application/pdf')])
            ->assertSessionHasErrors(['ifu' => 'Ce numéro IFU est déjà utilisé par un autre compte.']);
        $this->assertSame(0, ReservationBoutique::query()->count());

        // Saisie normalisée (espaces, tiret, mot « IFU ») ; attestation au dossier, à vérifier par le VALIDATEUR.
        $this->candidater($citoyen, $boutique, ['ifu' => 'IFU 0000-4742 u', 'attestation_ifu' => UploadedFile::fake()->create('ifu.pdf', 50, 'application/pdf')])
            ->assertSessionHasNoErrors();
        $citoyen->refresh();
        $this->assertSame('00004742U', $citoyen->ifu);
        $this->assertSame(StatutKyc::EnAttente, $citoyen->statut_kyc);
        $attestation = KycDocument::query()->where('user_id', $citoyen->id)->where('type', TypeDocumentKyc::Ifu)->sole();
        $this->assertSame(['00004742U', StatutDocumentKyc::EnAttente], [$attestation->numero, $attestation->statut]);
        $journal = AuditLog::query()->where('action', 'ifu.modifie')->where('entite_id', (string) $citoyen->id)->sole();
        $this->assertSame([['ifu' => null], '00004742U'], [$journal->ancienne_valeur, $journal->nouvelle_valeur['ifu']]);
        $this->en(User::factory()->role(Role::Admin)->create())->get(route('admin.kyc.dossier', $citoyen))->assertOk()
            ->assertSee('Attestation IFU')->assertSee('00004742U');

        // Réservation suivante : l'IFU n'est plus demandé.
        $this->app['auth']->forgetGuards();
        $this->actingAs($citoyen)->get(route('boutiques.boutique', Boutiques::boutique('A', 2)->code))->assertOk()
            ->assertSee('data-formulaire-reservation', false)->assertDontSee('data-ifu-reservation', false);
    }

    public function test_ifu_corrige_avec_une_attestation_refusee_et_journalise(): void
    {
        Storage::fake('local');
        $citoyen = $this->citoyen(['ifu' => '00012345A', 'statut_kyc' => StatutKyc::Rejete]);
        DossierKyc::complet($citoyen);
        KycDocument::create(['user_id' => $citoyen->id, 'type' => TypeDocumentKyc::Ifu, 'chemin_fichier' => 'kyc/ifu.pdf', 'mime' => 'application/pdf',
            'taille' => 1000, 'empreinte_sha256' => str_repeat('0', 64), 'statut' => StatutDocumentKyc::Rejete, 'commentaire' => 'Numéro différent',
            'televerse_par' => $citoyen->id, 'numero' => '00012345A']);

        $this->actingAs($citoyen)->get(route('espace.verification'))->assertOk()->assertSee('name="numero_ifu"', false)->assertSee('value="00012345A"', false);
        $this->actingAs($citoyen)->post(route('espace.verification.store'), ['numero_ifu' => '0005 4321 b', 'ifu' => UploadedFile::fake()->create('ifu.pdf', 50, 'application/pdf')])
            ->assertSessionHasNoErrors();

        $this->assertSame('00054321B', $citoyen->fresh()->ifu);
        $this->assertSame('00054321B', KycDocument::query()->where('user_id', $citoyen->id)->where('type', TypeDocumentKyc::Ifu)->latest('id')->value('numero'));
        $journal = AuditLog::query()->where('action', 'ifu.modifie')->sole();
        $this->assertSame([['ifu' => '00012345A'], '00054321B', 'verification_identite'],
            [$journal->ancienne_valeur, $journal->nouvelle_valeur['ifu'], $journal->nouvelle_valeur['origine']]);
    }

    public function test_l_ifu_est_le_numero_de_la_carte_de_commercant_sans_identifiant_fiscal_raaga(): void
    {
        $this->completerReglagesCarte();
        $citoyen = $this->citoyen(['ifu' => '00012345A']);
        $this->attribuer($citoyen, Boutiques::boutique('A', 1));

        $carte = Cartes::courante($citoyen, TypeCarte::Commercant);
        $this->assertSame('00012345A', $carte->numero);
        $this->assertArrayNotHasKey('identifiant_fiscal', $carte->contenu);
        $this->assertFalse(Schema::hasColumn('users', 'identifiant_fiscal_raaga'));
        $this->assertFalse(Schema::hasColumn('users', 'numero_registre_raaga'));
        $html = view('cartes._faces', ['d' => app(RenduCarte::class)->donnees($carte)])->render();
        $this->assertStringContainsString('00012345A', $html);
        $this->assertStringNotContainsString('Identifiant fiscal', $html);

        // Le numéro Raaga (IFU) est le seul numéro du commerçant : sans IFU, carte en attente, sans numéro, jamais de RG-C.
        $sansIfu = $this->citoyen(['ifu' => null]);
        $this->attribuer($sansIfu, Boutiques::boutique('A', 2));
        $enAttente = Cartes::courante($sansIfu, TypeCarte::Commercant);
        $this->assertSame([StatutCarte::EnAttente, null], [$enAttente->statut, $enAttente->numero]);
        // IFU donné ensuite : la carte reste en attente tant que l'attestation n'est pas validée (05/11/2026) ; validée, elle
        // prend ce numéro et devient active.
        $sansIfu->forceFill(['ifu' => '00054321B'])->save();
        $this->assertSame([StatutCarte::EnAttente, null], [Cartes::courante($sansIfu, TypeCarte::Commercant)->statut, Cartes::courante($sansIfu, TypeCarte::Commercant)->numero]);
        Boutiques::ifuVerifie($sansIfu->fresh());
        $active = app(Cartes::class)->synchroniser($sansIfu->fresh(), TypeCarte::Commercant);
        $this->assertSame([StatutCarte::Active, '00054321B'], [$active->statut, $active->numero]);
        $this->assertStringContainsString('Numéro Raaga', $html);
    }

    public function test_guichet_dossier_et_profil_du_commercant(): void
    {
        $citoyen = $this->citoyen(['ifu' => '00012345A']);
        $this->attribuer($citoyen, Boutiques::boutique('A', 1));
        $carte = Cartes::courante($citoyen, TypeCarte::Commercant);
        $agent = User::factory()->role(Role::AgentGuichet)->create();

        // Recherche par l'IFU (espaces et minuscules acceptés) : vérification de la carte courante.
        foreach (['00012345A', '0001 2345 a'] as $saisie) {
            $this->en($agent)->post(route('admin.guichet.rechercher'), ['carte' => $saisie])->assertRedirect(route('admin.guichet.carte', $carte->jeton));
        }
        $this->en($agent)->from(route('admin.guichet'))->post(route('admin.guichet.rechercher'), ['carte' => 'RG-F-'.sprintf('%06d', $citoyen->id)])
            ->assertSessionHasErrors('carte');
        $this->en($agent)->get(route('admin.guichet.titulaire', $citoyen))->assertOk()->assertSee('Numéro Raaga')->assertDontSee('N° IFU')->assertSee('00012345A')
            ->assertDontSee('Identifiant fiscal Raaga');

        $admin = User::factory()->role(Role::Admin)->create();
        $this->en($admin)->get(route('admin.kyc.dossier', $citoyen))->assertOk()->assertSee('data-ifu', false)->assertSee('00012345A');

        $this->app['auth']->forgetGuards();
        $this->actingAs($citoyen)->get('/espace?onglet=profil')->assertOk()->assertSee('Numéro Raaga')->assertDontSee('Numéro IFU')->assertSee('00012345A')
            ->assertDontSee('Identifiant fiscal Raaga');
    }

    public function test_ifu_en_lecture_seule_dans_le_profil(): void
    {
        $citoyen = $this->citoyen(['ifu' => '00012345A', 'date_naissance' => '1990-05-12', 'pays_residence' => 'FR']);
        $this->attribuer($citoyen, Boutiques::boutique('A', 1));

        $this->actingAs($citoyen)->get('/espace/profil')->assertOk()->assertSee('data-champ-ifu', false)->assertSee('00012345A')
            ->assertDontSee('name="ifu"', false)->assertSee('il se corrige avec l’attestation', false);
        $this->actingAs($citoyen)->post('/espace/profil', ['nom' => 'OUEDRAOGO', 'prenoms' => 'Awa', 'date_naissance' => '1990-05-12',
            'ifu' => '99999999Z', 'pays_residence' => 'FR', 'commune_id' => '', 'email' => ''])->assertSessionHasNoErrors();
        $this->assertSame('00012345A', $citoyen->fresh()->ifu);
        $this->assertSame('00012345A', Cartes::courante($citoyen, TypeCarte::Commercant)->numero);
    }

    public function test_rattrapage_des_cartes_de_commercant_deja_emises(): void
    {
        $citoyen = $this->citoyen(['ifu' => '00012345A']);
        $this->attribuer($citoyen, Boutiques::boutique('A', 1));
        // Carte émise avant la règle : numéro RG-C.
        $carte = Cartes::courante($citoyen, TypeCarte::Commercant);
        $carte->forceFill(['numero' => sprintf('RG-C-%06d', $carte->id)])->save();

        $this->artisan('raaga:numeros-cartes-commercants')->expectsOutput('Cartes de commerçant mises à jour : 1.')->assertSuccessful();
        $mise = Carte::query()->find($carte->id);
        $this->assertSame(['00012345A', $carte->version + 1], [$mise->numero, $mise->version]);
        $this->artisan('raaga:numeros-cartes-commercants')->expectsOutput('Cartes de commerçant mises à jour : 0.')->assertSuccessful();
    }
}
