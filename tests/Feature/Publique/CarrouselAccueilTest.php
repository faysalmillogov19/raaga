<?php

namespace Tests\Feature\Publique;

use App\Enums\Role;
use App\Support\Diapositives;
use Database\Seeders\CarrouselAccueilSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/** Carrousel de la page d'accueil : Paramètres > Site public > Carrousel. */
class CarrouselAccueilTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['raaga.phase.presentation_actions' => false]);
        Cache::flush();
        Storage::fake('local');
    }

    /** Une diapositive saisie dans le formulaire (image déposée par défaut : la plus petite admise, 640 px de large). */
    private function diapositive(array $surcharges = []): array
    {
        return [
            'fichier' => UploadedFile::fake()->image('marche.jpg', 640, 360),
            'alt' => 'Allée du marché',
            'titre' => 'Votre boutique',
            'sousTitre' => 'Candidatez en ligne.',
            'boutonLibelle' => 'Je réserve',
            'boutonLien' => '/inscription',
            'active' => '1',
            ...$surcharges,
        ];
    }

    private function enregistrer(array $diapositives)
    {
        return $this->put(route('admin.plateforme.update', 'site_public'), ['carrousel' => $diapositives]);
    }

    public function test_diapositive_par_defaut_sans_diapositive_active(): void
    {
        // Rendu historique de l'accueil (thème « clair ») ; le thème « nuit » est couvert par ThemeNuitAccueilBoutiquesTest.
        config(['raaga.theme_public' => 'clair']);
        $page = $this->get('/')->assertOk();

        $page->assertSee('class="acc-diapo acc-diapo-defaut"', false)->assertSee('aria-label="1 sur 1"', false)
            ->assertSeeInOrder(['Votre boutique au marché Raaga', 'Je réserve', 'Comment ça marche'])
            // Une seule diapositive : ni lecture automatique, ni commandes, ni image à précharger.
            ->assertDontSee('data-carrousel', false)->assertDontSee('data-lecture', false)->assertDontSee('rel="preload" as="image"', false);
    }

    public function test_l_admin_ajoute_des_diapositives_converties_en_webp(): void
    {
        // Seul test de la vraie conversion WebP, de bout en bout (photo assez large pour les trois déclinaisons).
        $this->conversionWebpReelle();
        $this->connecterBackoffice(Role::Admin);
        $this->get(route('admin.plateforme', ['section' => 'site_public']))->assertOk()->assertSee('Carrousel');

        $photo = fn () => UploadedFile::fake()->image('marche.jpg', 2400, 1350);
        $this->enregistrer([$this->diapositive(['fichier' => $photo()]), $this->diapositive(['fichier' => $photo(), 'titre' => 'Deuxième', 'boutonLibelle' => null, 'boutonLien' => null])])
            ->assertSessionHasNoErrors()->assertSessionHas('succes');

        $diapos = plateforme('site_public.carrousel');
        $this->assertCount(2, $diapos);
        $this->assertSame(['Votre boutique', 'Deuxième'], array_column($diapos, 'titre'));
        foreach ([640, 1280, 1920] as $largeur) {
            [$l] = getimagesize(Storage::disk('local')->path($diapos[0]['image'].'-'.$largeur.'.webp'));
            $this->assertSame($largeur, $l);
            $this->get(Diapositives::url($diapos[0]['image'], $largeur))->assertOk()->assertHeader('Content-Type', 'image/webp')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
        }
        $uuid = substr($diapos[0]['image'], strlen('plateforme/diapositive-'));
        $this->get(route('diapositive', ['image' => $uuid, 'largeur' => 999]))->assertNotFound();
        $this->get(route('diapositive', ['image' => '00000000-0000-0000-0000-000000000000', 'largeur' => 640]))->assertNotFound();

        $page = $this->get('/')->assertOk();
        $html = $page->getContent();
        $page->assertSee('role="region" aria-roledescription="carrousel"', false)->assertSee('data-carrousel', false)
            ->assertSee('aria-label="1 sur 2"', false)->assertSee('aria-label="2 sur 2"', false)
            ->assertSee('rel="preload" as="image" type="image/webp"', false)
            ->assertSee('aria-label="Mettre le défilement en pause"', false)
            ->assertSee('aria-label="Diapositive précédente"', false)->assertSee('aria-label="Diapositive suivante"', false)
            ->assertSee('Comment ça marche')->assertDontSee('acc-diapo-defaut', false);
        // Première image en priorité ; la seconde n'est chargée qu'à l'approche de son tour (pas de src), masquée aux lecteurs d'écran.
        $this->assertSame(1, substr_count($html, 'fetchpriority="high" decoding'));
        $this->assertMatchesRegularExpression('#<img src="[^"]+" srcset="[^"]+640w[^"]+1280w[^"]+1920w" sizes="100vw"\s+alt="Allée du marché"#', $html);
        $this->assertMatchesRegularExpression('#aria-label="2 sur 2" data-diapo\s+aria-hidden="true" inert\s*>\s*<img data-src="[^"]+" data-srcset="[^"]+1920w" sizes="100vw"#', $html);
        $this->assertSame(1, substr_count($html, 'class="acc-btn acc-btn-jaune acc-diapo-bouton"'));

        // Nouvel enregistrement sans fichier : les images en place sont conservées.
        $this->enregistrer(array_map(fn ($d) => [...$d, 'active' => '1'], array_map(fn ($d) => array_map(fn ($v) => is_bool($v) ? ($v ? '1' : '0') : $v, $d), $diapos)))
            ->assertSessionHasNoErrors();
        $this->assertSame(array_column($diapos, 'image'), array_column(plateforme('site_public.carrousel'), 'image'));
    }

    public function test_les_saisies_invalides_sont_refusees(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);

        $this->enregistrer(array_fill(0, 6, $this->diapositive()))->assertSessionHasErrors(['carrousel' => '5 diapositives actives au plus : désactivez-en une avant d’en activer une autre.']);
        $this->enregistrer([$this->diapositive(['alt' => ''])])->assertSessionHasErrors('carrousel.0.alt');
        $this->enregistrer([$this->diapositive(['titre' => str_repeat('a', 61), 'sousTitre' => str_repeat('b', 141)])])->assertSessionHasErrors(['carrousel.0.titre', 'carrousel.0.sousTitre']);
        $this->enregistrer([$this->diapositive(['boutonLien' => 'javascript:alert(1)'])])->assertSessionHasErrors(['carrousel.0.boutonLien' => 'Le lien doit commencer par https:// ou par / (page du site).']);
        $this->enregistrer([$this->diapositive(['boutonLien' => '//exemple.org'])])->assertSessionHasErrors('carrousel.0.boutonLien');
        $this->enregistrer([$this->diapositive(['boutonLibelle' => null])])->assertSessionHasErrors('carrousel.0.boutonLibelle');
        $this->enregistrer([$this->diapositive(['debut' => '2026-12-10', 'fin' => '2026-12-01'])])->assertSessionHasErrors(['carrousel.0.fin' => 'La fin doit être postérieure au début.']);
        $this->enregistrer([$this->diapositive(['fichier' => null])])->assertSessionHasErrors(['carrousel.0.image' => 'Choisissez une image pour cette diapositive.']);
        $this->enregistrer([$this->diapositive(['fichier' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')])])->assertSessionHasErrors('carrousel.0.fichier');
        $this->enregistrer([$this->diapositive(['fichier' => UploadedFile::fake()->image('grande.jpg', 640, 360)->size(6000)])])->assertSessionHasErrors('carrousel.0.fichier');
        $this->enregistrer([$this->diapositive(['fichier' => UploadedFile::fake()->image('petite.jpg', 400, 300)])])->assertSessionHasErrors('carrousel.0.fichier');

        $this->assertSame([], plateforme('site_public.carrousel'));
    }

    public function test_ordre_activation_et_periode_d_affichage(): void
    {
        // Rendu historique de l'accueil (thème « clair ») ; le thème « nuit » est couvert par ThemeNuitAccueilBoutiquesTest.
        config(['raaga.theme_public' => 'clair']);
        $this->connecterBackoffice(Role::Admin);
        $this->travelTo('2026-10-14 10:00');

        $this->enregistrer([
            $this->diapositive(['titre' => 'Troisième', 'ordre' => '3']),
            $this->diapositive(['titre' => 'Première', 'ordre' => '1']),
            $this->diapositive(['titre' => 'Inactive', 'ordre' => '2', 'active' => '0']),
            $this->diapositive(['titre' => 'Programmée', 'ordre' => '4', 'debut' => '2026-10-20']),
            $this->diapositive(['titre' => 'Terminée', 'ordre' => '5', 'fin' => '2026-10-13']),
            $this->diapositive(['titre' => 'En cours', 'ordre' => '6', 'debut' => '2026-10-14', 'fin' => '2026-10-14']),
        ])->assertSessionHasNoErrors();

        $this->assertSame(['Première', 'Inactive', 'Troisième', 'Programmée', 'Terminée', 'En cours'], array_column(plateforme('site_public.carrousel'), 'titre'));
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column(plateforme('site_public.carrousel'), 'ordre'));

        $page = $this->get('/')->assertOk()->assertSeeInOrder(['Première', 'Troisième', 'En cours'])->assertSee('aria-label="3 sur 3"', false);
        foreach (['Inactive', 'Programmée', 'Terminée'] as $titre) {
            $page->assertDontSee($titre);
        }

        // Le 20 octobre, la diapositive programmée apparaît.
        $this->travelTo('2026-10-20 08:00');
        $this->get('/')->assertSee('Programmée')->assertDontSee('En cours');
    }

    public function test_cadrage_et_position_du_texte(): void
    {
        $this->connecterBackoffice(Role::Admin);

        $this->enregistrer([$this->diapositive(['cadrageX' => '36', 'cadrageY' => '0', 'texte' => 'droite']), $this->diapositive()])->assertSessionHasNoErrors();
        $diapos = plateforme('site_public.carrousel');
        $this->assertSame([36, 0, 'droite'], [$diapos[0]['cadrageX'], $diapos[0]['cadrageY'], $diapos[0]['texte']]);
        $this->assertSame([50, 50, 'gauche'], [$diapos[1]['cadrageX'], $diapos[1]['cadrageY'], $diapos[1]['texte']]);

        $this->get('/')->assertSee('acc-diapo acc-diapo-droite', false)->assertSee('style="--cadrage: 36% 0%"', false)
            ->assertSee('style="--cadrage: 50% 50%"', false);

        $this->enregistrer([$this->diapositive(['texte' => 'panneau'])])->assertSessionHasNoErrors();
        $this->get('/')->assertSee('acc-diapo acc-diapo-panneau', false);

        $this->enregistrer([$this->diapositive(['cadrageX' => '101', 'texte' => 'haut'])])->assertSessionHasErrors(['carrousel.0.cadrageX', 'carrousel.0.texte']);
    }

    public function test_apercu_sans_enregistrement_et_historique(): void
    {
        $this->connecterBackoffice(Role::Admin);

        // Aperçu : la diapositive programmée est montrée, rien n'est enregistré.
        $this->post(route('admin.plateforme.apercu', 'site_public'), ['_method' => 'PUT', 'carrousel' => [$this->diapositive(['titre' => 'Programmée 2030', 'debut' => '2030-01-01'])]])
            ->assertOk()->assertSee('Programmée 2030')->assertSee('aria-label="1 sur 1"', false);
        $this->assertSame([], plateforme('site_public.carrousel'));
        $this->get('/')->assertDontSee('Programmée 2030');

        $this->enregistrer([$this->diapositive(['titre' => 'Une']), $this->diapositive(['titre' => 'Deux', 'active' => '0'])])->assertSessionHasNoErrors();
        $this->get(route('admin.plateforme', ['section' => 'site_public', 'onglet' => 'historique']))->assertOk()
            ->assertSee('2 diapositives (1 active) : Une ; (inactive) Deux');
    }

    public function test_seuls_l_admin_et_le_super_admin_gerent_le_carrousel(): void
    {
        foreach ([Role::Regulateur, Role::Validateur, Role::GestionnaireSites] as $role) {
            $this->connecterBackoffice($role);
            $this->enregistrer([$this->diapositive()])->assertForbidden();
        }
        $this->assertSame([], plateforme('site_public.carrousel'));
    }

    public function test_contenu_de_depart_en_recette_seulement(): void
    {
        $this->app['env'] = 'production';
        $this->app->make(CarrouselAccueilSeeder::class)->__invoke();
        $this->assertSame([], plateforme('site_public.carrousel'));

        $this->app['env'] = 'testing';
        $this->app->make(CarrouselAccueilSeeder::class)->__invoke();
        $diapos = plateforme('site_public.carrousel');
        $this->assertCount(4, $diapos);
        $this->assertTrue(collect($diapos)->every(fn ($d) => $d['active'] && Diapositives::existe($d['image']) && $d['alt']));

        // Relancé : le carrousel saisi n'est jamais remplacé.
        $this->app->make(CarrouselAccueilSeeder::class)->__invoke();
        $this->assertSame(array_column($diapos, 'image'), array_column(plateforme('site_public.carrousel'), 'image'));
        $this->get('/')->assertSee('aria-label="4 sur 4"', false);
    }
}
