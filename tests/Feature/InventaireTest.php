<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Models\User;
use App\Support\Inventaire\BlocsAuto;
use App\Support\Inventaire\Masquage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * raaga:inventaire : inventaires locaux, refusés hors poste de développement avant tout accès à la base ; seules les
 * parties encadrées par les balises AUTO sont remplacées ; aucune donnée personnelle ni aucun secret en clair.
 */
class InventaireTest extends TestCase
{
    use RefreshDatabase;

    private string $dossier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dossier = storage_path('framework/testing/inventaire-'.getmypid());
        File::deleteDirectory($this->dossier);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dossier);
        parent::tearDown();
    }

    public function test_refus_en_production_et_en_test_avant_tout_acces_a_la_base(): void
    {
        foreach (['production', 'testing'] as $environnement) {
            $this->app['env'] = $environnement;
            $requetes = 0;
            DB::listen(function () use (&$requetes) {
                $requetes++;
            });

            $this->assertSame(1, Artisan::call('raaga:inventaire', ['--dossier' => $this->dossier]));
            $this->assertStringContainsString('ne tourne que sur un poste de développement (APP_ENV=local). Environnement actuel : « '.$environnement.' »', Artisan::output());

            $this->assertSame(0, $requetes, "Aucune requête avant le refus ({$environnement}).");
            $this->assertDirectoryDoesNotExist($this->dossier);
        }
    }

    public function test_verifier_production_controle_le_refus(): void
    {
        $this->app['env'] = 'production';

        Artisan::call('raaga:verifier-production');
        $sortie = Artisan::output();

        $this->assertStringNotContainsString('raaga:inventaire serait autorisée', $sortie);
        $this->assertStringNotContainsString('raaga:inventaire ne refuse pas', $sortie);
        $this->assertDirectoryDoesNotExist(storage_path('framework/inventaire-refuse'));
    }

    public function test_seules_les_parties_auto_sont_remplacees(): void
    {
        $document = "# Titre\n\nTexte rédigé à la main, mot pour mot.\n\n".BlocsAuto::bloc('routes', 'ancien contenu')
            ."\n\n## Parcours rédigé\n\nÉtape 1 conservée.\n";

        $resultat = BlocsAuto::remplacer($document, ['entete' => 'Généré le 01/01/2027', 'routes' => 'nouveau contenu', 'chiffres' => 'tableau']);

        $this->assertStringContainsString("Texte rédigé à la main, mot pour mot.\n", $resultat);
        $this->assertStringContainsString("## Parcours rédigé\n\nÉtape 1 conservée.\n", $resultat);
        $this->assertStringContainsString(BlocsAuto::bloc('routes', 'nouveau contenu'), $resultat);
        $this->assertStringNotContainsString('ancien contenu', $resultat);
        // En-tête sous le titre ; bloc absent ajouté en fin de fichier.
        $this->assertStringStartsWith("# Titre\n\n".BlocsAuto::bloc('entete', 'Généré le 01/01/2027'), $resultat);
        $this->assertStringEndsWith(BlocsAuto::bloc('chiffres', 'tableau')."\n", $resultat);

        // Deuxième passage : même résultat, rien n'est dupliqué.
        $this->assertSame($resultat, BlocsAuto::remplacer($resultat, ['entete' => 'Généré le 01/01/2027', 'routes' => 'nouveau contenu', 'chiffres' => 'tableau']));
    }

    public function test_masquage_des_donnees_personnelles_et_des_secrets(): void
    {
        $texte = 'Tél. 70 12 34 56, +22676543210, NIP 12345678901234567, IFU 00012345A, contact@exemple.bf, '
            .'clé base64:'.str_repeat('Ab3', 15).' ; 802 boutiques le 19/10/2026 à 13:00, objectif 1'."\u{202F}".'000'."\u{202F}".'000'."\u{202F}".'000 FCFA';

        $masque = Masquage::texte($texte);

        foreach (['70 12 34 56', '76543210', '12345678901234567', '00012345A', 'contact@exemple.bf', str_repeat('Ab3', 15)] as $secret) {
            $this->assertStringNotContainsString($secret, $masque);
        }
        $this->assertStringContainsString('802 boutiques le 19/10/2026 à 13:00', $masque);
        $this->assertStringContainsString('1'."\u{202F}".'000'."\u{202F}".'000'."\u{202F}".'000 FCFA', $masque);
    }

    public function test_execution_locale_conserve_le_redige_et_ne_montre_aucune_donnee_personnelle(): void
    {
        $citoyen = User::factory()->role(Role::Citoyen)->create([
            'nom' => 'ZONGNABA', 'prenoms' => 'Awaissa', 'email' => 'awaissa.zongnaba@exemple.bf',
            'telephone' => '+22670998877', 'statut_kyc' => StatutKyc::Valide,
        ]);
        Boutiques::campagneOuverte(['nom' => 'Campagne essai']);
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A'), 'B98765432');

        File::ensureDirectoryExists($this->dossier);
        File::put($this->dossier.'/INVENTAIRE-FRONT-OFFICE.md', "# Inventaire du front-office Raaga\n\n## Parcours\n\nTexte rédigé conservé.\n\n"
            .BlocsAuto::bloc('chiffres-base', 'ancien'));

        $this->app['env'] = 'local';
        $this->artisan('raaga:inventaire', ['--dossier' => $this->dossier])->assertSuccessful()->expectsOutputToContain('Inventaires à jour');

        $front = File::get($this->dossier.'/INVENTAIRE-FRONT-OFFICE.md');
        $back = File::get($this->dossier.'/INVENTAIRE-BACK-OFFICE.md');
        $this->assertStringContainsString("## Parcours\n\nTexte rédigé conservé.\n", $front);
        $this->assertStringNotContainsString("\nancien\n", $front);
        foreach (['entete', 'routes-publiques', 'composants', 'chiffres-base'] as $bloc) {
            $this->assertStringContainsString(BlocsAuto::debut($bloc), $front);
        }
        foreach (['entete', 'matrice-droits', 'menus', 'routes-admin', 'composants-admin'] as $bloc) {
            $this->assertStringContainsString(BlocsAuto::debut($bloc), $back);
        }
        $this->assertMatchesRegularExpression('/régénérées le \d{2}\/\d{2}\/\d{4} à \d{2}:\d{2}, commit [0-9a-f]{7,}/', $front);
        $this->assertStringContainsString('Campagne essai', $front);
        $this->assertStringContainsString('| /boutiques/plan/{code}/reserver | boutiques.reserver |', $front);
        $this->assertStringContainsString('### Validateur', $back);

        foreach ([$front, $back] as $contenu) {
            foreach (['ZONGNABA', 'Awaissa', 'awaissa.zongnaba', '70998877', 'B98765432'] as $donnee) {
                $this->assertStringNotContainsString($donnee, $contenu);
            }
        }
    }
}
