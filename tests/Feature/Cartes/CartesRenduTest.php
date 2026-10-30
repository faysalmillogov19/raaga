<?php

namespace Tests\Feature\Cartes;

use App\Enums\Role;
use App\Enums\StatutCarte;
use App\Enums\StatutKyc;
use App\Enums\TypeCarte;
use App\Models\AuditLog;
use App\Models\Carte;
use App\Models\User;
use App\Notifications\CarteImprimee;
use App\Services\Cartes\RenduCarte;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\ConnexionBackoffice;
use Tests\Support\ReglagesCarteComplets;
use Tests\TestCase;

/**
 * Carte côté titulaire (QR code, plein écran, PDF d'une page par face au format ID-1, PNG 300 dpi par face, régénération
 * par version) et impression au guichet (pièce vérifiée, impression tracée, titulaire notifié). Rendu réel par Chrome.
 */
class CartesRenduTest extends TestCase
{
    use ConnexionBackoffice;
    use RefreshDatabase;
    use ReglagesCarteComplets;

    private User $titulaire;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->completerReglagesCarte();
        $this->titulaire = User::factory()->role(Role::Citoyen)->create(['name' => 'Awa OUEDRAOGO', 'email' => 'awa@exemple.bf',
            'statut_kyc' => StatutKyc::Valide, 'identite_validee' => ['nom' => 'OUEDRAOGO', 'prenoms' => 'Awa']]);
    }

    private function carte(StatutCarte $statut = StatutCarte::Active, TypeCarte $type = TypeCarte::Commercant, ?User $user = null): Carte
    {
        $carte = Carte::create(['type' => $type, 'user_id' => ($user ?? $this->titulaire)->id, 'statut' => $statut,
            'courante' => $statut->courante() ? true : null, 'jeton' => Str::random(64), 'emise_le' => now(), 'valide_jusqu_au' => now()->addYears(2)->toDateString(),
            'contenu' => ['nom' => 'Awa OUEDRAOGO', 'emplacements' => [['code' => 'RAA-R1-A-045', 'site' => 'Marché de Rood Woko']]]]);
        $carte->forceFill(['numero' => sprintf('RG-C-%06d', $carte->id)])->save();

        return $carte;
    }

    public function test_ma_carte_affiche_le_qr_code_le_plein_ecran_et_les_telechargements(): void
    {
        $carte = $this->carte();
        $page = $this->actingAs($this->titulaire)->get(route('espace.carte', ['type' => 'commercant']))->assertOk()
            ->assertSee('<svg', false)->assertSee('data-plein-ecran', false)->assertSee('Afficher en plein écran')
            ->assertSee(route('cartes.fichier', [$carte, 'pdf']), false)->assertSee(route('cartes.fichier', [$carte, 'png']), false)
            ->assertSee('RAA-R1-A-045');
        // Le jeton n'apparaît jamais en clair dans la page (seul le QR code le porte).
        $page->assertDontSee($carte->jeton);

        // QR code : l'adresse de vérification seule, sans donnée personnelle ; correction H (logo au centre sur la carte).
        $attendu = Encoder::encode($carte->urlVerification(), ErrorCorrectionLevel::H())->getMatrix();
        $this->assertEquals($attendu, app(RenduCarte::class)->matrice($carte));

        // Carte suspendue : ni QR code ni téléchargement.
        $carte->forceFill(['statut' => StatutCarte::Suspendue])->save();
        $this->get(route('espace.carte', ['type' => 'commercant']))->assertOk()->assertDontSee('data-plein-ecran', false)->assertSee('Suspendue');
        $this->get(route('cartes.fichier', [$carte, 'pdf']))->assertNotFound();
    }

    public function test_telechargement_pdf_format_carte_bancaire_et_png(): void
    {
        $this->exigerChrome();
        $carte = $this->carte();

        $pdf = $this->actingAs($this->titulaire)->get(route('cartes.fichier', [$carte, 'pdf']))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('carte-raaga-'.strtolower($carte->numero).'-v1.pdf', $pdf->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        // Format ID-1 : 85,6 × 54 mm (242,65 × 153,07 points ; Chrome arrondit à 242,88 × 153,12), une page par face.
        $this->assertMatchesRegularExpression('#/MediaBox \[0(\.0+)? 0(\.0+)? 242\.\d+ 153\.\d+\]#', $pdf->getContent());
        $this->assertSame(2, preg_match_all('#/Type\s*/Page\b#', $pdf->getContent()));

        // PNG 300 dpi : 1011 × 638 px par face, recto par défaut, verso sur demande.
        $png = $this->get(route('cartes.fichier', [$carte, 'png']))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('-recto.png', $png->headers->get('Content-Disposition'));
        $image = imagecreatefromstring($png->getContent());
        $this->assertSame([RenduCarte::LARGEUR, RenduCarte::HAUTEUR], [imagesx($image), imagesy($image)]);
        $verso = $this->get(route('cartes.fichier', [$carte, 'png', 'face' => 'verso']))->assertOk();
        $this->assertStringContainsString('-verso.png', $verso->headers->get('Content-Disposition'));
        $this->assertNotSame($png->getContent(), $verso->getContent());

        // Régénération : chaque changement de contenu donne une nouvelle version, et un nouveau fichier.
        $carte->forceFill(['version' => 2])->save();
        $this->assertStringContainsString('-v2-recto.png', $this->get(route('cartes.fichier', [$carte, 'png']))->headers->get('Content-Disposition'));

        // Seul le titulaire télécharge sa carte.
        $this->actingAs(User::factory()->role(Role::Citoyen)->create())->get(route('cartes.fichier', [$carte, 'pdf']))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->connecterBackoffice(Role::AgentGuichet);
        $this->get(route('cartes.fichier', [$carte, 'pdf']))->assertForbidden();
    }

    public function test_ma_carte_du_personnel_au_back_office(): void
    {
        $this->exigerChrome();
        $admin = $this->connecterBackoffice(Role::Admin, ['name' => 'Rasmata OUÉDRAOGO']);
        $carte = $this->carte(StatutCarte::Active, TypeCarte::Personnel, $admin);
        $carte->forceFill(['contenu' => ['nom' => 'Rasmata OUÉDRAOGO', 'fonction' => 'Administrateur']])->save();

        $this->get(route('admin.carte'))->assertOk()->assertSee('Administrateur')->assertSee('data-plein-ecran', false)
            ->assertSee(route('cartes.fichier', [$carte, 'png']), false);
        $this->get(route('cartes.fichier', [$carte, 'png']))->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_impression_au_guichet_apres_controle_de_la_piece(): void
    {
        $this->exigerChrome();
        $carte = $this->carte();
        $agent = $this->connecterBackoffice(Role::AgentGuichet);

        $this->get(route('admin.guichet.carte', $carte->jeton))->assertOk()->assertSee('data-imprimer', false)
            ->assertSee('J’ai vérifié la pièce d’identité du titulaire');

        // Pièce non vérifiée : refus.
        $this->from(route('admin.guichet.carte', $carte->jeton))->post(route('admin.guichet.imprimer', $carte->jeton))
            ->assertSessionHasErrors('piece_verifiee');
        $this->assertSame(0, AuditLog::query()->where('action', 'carte.imprimee')->count());

        $pdf = $this->post(route('admin.guichet.imprimer', $carte->jeton), ['piece_verifiee' => '1'])->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'carte.imprimee', 'acteur_id' => $agent->id, 'entite_id' => $carte->id]);
        Notification::assertSentTo($this->titulaire, CarteImprimee::class);

        // Carte non valide : pas d'impression.
        $carte->forceFill(['statut' => StatutCarte::Suspendue])->save();
        $this->get(route('admin.guichet.carte', $carte->jeton))->assertDontSee('data-imprimer', false);
        $this->post(route('admin.guichet.imprimer', $carte->jeton), ['piece_verifiee' => '1'])->assertSessionHasErrors('piece_verifiee');

        // L'ADMIN vérifie mais n'imprime pas.
        $carte->forceFill(['statut' => StatutCarte::Active])->save();
        $this->app['auth']->forgetGuards();
        $this->connecterBackoffice(Role::Admin);
        $this->get(route('admin.guichet.carte', $carte->jeton))->assertOk()->assertDontSee('data-imprimer', false);
        $this->post(route('admin.guichet.imprimer', $carte->jeton), ['piece_verifiee' => '1'])->assertForbidden();
    }
}
