<?php

namespace Tests\Feature\Admin;

use App\Enums\EtatPhysiqueBoutique;
use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/** Charte, étape 3 : liste des boutiques comme la maquette (tableau de données) et tableau de bord par profil. */
class EcransCharteTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    private Site $raa;

    private Site $a04;

    protected function setUp(): void
    {
        parent::setUp();

        $auteur = User::factory()->role(Role::GestionnaireSites)->create();
        $bout = TypeBoutique::create(['code' => 'BOUT', 'libelle' => 'Boutique']);
        $poste = TypeBoutique::create(['code' => 'POSTE', 'libelle' => 'Poste']);
        $this->raa = Site::create(['statut' => StatutSite::Brouillon, 'code' => 'RAA', 'nom' => 'Raaga', 'cree_par' => $auteur->id]);
        $this->a04 = Site::create(['statut' => StatutSite::Soumis, 'code' => 'A04', 'nom' => 'Arrondissement N°4', 'cree_par' => $auteur->id,
            'soumis_par' => $auteur->id, 'soumis_le' => now()->subHours(3)]);
        // A04 : 8 boutiques R1-A (BOUT, 8,6 m²), 2 postes RC-BP ; RAA : 3 boutiques R1-A, dont une attribuée.
        foreach (range(1, 8) as $n) {
            $this->a04->boutiques()->create(['niveau' => 'R1', 'bloc' => 'A', 'numero' => sprintf('%03d', $n), 'surface' => 8.6, 'type_boutique_id' => $bout->id,
                'statut' => StatutBoutique::Disponible, 'etat_physique' => EtatPhysiqueBoutique::EnConstruction]);
        }
        foreach ([1, 2] as $n) {
            $this->a04->boutiques()->create(['niveau' => 'RC', 'bloc' => 'BP', 'numero' => sprintf('%03d', $n), 'type_boutique_id' => $poste->id,
                'statut' => StatutBoutique::Disponible, 'etat_physique' => EtatPhysiqueBoutique::EnConstruction]);
        }
        foreach ([1, 2, 3] as $n) {
            $this->raa->boutiques()->create(['niveau' => 'R1', 'bloc' => 'A', 'numero' => sprintf('%03d', $n), 'surface' => 9, 'type_boutique_id' => $bout->id,
                'statut' => $n === 3 ? StatutBoutique::Attribuee : StatutBoutique::Disponible]);
        }
        $this->raa->capacites()->create(['niveau' => 'R1', 'type_boutique_id' => $bout->id, 'nombre_prevu' => 4]);
        AuditLog::query()->create(['acteur_id' => $auteur->id, 'action' => 'boutiques.importees', 'entite_type' => 'Site', 'entite_id' => (string) $this->a04->id,
            'nouvelle_valeur' => ['fichier' => 'A04_locaux_DQE.xlsx', 'creees' => 10, 'mises_a_jour' => 0], 'created_at' => now()]);
    }

    private function codes(TestResponse $page): array
    {
        preg_match_all('#<td class="whitespace-nowrap font-bold" data-libelle="Code">([A-Z0-9-]+)</td>#', $page->getContent(), $m);

        return $m[1];
    }

    public function test_liste_des_boutiques_comme_la_maquette(): void
    {
        $this->connecterBackoffice(Role::GestionnaireSites);
        $page = $this->get(route('admin.referencement.boutiques'))->assertOk();

        // Boutons de page, filtres, colonnes, 10 lignes par défaut.
        $page->assertSee('Toutes les boutiques référencées, tous sites confondus.')->assertSee('data-export-tableau="boutiques"', false)
            ->assertSee('Importer un fichier')->assertSee('Ajouter des boutiques')->assertSee('Réinitialiser');
        foreach (['Site', 'Niveau', 'Type', 'Statut commercial'] as $filtre) {
            $page->assertSee('class="adm-libelle-petit">'.$filtre.'</label>', false);
        }
        $page->assertDontSee('class="adm-libelle-petit">Zone / bloc</label>', false)
            ->assertSeeInOrder(['Code', 'Site', 'Niveau', 'Zone', 'Type', 'Surface (m²)', 'Statut physique', 'Statut commercial', 'Actions'])
            ->assertSee('⇅')->assertSee('aria-sort="ascending"', false)->assertSee('Affichage de 1 à 10 sur 13 boutiques')
            ->assertSee('<span class="adm-badge adm-badge-neutre">En construction</span>', false)->assertSee('<span class="adm-badge adm-badge-ok">Disponible</span>', false)
            ->assertSee('8,6');
        $this->assertCount(10, $this->codes($page));

        // « Modifier » seulement pour une boutique libre (site en brouillon, boutique non attribuée).
        $raa = $this->raa->boutiques()->orderBy('numero')->get();
        $page = $this->get(route('admin.referencement.boutiques', ['site' => $this->raa->id]))->assertOk();
        $page->assertSee(route('admin.referencement.boutiques.edit', $raa[0]))->assertDontSee(route('admin.referencement.boutiques.edit', $raa[2]));
        $this->assertSame(['RAA-R1-A-001', 'RAA-R1-A-002', 'RAA-R1-A-003'], $this->codes($page));
    }

    public function test_pagination_tri_recherche_et_filtres_combines(): void
    {
        $this->connecterBackoffice(Role::Validateur);
        $this->assertSame(['RAA-R1-A-001', 'RAA-R1-A-002', 'RAA-R1-A-003'], $this->codes($this->get(route('admin.referencement.boutiques', ['page' => 2]))));
        $this->assertSame('RAA-R1-A-003', $this->codes($this->get(route('admin.referencement.boutiques', ['tri' => '-code'])))[0]);
        $this->assertSame(['A04-RC-BP-001', 'A04-RC-BP-002'], $this->codes($this->get(route('admin.referencement.boutiques', ['q' => 'BP']))));

        $page = $this->get(route('admin.referencement.boutiques', ['site' => $this->a04->id, 'niveau' => 'R1', 'statut' => 'DISPONIBLE', 'tri' => '-code', 'lignes' => 25]));
        $this->assertSame('A04-R1-A-008', $this->codes($page)[0]);
        $this->assertCount(8, $this->codes($page));
        $page->assertSee('(filtrées sur 13)')->assertSee('<option value="25" selected>', false);
        // Filtre masqué (zone, venu des capacités prévues) : appliqué et conservé.
        $this->assertCount(2, $this->codes($this->get(route('admin.referencement.boutiques', ['zone' => 'BP']))));
    }

    public function test_export_filtre_sans_colonne_financiere_pour_le_gestionnaire(): void
    {
        $this->connecterBackoffice(Role::GestionnaireSites);
        $fichier = tempnam(sys_get_temp_dir(), 'export');
        file_put_contents($fichier, $this->get(route('admin.referencement.boutiques', ['site' => $this->raa->id, 'export' => 'xlsx']))->assertOk()->streamedContent());
        $lignes = IOFactory::load($fichier)->getActiveSheet()->toArray();
        $this->assertSame(['Code', 'Site', 'Niveau', 'Zone', 'Type', 'Surface (m²)', 'Statut physique', 'Statut commercial'], $lignes[0]);
        $this->assertSame(['RAA-R1-A-001', 'RAA-R1-A-002', 'RAA-R1-A-003'], array_column(array_slice($lignes, 1), 0));
        $this->assertDoesNotMatchRegularExpression('/loyer|caution|avance|fcfa|montant/i', implode(' ', $lignes[0]));
        $this->get(route('admin.referencement.boutiques'))->assertDontSee('FCFA')->assertDontSee('Loyer');
    }

    public function test_tableau_de_bord_par_profil(): void
    {
        $cartes = fn (TestResponse $p) => preg_match_all('#data-indicateur="([a-z]+)"#', $p->getContent(), $m) ? $m[1] : [];

        // VALIDATEUR : actions en attente, KYC, cautions, boutiques ; le site soumis est à examiner.
        $this->connecterBackoffice(Role::Validateur);
        $page = $this->get('/admin')->assertOk()->assertSee('Bonjour. 1 élément attend votre décision.');
        $this->assertSame(['actions', 'kyc', 'cautions', 'boutiques'], $cartes($page));
        $page->assertSee('<td class="font-semibold">Site</td>', false)->assertSee('A04 · Arrondissement N°4')->assertSee('Gestionnaire des sites et boutiques')
            ->assertSee('Examiner')->assertSee('Sites : boutiques prévues et importées')->assertSee('3 boutiques importées sur 4 prévues')
            ->assertSee('Derniers imports')->assertSee('A04_locaux_DQE.xlsx')->assertSee('role="progressbar"', false);

        // GESTIONNAIRE : aucun indicateur financier.
        $this->connecterBackoffice(Role::GestionnaireSites);
        $page = $this->get('/admin')->assertOk();
        $this->assertSame(['actions', 'boutiques', 'sites', 'capacites'], $cartes($page));
        $page->assertDontSee('Cautions à rapprocher')->assertDontSee('Engagements')->assertDontSee('FCFA')->assertSee('Derniers imports')
            ->assertSee('<span class="adm-indicateur-valeur">13</span>', false);

        // RÉGULATEUR : ses indicateurs ; pas de derniers imports (onglet non autorisé).
        $this->connecterBackoffice(Role::Regulateur);
        $page = $this->get('/admin')->assertOk();
        $this->assertSame(['actions', 'kyc', 'cautions', 'boutiques'], $cartes($page));
        $page->assertDontSee('Derniers imports')->assertSee('Rien à traiter pour le moment.');

        // ADMIN (support) : ni référencement ni finances.
        $this->connecterBackoffice(Role::Admin);
        $page = $this->get('/admin')->assertOk();
        // Vérifications d'identité : nombre de dossiers à traiter, sans aucun montant.
        $this->assertSame(['actions', 'kyc', 'citoyens', 'institutions'], $cartes($page));
        $page->assertDontSee('Sites : boutiques prévues et importées')->assertDontSee('Derniers imports')->assertDontSee('Cautions');

        // Rechargement du seul tableau « Derniers imports ».
        $this->connecterBackoffice(Role::Validateur);
        $this->withHeader('X-Tableau', 'derniers-imports')->get('/admin')->assertOk()->assertDontSee('<html', false)->assertSee('A04_locaux_DQE.xlsx');
    }
}
