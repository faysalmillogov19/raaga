<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Services\Plateforme\ParametresPlateforme;
use App\Support\ImagesPlateforme;
use App\Support\Telephone;
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
 * validation, images PNG contrôlées et jamais écrasées, état « prête » ou « réglages manquants » (armoiries et logo
 * déposés, numéro vert des contacts), journal, aperçu.
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
        return [
            'qualiteAutorite' => 'Le Maire de l’Arrondissement N°1',
            'nomAutorite' => 'Awa OUÉDRAOGO',
            'signatureAutorite' => $this->pngTransparent('signature.png', 900, 300),
            'cachetAutorite' => $this->pngTransparent('cachet.png', 700, 700),
            ...$surcharges,
        ];
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

        $this->assertNull(plateforme('carte.nomAutorite'));
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

        $this->get(route('admin.plateforme', ['section' => 'carte']))->assertOk()
            ->assertSee('data-etat-carte="manquant"', false)->assertSee('Réglages manquants')
            ->assertSee('data-manquant="signatureAutorite"', false)->assertDontSee('data-manquant="armoiries"', false);

        $this->enregistrer($this->complet())->assertSessionHasNoErrors()->assertSessionHas('succes')->assertSessionMissing('avertissement_plateforme');

        $this->assertSame('Le Maire de l’Arrondissement N°1', plateforme('carte.qualiteAutorite'));
        $this->assertTrue(ReglagesCarte::prete());
        $this->get(route('admin.plateforme', ['section' => 'carte']))->assertOk()
            ->assertSee('data-etat-carte="pret"', false)->assertSee('Carte prête à être générée')
            ->assertSee('data-image-actuelle="signatureAutorite"', false);
    }

    public function test_vider_un_champ_obligatoire_fait_passer_l_etat_a_reglages_manquants(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->deposerArmoiriesEtLogo();
        $this->enregistrer($this->complet())->assertSessionHasNoErrors();

        $this->enregistrer(['qualiteAutorite' => 'Le Maire de l’Arrondissement N°1', 'nomAutorite' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull(plateforme('carte.nomAutorite'));
        $this->assertSame(['nomAutorite'], ReglagesCarte::manquants());
        $this->get(route('admin.plateforme', ['section' => 'carte']))->assertOk()
            ->assertSee('data-etat-carte="manquant"', false)->assertSee('data-manquant="nomAutorite"', false)
            ->assertDontSee('data-manquant="signatureAutorite"', false);
    }

    public function test_un_jpg_un_png_trop_lourd_et_un_png_trop_petit_sont_refuses_avec_le_bon_message(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);

        $this->enregistrer($this->complet(['signatureAutorite' => UploadedFile::fake()->image('signature.jpg', 900, 300)]))
            ->assertSessionHasErrors(['signatureAutorite' => 'Signature de l’autorité : format refusé. Seul le format PNG est accepté (de préférence à fond transparent).']);

        $this->enregistrer($this->complet(['signatureAutorite' => UploadedFile::fake()->image('signature.png', 900, 300)->size(3000)]))
            ->assertSessionHasErrors(['signatureAutorite' => 'Signature de l’autorité : fichier trop lourd. 2 Mo au plus.']);

        $this->enregistrer($this->complet(['signatureAutorite' => $this->pngTransparent('signature.png', 500, 300)]))
            ->assertSessionHasErrors(['signatureAutorite' => 'Signature de l’autorité : image trop petite. Elle doit faire au moins 600 px de large.']);

        $this->enregistrer($this->complet(['cachetAutorite' => $this->pngTransparent('cachet.png', 700, 500)]))
            ->assertSessionHasErrors(['cachetAutorite' => 'Cachet de l’autorité : image trop petite. Elle doit faire au moins 600 × 600 px.']);

        // Rien n'a été enregistré ni déposé.
        $this->assertNull(plateforme('carte.signatureAutorite'));
        $this->assertSame([], Storage::disk('local')->allFiles());
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

    public function test_le_numero_vert_est_celui_des_contacts_affiche_avec_des_espaces(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->deposerArmoiriesEtLogo();
        $this->enregistrer($this->complet())->assertSessionHasNoErrors();
        $numero = plateforme('contacts.numeroVert');
        $this->assertNotNull($numero);

        $this->post(route('admin.plateforme.apercu', 'carte'), $this->complet())->assertOk()
            ->assertSee('data-champ="numero_vert">'.Telephone::formater($numero).'</strong>', false);

        // Numéro vert vidé dans les contacts : la carte n'est plus prête.
        $parametres = app(ParametresPlateforme::class);
        $parametres->enregistrer('contacts', [...$parametres->section('contacts'), 'numeroVert' => null], null);
        $this->assertSame(['numeroVert'], ReglagesCarte::manquants());
        $this->get(route('admin.plateforme', ['section' => 'carte']))->assertOk()
            ->assertSee('data-manquant="numeroVert"', false)->assertSee('(section Contacts d’assistance)');
    }

    public function test_remplacer_la_signature_garde_l_ancien_fichier(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->enregistrer($this->complet())->assertSessionHasNoErrors();
        $ancienne = plateforme('carte.signatureAutorite');

        $this->enregistrer([...$this->complet(), 'cachetAutorite' => null, 'signatureAutorite' => $this->pngTransparent('signature-2.png', 1200, 400)])
            ->assertSessionHasNoErrors();
        $nouvelle = plateforme('carte.signatureAutorite');

        $this->assertNotSame($ancienne, $nouvelle);
        $this->assertMatchesRegularExpression('#^plateforme/signature-autorite-[0-9a-f-]{36}\.png$#', $nouvelle);
        Storage::disk('local')->assertExists([$ancienne, $nouvelle]);
    }

    public function test_une_image_sans_transparence_est_acceptee_avec_un_avertissement(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);

        $this->enregistrer($this->complet(['cachetAutorite' => UploadedFile::fake()->image('cachet.png', 700, 700)]))
            ->assertSessionHasNoErrors()->assertSessionHas('succes')
            ->assertSessionHas('avertissement_plateforme', fn (string $m) => str_contains($m, '« Cachet de l’autorité »'));

        $this->assertNotNull(plateforme('carte.cachetAutorite'));
    }

    public function test_chaque_modification_est_inscrite_au_journal_avec_l_ancienne_et_la_nouvelle_valeur(): void
    {
        $admin = $this->connecterBackoffice(Role::SuperAdmin);
        $this->enregistrer($this->complet())->assertSessionHasNoErrors();

        $this->enregistrer(['qualiteAutorite' => 'Le Maire de l’Arrondissement N°1', 'nomAutorite' => 'Issa KABORÉ'])
            ->assertSessionHasNoErrors();

        $entree = AuditLog::query()->where('action', 'plateforme.modifiee')->where('entite_id', 'carte')->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $entree->acteur_id);
        $this->assertSame(['nomAutorite' => 'Awa OUÉDRAOGO'], $entree->ancienne_valeur);
        $this->assertSame(['nomAutorite' => 'Issa KABORÉ'], $entree->nouvelle_valeur);
        $this->assertNotNull($entree->created_at);
    }

    public function test_l_apercu_montre_recto_et_verso_specimen_sans_rien_enregistrer(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->deposerArmoiriesEtLogo();
        $fichiers = Storage::disk('local')->allFiles();
        $versions = DB::table('parametres_plateforme_versions')->count();

        $this->post(route('admin.plateforme.apercu', 'carte'), $this->complet(['nomAutorite' => 'Nom saisi non enregistré']))->assertOk()
            ->assertSee('data-face="recto"', false)->assertSee('data-face="verso"', false)
            ->assertSee('SPÉCIMEN')->assertSee('Nom saisi non enregistré')->assertSee('data:image/png;base64,', false)
            ->assertSee('Carte prête à être générée')
            // Recto validé : titre et type, titulaire, né(e) le, carte n°, expire le ; aucune information sur la boutique.
            ->assertSeeInOrder(['Burkina Faso', 'Carte d’identification', 'COMMERÇANT', 'Titulaire', 'Né(e) le', 'Carte n°', 'Expire le'])
            ->assertDontSee('Emplacement')->assertDontSee('Activité');

        $this->assertNull(plateforme('carte.nomAutorite'));
        $this->assertSame($versions, DB::table('parametres_plateforme_versions')->count());
        // Seul le filigrane des armoiries peut être mis en cache (dérivé des armoiries, sans donnée saisie).
        $this->assertSame($fichiers, array_values(array_filter(Storage::disk('local')->allFiles(), fn ($f) => ! str_starts_with($f, 'cartes/filigranes/'))));
    }

    public function test_la_signature_et_le_cachet_ne_sont_servis_par_aucune_adresse_publique(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->enregistrer($this->complet())->assertSessionHasNoErrors();

        foreach (['signatureAutorite', 'cachetAutorite'] as $champ) {
            $this->get('/plateforme/images/'.$champ)->assertNotFound();
        }
        // La page des réglages intègre les images (data URI) sans exposer le chemin du fichier.
        $this->get(route('admin.plateforme', ['section' => 'carte']))->assertOk()->assertDontSee(plateforme('carte.signatureAutorite'));
    }
}
