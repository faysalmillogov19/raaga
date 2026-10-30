<?php

namespace Tests\Feature\Compte;

use App\Contracts\SmsProvider;
use App\Enums\Role;
use App\Enums\StatutCarte;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutKyc;
use App\Enums\TypeCarte;
use App\Enums\TypeDocumentKyc;
use App\Enums\TypeTitulaire;
use App\Models\Carte;
use App\Models\KycDocument;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Models\VerificationKyc;
use App\Notifications\PiecesAMettreAJour;
use App\Services\Boutiques\ReservationsBoutiques;
use App\Services\Cartes\Cartes;
use App\Services\Cartes\RenduCarte;
use App\Services\Kyc\DossiersIdentite;
use App\Support\ATraiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\Support\DossierKyc;
use Tests\Support\FakeSmsProvider;
use Tests\Support\ReglagesCarteComplets;
use Tests\TestCase;

/**
 * Pièces des personnes physiques (CNIB, CIB-AES ou passeport, numéro et date d'expiration obligatoires) et morales
 * (RCCM, IFU, pièce et selfie du représentant légal, justificatif de pouvoir) ; dossier conforme seulement s'il est
 * complet ; limite de réservation sur le numéro de pièce ou sur l'IFU ; carte d'une personne morale ; dossiers anciens
 * marqués « pièces à mettre à jour ».
 */
class PiecesTitulairesTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase, ReglagesCarteComplets;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->app->instance(SmsProvider::class, new FakeSmsProvider);
        $this->creerActeurs();
        $this->admin = User::factory()->role(Role::Admin)->create();
    }

    private function citoyen(TypeTitulaire $type = TypeTitulaire::Physique, array $attributs = []): User
    {
        static $n = 0;
        $n++;

        return User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::NonSoumis, 'telephone' => '+2267100'.sprintf('%04d', $n),
            'nom' => 'KABORE', 'prenoms' => 'Issa', 'type_titulaire' => $type,
            'raison_sociale' => $type === TypeTitulaire::Morale ? 'Société Kaboré et Fils' : null, 'forme_morale' => $type === TypeTitulaire::Morale ? 'ENTREPRISE' : null,
            ...$attributs]);
    }

    private function image(string $nom = 'piece.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($nom, 800, 500);
    }

    private function pdf(string $nom): UploadedFile
    {
        return UploadedFile::fake()->create($nom, 200, 'application/pdf');
    }

    public function test_personne_physique_cnib_cib_aes_ou_passeport_avec_numero_et_expiration(): void
    {
        // CIB-AES : recto et verso, numéro et date d'expiration obligatoires.
        $cib = $this->citoyen();
        $this->actingAs($cib)->get(route('espace.verification'))->assertOk()
            ->assertSee('CNIB (carte nationale d’identité burkinabè)', false)->assertSee('CIB-AES (carte d’identité biométrique AES)', false)->assertSee('Passeport')
            ->assertSee('name="numero_piece"', false)->assertSee('name="piece_expire_le"', false)->assertDontSee('name="rccm"', false);
        $this->actingAs($cib)->post(route('espace.verification.store'), ['type_piece' => 'CIB_AES', 'recto' => $this->image(), 'selfie' => $this->image()])
            ->assertSessionHasErrors('verso');
        $this->actingAs($cib)->post(route('espace.verification.store'), ['type_piece' => 'CIB_AES', 'recto' => $this->image(), 'verso' => $this->image(), 'selfie' => $this->image()])
            ->assertSessionHasErrors(['numero_piece', 'piece_expire_le']);
        $this->actingAs($cib)->post(route('espace.verification.store'), ['type_piece' => 'CIB_AES', 'recto' => $this->image(), 'verso' => $this->image(), 'selfie' => $this->image(),
            'numero_piece' => 'AES-0012 3456', 'piece_expire_le' => now()->subDay()->toDateString()])->assertSessionHasErrors('piece_expire_le');
        $this->actingAs($cib)->post(route('espace.verification.store'), ['type_piece' => 'CIB_AES', 'recto' => $this->image(), 'verso' => $this->image(), 'selfie' => $this->image(),
            'numero_piece' => 'AES-0012 3456', 'piece_expire_le' => now()->addYears(3)->toDateString()])->assertSessionHasNoErrors();
        $cib->refresh();
        $this->assertSame(StatutKyc::EnAttente, $cib->statut_kyc);
        $this->assertSame('AES00123456', $cib->numero_piece);
        $recto = $cib->kycDocuments()->where('type', TypeDocumentKyc::CibAes)->where('face', 'RECTO')->sole();
        $this->assertSame(['AES00123456', now()->addYears(3)->toDateString()], [$recto->numero, $recto->expire_le->toDateString()]);

        // Passeport : une page, sans verso.
        $passeport = $this->citoyen();
        $this->actingAs($passeport)->post(route('espace.verification.store'), ['type_piece' => 'PASSEPORT', 'recto' => $this->image(), 'selfie' => $this->image(),
            'numero_piece' => 'BF1234567', 'piece_expire_le' => now()->addYears(4)->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(2, $passeport->kycDocuments()->count());
        // Une personne physique ne dépose ni RCCM ni IFU : ils sont ignorés.
        $this->assertSame(0, KycDocument::query()->whereIn('type', [TypeDocumentKyc::Rccm, TypeDocumentKyc::Ifu])->count());
    }

    public function test_personne_morale_rccm_ifu_representant_et_pouvoir(): void
    {
        $morale = $this->citoyen(TypeTitulaire::Morale);
        $this->actingAs($morale)->get(route('espace.verification'))->assertOk()
            ->assertSee('Société Kaboré et Fils')->assertSee('Pièce d’identité du représentant légal', false)
            ->assertSee('name="rccm"', false)->assertSee('name="numero_rccm"', false)->assertSee('name="ifu"', false)
            ->assertSee('name="numero_ifu"', false)->assertSee('name="pouvoir"', false);

        $complet = ['type_piece' => 'CNIB', 'recto' => $this->image(), 'verso' => $this->image(), 'selfie' => $this->image(),
            'numero_piece' => 'B7654321', 'piece_expire_le' => now()->addYears(2)->toDateString(),
            'rccm' => $this->pdf('rccm.pdf'), 'numero_rccm' => 'BF-OUA-2021-B-777', 'ifu' => $this->pdf('ifu.pdf'), 'numero_ifu' => '00098765 A',
            'pouvoir' => $this->pdf('pv.pdf')];
        // Pièce manquante (justificatif de pouvoir), numéros manquants : refusé.
        $this->actingAs($morale)->post(route('espace.verification.store'), array_diff_key($complet, ['pouvoir' => 1]))->assertSessionHasErrors('pouvoir');
        $this->actingAs($morale)->post(route('espace.verification.store'), array_diff_key($complet, ['numero_rccm' => 1, 'numero_ifu' => 1]))
            ->assertSessionHasErrors(['numero_rccm', 'numero_ifu']);
        $this->assertSame(0, $morale->kycDocuments()->count());

        $this->actingAs($morale)->post(route('espace.verification.store'), $complet)->assertSessionHasNoErrors();
        $morale->refresh();
        $this->assertEqualsCanonicalizing([TypeDocumentKyc::Cnib, TypeDocumentKyc::Cnib, TypeDocumentKyc::Selfie, TypeDocumentKyc::Rccm, TypeDocumentKyc::Ifu, TypeDocumentKyc::JustifPouvoir],
            $morale->kycDocuments()->pluck('type')->all());
        $this->assertSame(['00098765A', 'BF-OUA-2021-B-777'], [$morale->ifu, $morale->rccm]);

        // Dossier complet : l'ADMIN le voit (raison sociale, numéros) et le marque conforme.
        $this->en($this->admin)->get(route('admin.kyc.dossier', $morale))->assertOk()->assertSee('Société Kaboré et Fils')
            ->assertSee('00098765A')->assertSee('BF-OUA-2021-B-777')->assertSee('Justificatif de pouvoir')->assertDontSee('data-dossier-incomplet', false);
        $this->en($this->admin)->post(route('admin.kyc.conforme', $morale), ['piece_expire_le' => now()->addYears(2)->toDateString()])->assertSessionHas('succes');
        $this->assertSame(StatutKyc::Valide, $morale->fresh()->statut_kyc);
        $this->assertSame('Société Kaboré et Fils', $morale->fresh()->identite_validee['raison_sociale']);
    }

    public function test_dossier_de_personne_morale_incomplet_refuse(): void
    {
        $morale = $this->citoyen(TypeTitulaire::Morale, ['statut_kyc' => StatutKyc::EnAttente]);
        DossierKyc::complet($morale);
        KycDocument::query()->where('user_id', $morale->id)->where('type', TypeDocumentKyc::JustifPouvoir)->delete();

        // Ni bouton « conforme » ni décision : le dossier signale la pièce manquante.
        $this->en($this->admin)->get(route('admin.kyc.dossier', $morale))->assertOk()->assertSee('data-dossier-incomplet', false)
            ->assertSee('justificatif de pouvoir')->assertDontSee(route('admin.kyc.conforme', $morale), false);
        $this->en($this->admin)->post(route('admin.kyc.conforme', $morale), ['piece_expire_le' => now()->addYear()->toDateString()])->assertSessionHasErrors('pieces');
        $this->assertThrows(fn () => app(DossiersIdentite::class)->conforme($morale, $this->admin, null, now()->addYear()->toDateString()), ValidationException::class);
        $this->assertSame(StatutKyc::EnAttente, $morale->fresh()->statut_kyc);

        // Le titulaire voit ce qui manque et peut compléter.
        $this->actingAs($morale)->get(route('espace.verification'))->assertSee('data-a-completer', false)->assertSee('name="pouvoir"', false)
            ->assertDontSee('name="rccm"', false);
        $this->actingAs($morale)->post(route('espace.verification.store'), ['pouvoir' => $this->pdf('statuts.pdf')])->assertSessionHasNoErrors();
        $this->en($this->admin)->post(route('admin.kyc.conforme', $morale), ['piece_expire_le' => now()->addYear()->toDateString()])->assertSessionHas('succes');
    }

    public function test_limite_de_reservation_sur_le_numero_de_piece_ou_sur_l_ifu(): void
    {
        $campagne = Boutiques::campagneOuverte();
        // Personne physique : numéro de pièce (CIB-AES acceptée) ; même pièce sur un autre compte : pas retenue (liste d'attente).
        Boutiques::reserver($this, $this->citoyen(), Boutiques::boutique('A', 1), 'AES 001', 'CIB_AES')->assertSessionHasNoErrors();
        $meme = $this->citoyen();
        Boutiques::reserver($this, $meme, Boutiques::boutique('A', 2), 'aes-001', 'CIB_AES')->assertSessionHasNoErrors();
        $this->assertSame(0, ReservationBoutique::query()->where('user_id', $meme->id)->count());
        // Une personne physique ne réserve pas avec un IFU.
        Boutiques::reserver($this, $this->citoyen(), Boutiques::boutique('A', 2), '00012345X', 'IFU')->assertSessionHasErrors('type_piece');

        // Personne morale : IFU seulement ; même IFU sur un autre compte refusé, autre IFU accepté.
        $morale = $this->citoyen(TypeTitulaire::Morale);
        $this->actingAs($morale)->get(route('boutiques.boutique', Boutiques::boutique('A', 2)->code))->assertOk()
            ->assertSee('IFU (personne morale)', false)->assertDontSee('value="CNIB"', false);
        Boutiques::reserver($this, $morale, Boutiques::boutique('A', 2), 'B1234567', 'CNIB')->assertSessionHasErrors('type_piece');
        Boutiques::reserver($this, $morale, Boutiques::boutique('A', 2), '0001-2345 X', 'IFU')->assertSessionHasNoErrors();
        $this->assertSame('00012345X', $morale->fresh()->ifu);
        $this->assertSame(['type_piece' => 'IFU', 'numero_piece' => '00012345X'], ReservationBoutique::query()->where('user_id', $morale->id)->sole()->only(['type_piece', 'numero_piece']));
        $memeIfu = $this->citoyen(TypeTitulaire::Morale);
        Boutiques::reserver($this, $memeIfu, Boutiques::boutique('A', 3), '00012345x', 'IFU')->assertSessionHasNoErrors();
        $this->assertSame(0, ReservationBoutique::query()->where('user_id', $memeIfu->id)->count());
        Boutiques::reserver($this, $this->citoyen(TypeTitulaire::Morale), Boutiques::boutique('A', 3), '00099999Z', 'IFU')->assertSessionHasNoErrors();
        $this->assertSame(3, ReservationBoutique::query()->where('campagne_boutiques_id', $campagne->id)->count());
    }

    public function test_carte_d_une_personne_morale_raison_sociale_et_representant(): void
    {
        // Réglages de la carte au complet (avant le dossier d'identité : le disque privé simulé est réinitialisé).
        $this->completerReglagesCarte();
        $morale = $this->citoyen(TypeTitulaire::Morale, ['statut_kyc' => StatutKyc::EnAttente]);
        DossierKyc::complet($morale);
        app(DossiersIdentite::class)->conforme($morale, $this->admin, null, now()->addYears(3)->toDateString());
        Boutiques::campagneOuverte();
        $r = ReservationBoutique::create(['reference' => 'BTQ-MORALE01', 'user_id' => $morale->id, 'campagne_boutiques_id' => Boutiques::campagneOuverte()->id,
            'boutique_id' => Boutiques::boutique('A', 1)->id, 'caution' => 540_000, 'avance' => 135_000, 'mois_avance' => 3, 'statut' => 'RESERVEE',
            'expire_le' => now()->subDay(), 'avance_payee_le' => now(), 'reference_avance' => 'PAY-AV']);
        app(ReservationsBoutiques::class)->attribuer($r, now()->toDateString(), $this->validateur);

        $carte = Cartes::courante($morale, TypeCarte::Commercant);
        $this->assertSame(StatutCarte::Active, $carte->statut);
        $this->assertSame('Société Kaboré et Fils', $carte->contenu['raison_sociale']);
        $this->assertSame('Issa KABORE', $carte->contenu['nom']);
        $this->actingAs($morale)->get(route('espace.carte'))->assertOk()->assertSee('data-raison-sociale', false)
            ->assertSee('Société Kaboré et Fils')->assertSee('Représentant légal :', false)->assertSee('Issa KABORE');
        // Recto : raison sociale, puis le représentant légal et sa date de naissance.
        $donnees = app(RenduCarte::class)->donnees($carte);
        $this->assertSame(['Société Kaboré et Fils', 'Issa KABORE'], [$donnees['titulaire'], $donnees['representant']]);
        $this->assertSame($morale->date_naissance?->format('d/m/Y'), $donnees['ne_le']);
        $this->exigerChrome();
        $this->assertNotFalse(imagecreatefromstring(app(RenduCarte::class)->png($carte)));

        // Au guichet : raison sociale en titre, puis le représentant.
        $this->en(User::factory()->role(Role::AgentGuichet)->create())->get(route('admin.guichet.carte', $carte->jeton))->assertOk()
            ->assertSee('Société Kaboré et Fils')->assertSee('data-representant', false);
    }

    public function test_type_de_titulaire_choisi_a_l_inscription_et_change_seulement_par_le_kyc(): void
    {
        $valide = $this->citoyen(TypeTitulaire::Physique, ['statut_kyc' => StatutKyc::EnAttente]);
        DossierKyc::complet($valide);
        app(DossiersIdentite::class)->conforme($valide, $this->admin, null, now()->addYears(3)->toDateString());

        // Pendant l'examen d'un dossier : impossible.
        $enExamen = $this->citoyen(TypeTitulaire::Physique, ['statut_kyc' => StatutKyc::EnAttente]);
        $this->actingAs($enExamen)->post(route('espace.verification.titulaire'), ['type_titulaire' => 'MORALE', 'raison_sociale' => 'Coop', 'forme_morale' => 'ASSOCIATION'])
            ->assertSessionHasErrors('type_titulaire');

        // Personne morale : raison sociale et forme obligatoires.
        $this->actingAs($valide)->post(route('espace.verification.titulaire'), ['type_titulaire' => 'MORALE'])->assertSessionHasErrors(['raison_sociale', 'forme_morale']);
        // Changement : le dossier validé est à refaire (rien n'est supprimé).
        $this->actingAs($valide)->post(route('espace.verification.titulaire'), ['type_titulaire' => 'MORALE', 'raison_sociale' => 'Coopérative des maraîchers', 'forme_morale' => 'ASSOCIATION'])
            ->assertSessionHas('succes');
        $valide->refresh();
        $this->assertSame([TypeTitulaire::Morale, 'Coopérative des maraîchers', StatutKyc::AMettreAJour], [$valide->type_titulaire, $valide->raison_sociale, $valide->statut_kyc]);
        $this->assertSame(3, $valide->kycDocuments()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'kyc.titulaire_change', 'entite_id' => $valide->id]);
    }

    public function test_dossiers_anciens_marques_pieces_a_mettre_a_jour(): void
    {
        Notification::fake();
        // Ancien dossier : pièce sans numéro ni date d'expiration (règles d'avant), carte active.
        $ancien = $this->citoyen(TypeTitulaire::Physique, ['statut_kyc' => StatutKyc::Valide, 'identite_validee' => ['nom' => 'KABORE', 'prenoms' => 'Issa']]);
        foreach ([['NIF', null], ['SELFIE', null]] as [$type]) {
            KycDocument::query()->forceCreate(['user_id' => $ancien->id, 'type' => $type === 'NIF' ? 'IFU' : $type, 'statut' => StatutDocumentKyc::Valide,
                'chemin_fichier' => 'kyc/x/'.$type, 'mime' => 'image/jpeg', 'taille' => 10, 'empreinte_sha256' => str_repeat('a', 64)]);
        }
        $carte = Carte::create(['type' => TypeCarte::Actionnaire, 'user_id' => $ancien->id, 'statut' => StatutCarte::Active, 'courante' => true,
            'jeton' => Str::random(64), 'emise_le' => now(), 'valide_jusqu_au' => now()->addYear(), 'contenu' => ['nom' => 'Issa KABORE']]);
        // Dossier qui suit déjà les règles : non touché.
        $conforme = $this->citoyen(TypeTitulaire::Physique, ['statut_kyc' => StatutKyc::EnAttente]);
        DossierKyc::complet($conforme);
        // Identité reconnue douteuse (annulation confirmée) : non touchée.
        $douteux = $this->citoyen(TypeTitulaire::Physique, ['statut_kyc' => StatutKyc::Rejete]);
        VerificationKyc::create(['user_id' => $douteux->id, 'decision' => VerificationKyc::DOUTEUX, 'motif' => 'Fraude', 'decide_par' => $this->admin->id,
            'statut' => VerificationKyc::ANNULATION_CONFIRMEE]);
        // Jamais déposé : non concerné.
        $this->citoyen();

        $this->artisan('raaga:kyc-pieces-a-mettre-a-jour --simuler')->assertSuccessful()->expectsOutputToContain('Simulation : 1 dossier(s)');
        $this->assertSame(StatutKyc::Valide, $ancien->fresh()->statut_kyc);

        $this->artisan('raaga:kyc-pieces-a-mettre-a-jour')->assertSuccessful()->expectsOutputToContain('1 dossier(s) d’identité marqué(s)');
        $ancien->refresh();
        $this->assertSame(StatutKyc::AMettreAJour, $ancien->statut_kyc);
        $this->assertNotNull($ancien->kyc_maj_demandee_le);
        $this->assertSame(2, $ancien->kycDocuments()->count()); // rien n'est supprimé
        $this->assertSame([StatutKyc::EnAttente, StatutKyc::Rejete], [$conforme->fresh()->statut_kyc, $douteux->fresh()->statut_kyc]);
        Notification::assertSentTo($ancien, PiecesAMettreAJour::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'kyc.pieces_a_mettre_a_jour', 'entite_id' => $ancien->id]);

        // Pas de carte tant que le dossier n'est pas complété.
        $this->assertNotSame(StatutCarte::Active, $carte->fresh()->statut);
        $this->assertFalse($carte->fresh()->estValide());

        // Le titulaire doit déposer un dossier complet ; l'ADMIN le voit signalé.
        $this->actingAs($ancien)->get(route('espace.verification'))->assertOk()->assertSee('Pièces à mettre à jour')
            ->assertSee('Les règles des pièces ont changé', false)->assertSee('name="numero_piece"', false);
        $this->assertContains('Pièces à mettre à jour', array_column(ATraiter::pour($this->admin), 'element'));
        $this->en($this->admin)->get(route('admin.kyc.dossier', $ancien))->assertOk()->assertSee('data-a-mettre-a-jour', false);
        $this->en($this->admin)->get(route('admin.kyc', ['statut_kyc' => 'A_METTRE_A_JOUR']))->assertOk()->assertSee($ancien->name)->assertDontSee($conforme->name);

        // Un second passage ne trouve plus rien.
        $this->artisan('raaga:kyc-pieces-a-mettre-a-jour --simuler')->expectsOutputToContain('Simulation : 0 dossier(s)');
    }
}
