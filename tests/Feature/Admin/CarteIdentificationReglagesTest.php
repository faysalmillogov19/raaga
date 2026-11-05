<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Services\Plateforme\ParametresPlateforme;
use App\Support\ImagesPlateforme;
use App\Support\Plateforme\ReglagesCarte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/**
 * Section « Carte d'identification » des paramètres de la plateforme : réservée au SUPER_ADMIN, enregistrée sans
 * validation, durée de validité et filigranes (PNG contrôlés, jamais écrasés), état « prête » ou « réglages manquants »
 * (armoiries, logo, dénomination sociale de l'organisme émetteur), journal, aperçu. Plus d'autorité, de signature, de
 * cachet ni de numéro vert sur la carte (comité restreint du 03/11/2026).
 */
class CarteIdentificationReglagesTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** PNG réel à fond transparent (le faux fichier de Laravel est opaque). */
    private function pngTransparent(string $nom, int $largeur, int $hauteur): UploadedFile
    {
        $image = imagecreatetruecolor($largeur, $hauteur);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledellipse($image, intdiv($largeur, 2), intdiv($hauteur, 2), intdiv($largeur, 2), intdiv($hauteur, 2), imagecolorallocatealpha($image, 20, 40, 120, 0));
        $chemin = tempnam(sys_get_temp_dir(), 'png');
        imagepng($image, $chemin);

        return new UploadedFile($chemin, $nom, 'image/png', null, true);
    }

    private function complet(array $surcharges = []): array
    {
        return ['dureeValiditeAnnees' => 3, ...$surcharges];
    }

    /** Armoiries et logo déposés dans les paramètres (à défaut, la carte prend les fichiers fournis par le code). */
    private function deposerArmoiriesEtLogo(): void
    {
        $parametres = app(ParametresPlateforme::class);
        $valeurs = $parametres->section('identite');
        foreach (['armoiries', 'logoHorizontal'] as $champ) {
            $valeurs[$champ] = 'plateforme/'.$champ.'-test.png';
            Storage::disk('local')->put($valeurs[$champ], (string) file_get_contents($this->pngTransparent($champ.'.png', 200, 200)->getRealPath()));
        }
        $parametres->enregistrer('identite', $valeurs, null);
    }

    private function enregistrer(array $donnees)
    {
        return $this->put(route('admin.plateforme.update', 'carte'), $donnees);
    }

    public static function autresProfils(): array
    {
        return ['ADMIN' => [Role::Admin], 'VALIDATEUR' => [Role::Validateur], 'REGULATEUR' => [Role::Regulateur], 'AGENT DE GUICHET' => [Role::AgentGuichet]];
    }

    #[DataProvider('autresProfils')]
    public function test_aucun_autre_profil_ne_voit_ni_ne_modifie_les_reglages(Role $role): void
    {
        $this->connecterBackoffice($role);

        $this->get(route('admin.plateforme', ['section' => 'carte']))->assertForbidden();
        $this->get(route('admin.plateforme', ['section' => 'carte', 'onglet' => 'historique']))->assertForbidden();
        $this->enregistrer($this->complet())->assertForbidden();
        $this->post(route('admin.plateforme.apercu', 'carte'), $this->complet())->assertForbidden();

        $this->assertNull(plateforme('carte.dureeValiditeAnnees'));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_l_admin_ne_voit_pas_l_onglet_de_la_carte(): void
    {
        $this->connecterBackoffice(Role::Admin);

        $this->get(route('admin.plateforme', ['section' => 'contacts']))->assertOk()
            ->assertDontSee(route('admin.plateforme', ['section' => 'carte']))->assertDontSee('Carte d’identification');
    }

    public function test_le_super_admin_enregistre_directement_sans_validation_et_l_etat_passe_a_pret(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->deposerArmoiriesEtLogo();

        // Rien à saisir pour l'autorité : armoiries, logo et dénomination sociale suffisent.
        $this->get(route('admin.plateforme', ['section' => 'carte']))->assertOk()
            ->assertSee('data-etat-carte="pret"', false)->assertSee('Carte prête à être générée')
            ->assertDontSee('name="nomAutorite"', false)->assertDontSee('name="signatureAutorite"', false)->assertDontSee('name="cachetAutorite"', false)
            ->assertSee('name="dureeValiditeAnnees"', false);

        $this->enregistrer($this->complet(['dureeValiditeAnnees' => 4]))->assertSessionHasNoErrors()->assertSessionHas('succes');
        $this->assertSame(4, (int) plateforme('carte.dureeValiditeAnnees'));
        $this->assertTrue(ReglagesCarte::prete());
    }

    public function test_sans_denomination_sociale_l_etat_passe_a_reglages_manquants(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->deposerArmoiriesEtLogo();
        $parametres = app(ParametresPlateforme::class);
        $parametres->enregistrer('mentions', [...$parametres->section('mentions'), 'denominationSociale' => null], null);

        $this->assertSame(['denominationSociale'], ReglagesCarte::manquants());
        $this->get(route('admin.plateforme', ['section' => 'carte']))->assertOk()
            ->assertSee('data-etat-carte="manquant"', false)->assertSee('data-manquant="denominationSociale"', false)->assertSee('(section Mentions légales)');
    }

    public function test_sans_depot_la_carte_utilise_les_armoiries_et_le_logo_fournis_par_defaut(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->enregistrer($this->complet())->assertSessionHasNoErrors();

        // Champs de l'onglet 1 vides : fichiers fournis par le code, comme sur le site ; rien ne manque (30/10/2026).
        $this->assertNull(plateforme('identite.armoiries'));
        $this->assertNull(plateforme('identite.logoHorizontal'));
        $this->assertSame([], ReglagesCarte::manquants());
        $this->assertTrue(ReglagesCarte::prete());
        $this->get(route('admin.plateforme', ['section' => 'carte']))->assertOk()
            ->assertDontSee('data-manquant="armoiries"', false)->assertDontSee('data-manquant="logoHorizontal"', false);

        // Instantané figé : fichiers fournis par le code, retrouvés à la réimpression.
        $instantane = ReglagesCarte::instantane();
        $this->assertSame('public:'.ImagesPlateforme::DEFAUTS['armoiries'][0], $instantane['armoiries']);
        $this->assertSame('public:'.ImagesPlateforme::DEFAUTS['logoHorizontal'][0], $instantane['logo']);
        $this->assertSame(public_path(ImagesPlateforme::DEFAUTS['armoiries'][0]), ReglagesCarte::chemin($instantane['armoiries']));
        $this->assertFileExists(ReglagesCarte::chemin($instantane['logo']));

        // Un dépôt prend le pas sur le fichier par défaut.
        $this->deposerArmoiriesEtLogo();
        $this->assertSame(plateforme('identite.armoiries'), ReglagesCarte::instantane()['armoiries']);
    }

    public function test_filigranes_png_cinq_mo_et_huit_cents_px_sur_le_plus_grand_cote(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);

        $this->enregistrer($this->complet(['filigranePrincipalRecto' => UploadedFile::fake()->image('armoiries.jpg', 1000, 1000)]))
            ->assertSessionHasErrors(['filigranePrincipalRecto' => 'Filigrane principal recto : format refusé. Seul le format PNG est accepté (de préférence à fond transparent).']);
        $this->enregistrer($this->complet(['filigranePrincipalRecto' => UploadedFile::fake()->image('armoiries.png', 1000, 1000)->size(6000)]))
            ->assertSessionHasErrors(['filigranePrincipalRecto' => 'Filigrane principal recto : fichier trop lourd. 5 Mo au plus.']);
        $this->enregistrer($this->complet(['filigraneSecondaireVerso' => UploadedFile::fake()->image('chapeau.png', 700, 500)]))
            ->assertSessionHasErrors(['filigraneSecondaireVerso' => 'Filigrane secondaire verso : image trop petite. Elle doit faire au moins 800 px sur son plus grand côté.']);

        // 1000 × 300 : le plus grand côté suffit. Fichier à nom unique, aperçu de l'origine et de la version filigrane.
        $this->enregistrer($this->complet(['filigranePrincipalVerso' => UploadedFile::fake()->image('silure.png', 1000, 300)]))->assertSessionHasNoErrors();
        $this->assertMatchesRegularExpression('#^plateforme/filigrane-principal-verso-[0-9a-f-]{36}\.png$#', plateforme('carte.filigranePrincipalVerso'));
        $this->get(route('admin.plateforme', ['section' => 'carte']))->assertOk()->assertSee('data-filigrane-actuel="filigranePrincipalVerso"', false)
            ->assertSee('Version filigrane (or, 14 %)');
    }

    public function test_ni_autorite_ni_numero_vert_mais_l_organisme_emetteur_et_le_code_de_controle(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->deposerArmoiriesEtLogo();

        $this->post(route('admin.plateforme.apercu', 'carte'), $this->complet())->assertOk()
            ->assertSeeInOrder(['Carte émise par', 'Raaga SA', 'Carte émise électroniquement, valable sans signature. Vérifiez-la en scannant le QR code.', 'Code de contrôle'])
            ->assertDontSee('Numéro vert')->assertDontSee('L’autorité de délivrance', false)->assertDontSee('Cachet de l’autorité', false);

        // Le numéro vert reste un contact de Raaga, mais n'est plus un réglage de la carte.
        $parametres = app(ParametresPlateforme::class);
        $parametres->enregistrer('contacts', [...$parametres->section('contacts'), 'numeroVert' => null], null);
        $this->assertSame([], ReglagesCarte::manquants());
    }

    public function test_remplacer_un_filigrane_garde_l_ancien_fichier(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->enregistrer($this->complet(['filigranePrincipalVerso' => UploadedFile::fake()->image('silure.png', 1000, 600)]))->assertSessionHasNoErrors();
        $ancien = plateforme('carte.filigranePrincipalVerso');

        $this->enregistrer($this->complet(['filigranePrincipalVerso' => UploadedFile::fake()->image('silure-2.png', 1200, 700)]))->assertSessionHasNoErrors();
        $nouveau = plateforme('carte.filigranePrincipalVerso');

        $this->assertNotSame($ancien, $nouveau);
        Storage::disk('local')->assertExists([$ancien, $nouveau]);
    }

    public function test_chaque_modification_est_inscrite_au_journal_avec_l_ancienne_et_la_nouvelle_valeur(): void
    {
        $admin = $this->connecterBackoffice(Role::SuperAdmin);
        $this->enregistrer($this->complet())->assertSessionHasNoErrors();

        $this->enregistrer($this->complet(['dureeValiditeAnnees' => 5]))->assertSessionHasNoErrors();

        $entree = AuditLog::query()->where('action', 'plateforme.modifiee')->where('entite_id', 'carte')->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $entree->acteur_id);
        $this->assertSame(['dureeValiditeAnnees' => 3], $entree->ancienne_valeur);
        $this->assertSame(['dureeValiditeAnnees' => 5], $entree->nouvelle_valeur);
        $this->assertNotNull($entree->created_at);
    }

    public function test_l_apercu_montre_recto_et_verso_specimen_sans_rien_enregistrer(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->deposerArmoiriesEtLogo();
        $fichiers = Storage::disk('local')->allFiles();
        $versions = DB::table('parametres_plateforme_versions')->count();

        $this->post(route('admin.plateforme.apercu', 'carte'), $this->complet(['dureeValiditeAnnees' => 7]))->assertOk()
            ->assertSee('data-face="recto"', false)->assertSee('data-face="verso"', false)
            ->assertSee('SPÉCIMEN')->assertSee('data:image/png;base64,', false)
            ->assertSee('Carte prête à être générée')
            // Recto de la maquette du 03/11/2026 : n° de carte (IFU), identité, secteur, site, dates ; jamais le code d'une boutique.
            ->assertSeeInOrder(['Burkina Faso', 'Carte d’identification Raaga', 'COMMERÇANT', 'Numéro Raaga', 'Nom et prénom(s)', 'Sexe', 'Né(e) le', 'À',
                'Secteur d’activité', 'Site', 'Établie le', 'Expire le', 'Groupe sanguin', 'Lieu de résidence', 'Personne à prévenir', 'Numéro Raaga · Expiration'])
            ->assertDontSee('Emplacement');

        $this->assertNull(plateforme('carte.dureeValiditeAnnees'));
        $this->assertSame($versions, DB::table('parametres_plateforme_versions')->count());
        // Seul le filigrane des armoiries peut être mis en cache (dérivé des armoiries, sans donnée saisie).
        $this->assertSame($fichiers, array_values(array_filter(Storage::disk('local')->allFiles(), fn ($f) => ! str_starts_with($f, 'cartes/filigranes/'))));
    }

    public function test_les_filigranes_ne_sont_servis_par_aucune_adresse_publique(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->enregistrer($this->complet(['filigranePrincipalVerso' => UploadedFile::fake()->image('silure.png', 1000, 600)]))->assertSessionHasNoErrors();

        $this->get('/plateforme/images/filigranePrincipalVerso')->assertNotFound();
        // La page des réglages intègre les images (data URI) sans exposer le chemin du fichier.
        $this->get(route('admin.plateforme', ['section' => 'carte']))->assertOk()->assertDontSee(plateforme('carte.filigranePrincipalVerso'));
    }
}
