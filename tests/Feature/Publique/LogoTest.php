<?php

namespace Tests\Feature\Publique;

use App\Enums\Role;
use App\Services\Plateforme\ParametresPlateforme;
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

    public function test_armoiries_en_tete_logo_en_tete_nuit_au_pied_de_page_et_a_la_connexion(): void
    {
        $logo = e(ImagesPlateforme::url('logoHorizontal'));

        // Thème « nuit » (défaut) : en-tête blanc avec les armoiries et le logo (maquettes du thème).
        preg_match('/<header.*?<\/header>/s', $this->get('/')->assertOk()->getContent(), $enteteNuit);
        $this->assertStringContainsString(e(ImagesPlateforme::url('armoiries')), $enteteNuit[0]);
        $this->assertStringContainsString($logo, $enteteNuit[0]);

        // Thème « clair » (rendu historique) : armoiries seules dans l'en-tête.
        config(['raaga.theme_public' => 'clair']);
        $accueil = $this->get('/')->assertOk();
        preg_match('/<header.*?<\/header>/s', $accueil->getContent(), $entete);
        $this->assertStringContainsString(e(ImagesPlateforme::url('armoiries')), $entete[0]);
        $this->assertStringContainsString('aria-label="Accueil · '.e(plateforme('identite.nomPlateforme')).'"', $entete[0]);
        foreach ([$logo, e(ImagesPlateforme::url('logoSymbole')), 'BURKINA FASO', e((string) plateforme('identite.deviseNationale'))] as $absent) {
            $this->assertStringNotContainsString($absent, $entete[0]);
        }

        preg_match('/<footer.*?<\/footer>/s', $accueil->getContent(), $pied);
        $this->assertStringContainsString($logo, $pied[0]);
        $accueil->assertSee('srcset=', false);
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

    public function test_les_armoiries_officielles_restent_affichees_quand_la_base_est_vide(): void
    {
        // Base neuve : aucun fichier déposé dans les paramètres ; les armoiries versionnées avec le code sont servies.
        $this->assertNull(app(ParametresPlateforme::class)->get('identite.armoiries'));
        $this->assertSame(public_path('armoiries.png'), ImagesPlateforme::chemin('armoiries'));
        $this->assertGreaterThan(50_000, filesize(public_path('armoiries.png')), 'armoiries officielles, pas un écusson provisoire');

        $reponse = $this->get(ImagesPlateforme::url('armoiries'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame(file_get_contents(public_path('armoiries.png')), $reponse->streamedContent() ?: $reponse->getContent());
        $this->get('/')->assertOk()->assertSee(e(ImagesPlateforme::url('armoiries')), false);
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
