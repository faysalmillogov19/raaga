<?php

namespace Tests\Feature\Publique;

use App\Enums\Role;
use App\Support\ImagesPlateforme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/**
 * Logo Raaga : présent aux emplacements prévus, lu depuis les paramètres de la plateforme,
 * icônes et image de partage ; aucun symbole Unicode utilisé comme icône.
 */
class LogoTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    public function test_le_logo_figure_dans_l_en_tete_le_pied_de_page_et_la_connexion(): void
    {
        $logo = e(ImagesPlateforme::url('logoHorizontal'));

        $accueil = $this->get('/')->assertOk()
            ->assertSee('alt="Raaga – Participation populaire, accueil"', false)
            ->assertSee($logo, false)
            ->assertSee(e(ImagesPlateforme::url('logoSymbole')), false)
            ->assertSee('srcset=', false);
        $this->get('/connexion')->assertOk()->assertSee($logo, false);
        $this->assertStringContainsString('images/og-raaga.png', $accueil->getContent());
        $this->assertStringContainsString('rel="manifest"', $accueil->getContent());

        // Police hébergée localement : aucun appel à un service externe.
        $this->assertStringNotContainsString('fonts.googleapis', $accueil->getContent());
        $this->assertStringContainsString('fonts/public-sans-400.woff2', $accueil->getContent());
        foreach ([400, 600, 700, 800] as $graisse) {
            $this->assertFileExists(public_path("fonts/public-sans-{$graisse}.woff2"));
        }
    }

    public function test_les_fichiers_du_logo_sont_servis(): void
    {
        foreach (['logoHorizontal', 'logoSymbole', 'logoSymboleSimplifie'] as $champ) {
            $this->get(ImagesPlateforme::url($champ))->assertOk()->assertHeader('Content-Type', 'image/png');
        }
        // Pas de version blanche fournie : pas d'adresse, et le pied de page pose le logo sur fond blanc.
        $this->assertNull(ImagesPlateforme::url('logoHorizontalBlanc'));
        $this->get('/plateforme/images/inconnu')->assertNotFound();

        $this->get(route('manifeste'))->assertOk()->assertJsonPath('name', 'Raaga · Participation populaire')
            ->assertJsonPath('icons.0.sizes', '192x192');
        foreach (['favicon.ico', 'apple-touch-icon.png', 'images/og-raaga.png', 'images/logo/icone-512.png'] as $fichier) {
            $this->assertFileExists(public_path($fichier));
        }
    }

    public function test_le_super_admin_remplace_le_logo_et_le_site_l_affiche(): void
    {
        Storage::fake('local');
        $this->connecterBackoffice(Role::SuperAdmin);
        $avant = ImagesPlateforme::url('logoHorizontal');

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 4"><rect width="10" height="4"/></svg>');
        $this->put(route('admin.plateforme.update', 'identite'), [
            'nomPlateforme' => 'Raaga · Participation populaire', 'logoHorizontal' => $svg,
        ])->assertSessionHas('succes');

        $apres = ImagesPlateforme::url('logoHorizontal');
        $this->assertNotSame($avant, $apres);
        $this->get($apres)->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->get('/')->assertSee(e($apres), false);
    }

    public function test_aucun_symbole_unicode_ne_sert_d_icone_dans_les_pages_publiques(): void
    {
        foreach (['/', '/offre', '/transparence', '/faq', '/connexion', '/inscription', '/mentions-legales'] as $page) {
            $contenu = $this->get($page)->assertOk()->getContent();
            foreach (['⚠', 'ℹ', '✓', '→'] as $symbole) {
                $this->assertStringNotContainsString($symbole, $contenu, "« {$symbole} » sur {$page}");
            }
        }
    }
}
