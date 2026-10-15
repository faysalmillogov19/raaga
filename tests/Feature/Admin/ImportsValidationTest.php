<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutSite;
use App\Models\AuditLog;
use App\Models\ImportValidation;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use App\Notifications\DecisionRendue;
use Database\Seeders\NiveauxSeeder;
use Database\Seeders\ZonesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/** Statut de validation choisi à l'import et validation d'un import entier (« À valider › Imports »). */
class ImportsValidationTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private const ENTETES_SITES = ['Code site', 'Nom officiel', 'Ville', 'Arrondissement', 'Adresse', 'Latitude', 'Longitude', "Maître d'ouvrage", "Maître d'œuvre",
        'Phase', 'Livraison prévue', 'Référence des plans', 'Version des plans', 'Source des données', 'Contrôle'];

    private const ENTETES_CAPACITES = ['Code site', 'Niveau', 'Zone', 'Type de local', 'Nombre prévu', 'Surface unitaire (m²)', 'Surface totale saisie (m²)',
        'Surface retenue (m²)', 'Source', 'Observations', 'Contrôle'];

    private User $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([NiveauxSeeder::class, ZonesSeeder::class]);
        $this->creerActeurs();
        $this->gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->en($this->gestionnaire)->post(route('admin.sites.types.store'), ['code' => 'BOUT', 'libelle' => 'Petite boutique', 'attribuable' => 1]);
        $this->en($this->validateur)->post(route('admin.sites.types.confirmer', TypeBoutique::query()->where('code', 'BOUT')->sole()))->assertSessionHas('succes');
        // SAR et RAA ont des boutiques ; A04 n'en a aucune.
        foreach (['SAR', 'RAA'] as $code) {
            $this->en($this->gestionnaire)->post(route('admin.sites.store'), ['code' => $code, 'nom' => 'Marché '.$code, 'region' => 'Centre', 'ville' => 'Ouagadougou']);
            $this->en($this->gestionnaire)->post(route('admin.referencement.boutiques.store', $this->site($code)), [
                'niveau' => 'R1', 'bloc' => 'A', 'numero' => 1, 'surface' => 9, 'type_boutique_id' => TypeBoutique::query()->value('id'), 'etat_physique' => 'LIVREE',
            ]);
        }
    }

    private function site(string $code): Site
    {
        return Site::query()->where('code', $code)->sole();
    }

    /** Import « Sites et capacités » confirmé avec le statut choisi (null : aucun choix envoyé). */
    private function importer(array $codes, ?string $statut, ?User $auteur = null): TestResponse
    {
        $classeur = new Spreadsheet;
        $classeur->getActiveSheet()->setTitle('Sites')->fromArray(self::ENTETES_SITES);
        $classeur->createSheet()->setTitle('Capacités')->fromArray(self::ENTETES_CAPACITES);
        foreach ($codes as $i => $code) {
            $classeur->getSheetByName('Sites')->fromArray([$code, 'Marché '.$code, 'Ouagadougou', '1'], null, 'A'.($i + 2));
            $classeur->getSheetByName('Sites')->setCellValue('J'.($i + 2), 'Études');
            $classeur->getSheetByName('Capacités')->fromArray([$code, 'R1', 'A', 'BOUT', 2], null, 'A'.($i + 2));
        }
        $chemin = tempnam(sys_get_temp_dir(), 'sites').'.xlsx';
        (new Xlsx($classeur))->save($chemin);
        $this->en($auteur ?? $this->gestionnaire)->post(route('admin.sites.excel.deposer'), ['fichier_sites' => new UploadedFile($chemin, 'sites.xlsx', null, null, true)])
            ->assertRedirect(route('admin.sites.excel.apercu'));

        return $this->en($auteur ?? $this->gestionnaire)->post(route('admin.sites.excel.confirmer'), array_filter(['statut_apres' => $statut]));
    }

    public function test_choix_du_statut_a_l_import(): void
    {
        $classeur = new Spreadsheet;
        $classeur->getActiveSheet()->setTitle('Sites')->fromArray([self::ENTETES_SITES, ['SAR', 'Marché SAR', 'Ouagadougou', '1']]);
        $classeur->getSheetByName('Sites')->setCellValue('J2', 'Études');
        $classeur->createSheet()->setTitle('Capacités')->fromArray(self::ENTETES_CAPACITES);
        $chemin = tempnam(sys_get_temp_dir(), 'sites').'.xlsx';
        (new Xlsx($classeur))->save($chemin);
        $this->en($this->gestionnaire)->post(route('admin.sites.excel.deposer'), ['fichier_sites' => new UploadedFile($chemin, 'sites.xlsx', null, null, true)]);

        // Choix obligatoire dans la fenêtre, « Soumettre au VALIDATEUR » coché par défaut.
        $this->en($this->gestionnaire)->get(route('admin.sites.excel.apercu'))->assertOk()->assertSee('Statut après l’import')
            ->assertSee('name="statut_apres" value="SOUMETTRE" id="statut-sites-soumettre" class="mt-1" required checked', false)
            ->assertSee('name="statut_apres" value="BROUILLON"', false);

        // « Brouillon » : les sites restent en brouillon ; le statut choisi est au journal de l'import.
        $this->en($this->gestionnaire)->post(route('admin.sites.excel.confirmer'), ['statut_apres' => 'BROUILLON'])->assertSessionHas('succes');
        $this->assertSame(StatutSite::Brouillon, $this->site('SAR')->statut);
        $journal = AuditLog::query()->where('action', 'sites.importes')->sole();
        $this->assertSame('BROUILLON', $journal->nouvelle_valeur['statut_apres']);
        $this->assertSame('BROUILLON', ImportValidation::query()->findOrFail($journal->nouvelle_valeur['import_id'])->statutValidation());
        $this->en($this->gestionnaire)->get(route('admin.referencement.imports.entite', 'sites'))->assertSee('Statut de validation')->assertSee('data-validation="BROUILLON"', false);
    }

    public function test_sites_soumis_et_site_sans_boutique_laisse_en_brouillon(): void
    {
        $this->importer(['SAR', 'A04'], 'SOUMETTRE')
            ->assertSessionHas('succes', fn (string $m) => str_contains($m, '1 site soumis, 1 resté en brouillon : A04, ajoutez au moins une boutique avant de soumettre le site.'));

        $this->assertSame(StatutSite::Soumis, $this->site('SAR')->statut);
        $this->assertSame(StatutSite::Brouillon, $this->site('A04')->statut);
        $this->assertSame('SOUMETTRE', AuditLog::query()->where('action', 'sites.importes')->sole()->nouvelle_valeur['statut_apres']);
        $import = ImportValidation::query()->sole();
        $this->assertSame('SOUMIS', $import->statutValidation());
        $this->assertSame('Ajoutez au moins une boutique avant de soumettre le site.', $import->sites()->whereHas('site', fn ($q) => $q->where('code', 'A04'))->value('raison_brouillon'));
    }

    public function test_rubrique_imports(): void
    {
        $this->importer(['SAR', 'RAA'], 'SOUMETTRE');
        $import = ImportValidation::query()->sole();

        $rubrique = $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'imports'))->assertOk();
        $rubrique->assertSee('sites.xlsx · 2 sites (RAA, SAR)')->assertSee('data-non-groupable', false)->assertDontSee('name="elements[]" value="import:'.$import->id.'"', false);

        // Détail : résumé, sites (liens), fichier d'origine, décision.
        $this->en($this->validateur)->get(route('admin.imports-validation.show', $import))->assertOk()
            ->assertSee('Résumé par site, niveau et type')->assertSee('Écart avec les capacités prévues')->assertSee('Avertissements de l’aperçu')
            ->assertSee('href="'.route('admin.sites.show', $this->site('SAR')).'"', false)->assertSee(route('admin.imports-validation.fichier', $import))
            ->assertSee('Valider l’import')->assertSee('Rejeter l’import');
    }

    public function test_validation_d_un_import_confirme_ses_sites(): void
    {
        Notification::fake();
        $this->importer(['SAR', 'RAA'], 'SOUMETTRE');
        $import = ImportValidation::query()->sole();

        $this->en($this->validateur)->post(route('admin.imports-validation.confirmer', $import))->assertSessionHas('succes', '2 confirmés.');
        $this->assertSame([StatutSite::Confirme, StatutSite::Confirme], [$this->site('SAR')->statut, $this->site('RAA')->statut]);
        $this->assertSame('VALIDE', $import->refresh()->statutValidation());
        // Une seule notification, à l'auteur de l'import.
        Notification::assertSentToTimes($this->gestionnaire, DecisionRendue::class, 1);
        $this->en($this->gestionnaire)->get(route('admin.referencement.imports.entite', 'sites'))->assertSee('data-validation="VALIDE"', false);
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'imports'))->assertSee('Rien à valider dans cette rubrique.');
    }

    public function test_rejet_avec_motif(): void
    {
        $this->importer(['SAR', 'RAA'], 'SOUMETTRE');
        $import = ImportValidation::query()->sole();

        $this->en($this->validateur)->post(route('admin.imports-validation.rejeter', $import))->assertSessionHasErrors('motif');
        $this->en($this->validateur)->post(route('admin.imports-validation.rejeter', $import), ['motif' => 'Capacités à revoir'])->assertSessionHas('succes', '2 rejetés.');
        $this->assertSame([StatutSite::Brouillon, 'Capacités à revoir'], [$this->site('SAR')->statut, $this->site('SAR')->motif_rejet]);
        $this->assertSame('REJETE', $import->refresh()->statutValidation());
        $this->en($this->gestionnaire)->get(route('admin.referencement.imports.entite', 'sites'))->assertSee('data-validation="REJETE"', false)
            ->assertSee('Motif du rejet : Capacités à revoir');
    }

    public function test_site_deja_traite_separement(): void
    {
        $this->importer(['SAR', 'RAA'], 'SOUMETTRE');
        $import = ImportValidation::query()->sole();

        // SAR rejeté depuis « À valider › Sites » : retiré de l'import.
        $this->en($this->validateur)->post(route('admin.sites.rejeter', $this->site('SAR')), ['motif' => 'Adresse à préciser'])->assertSessionHas('succes');
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'imports'))->assertSee('1 site (RAA)');

        $this->en($this->validateur)->post(route('admin.imports-validation.confirmer', $import))->assertSessionHas('succes', '1 confirmé, 1 déjà traité.');
        $this->assertSame([StatutSite::Brouillon, StatutSite::Confirme], [$this->site('SAR')->statut, $this->site('RAA')->statut]);
        $this->assertSame('PARTIEL', $import->refresh()->statutValidation());
        $this->en($this->gestionnaire)->get(route('admin.referencement.imports.entite', 'sites'))->assertSee('Partiellement validé');
    }

    public function test_auto_validation_refusee(): void
    {
        $this->importer(['SAR', 'RAA'], 'SOUMETTRE');
        $import = ImportValidation::query()->sole();
        $import->forceFill(['auteur_id' => $this->validateur->id])->save(); // import fait par ce VALIDATEUR

        $this->en($this->validateur)->post(route('admin.imports-validation.confirmer', $import))
            ->assertSessionHas('erreur', __('admin.a_valider.erreurs.auto_validation'));
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['import:'.$import->id], 'decision' => 'confirmer', 'mode' => 'ligne'])
            ->assertSessionHas('erreur');
        $this->assertSame(StatutSite::Soumis, $this->site('SAR')->statut);
        $this->en($this->validateur)->get(route('admin.imports-validation.show', $import))->assertSee('data-auto-validation', false)->assertDontSee('Valider l’import</button>', false);
    }
}
