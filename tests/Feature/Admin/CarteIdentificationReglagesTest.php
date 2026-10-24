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

    /** Armoiries et logo déposés dans les paramètres (les fichiers fournis par défaut ne suffisent pas pour la carte). */
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

    public function test_les_armoiries_et_le_logo_fournis_par_defaut_ne_suffisent_pas(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->enregistrer($this->complet())->assertSessionHasNoErrors();

        // Fichiers fournis par le code toujours affichés sur le site, mais aucun dépôt : réglages manquants.
        $this->assertNotNull(ImagesPlateforme::url('armoiries'));
        $this->assertSame(['armoiries', 'logoHorizontal'], ReglagesCarte::manquants());
        $this->get(route('admin.plateforme', ['section' => 'carte']))->assertOk()
            ->assertSee('data-manquant="armoiries"', false)->assertSee('data-manquant="logoHorizontal"', false)
            ->assertSee('(fichier à déposer dans la section Identité de la plateforme)');

        $this->deposerArmoiriesEtLogo();
        $this->assertTrue(ReglagesCarte::prete());
    }

    public function test_le_numero_vert_est_celui_des_contacts_affiche_tel_qu_il_est_enregistre(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->deposerArmoiriesEtLogo();
        $this->enregistrer($this->complet())->assertSessionHasNoErrors();
        $numero = plateforme('contacts.numeroVert');
        $this->assertNotNull($numero);

        $this->post(route('admin.plateforme.apercu', 'carte'), $this->complet())->assertOk()
            ->assertSee('<strong>'.$numero.'</strong>', false);

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
            // Recto validé : titulaire, né(e) le, carte n°, expire le ; aucune information sur la boutique.
            ->assertSeeInOrder(['Carte d’identification · Commerçant', 'Titulaire', 'Né(e) le', 'Carte n°', 'Expire le'])
            ->assertDontSee('Emplacement')->assertDontSee('Activité');

        $this->assertNull(plateforme('carte.nomAutorite'));
        $this->assertSame($versions, DB::table('parametres_plateforme_versions')->count());
        $this->assertSame($fichiers, Storage::disk('local')->allFiles());
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
