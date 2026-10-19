<?php

namespace Tests\Feature\Compte;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutKyc;
use App\Enums\StatutSite;
use App\Models\Boutique;
use App\Models\CampagneBoutiques;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use Database\Seeders\ReferentielsSitesSeeder;
use Database\Seeders\TypesBoutiqueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * Types proposés par campagne (jamais la chambre froide CF), plan filtré par site et par type, détail complet de chaque
 * emplacement (montants pour le citoyen, aucun pour le GESTIONNAIRE), description des types pour le citoyen.
 */
class TypesCampagneTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private Site $site;

    private Site $autre;

    private CampagneBoutiques $campagne;

    /** @var array<string, TypeBoutique> */
    private array $types;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
        $this->seed(ReferentielsSitesSeeder::class);
        $this->types = TypeBoutique::query()->whereIn('code', ['BOUT', 'MAG', 'PP', 'CF'])->get()->keyBy('code')->all();
        $site = fn (string $code, string $nom) => Site::create(['statut' => StatutSite::Confirme, 'code' => $code, 'nom' => $nom, 'region' => 'Centre',
            'commune' => 'Ouagadougou', 'adresse' => 'Avenue', 'version' => 1, 'cree_par' => $this->regulateur->id]);
        $this->site = $site('RAA', 'Marché Raaga');
        $this->autre = $site('SAR', 'Marché de Sarfalao');
        foreach ([['RC', 'A', 1, 'BOUT', 9, null], ['RC', 'BP', 19, 'PP', 12, 'Évier double et carrelage'], ['RC', 'BP', 20, 'CF', 30, null], ['RC', 'M', 1, 'MAG', 40, null]] as [$n, $bloc, $num, $type, $surface, $obs]) {
            $this->site->boutiques()->create(['niveau' => $n, 'bloc' => $bloc, 'numero' => $num, 'surface' => $surface, 'observations' => $obs,
                'type_boutique_id' => $this->types[$type]->id, 'statut' => StatutBoutique::Disponible]);
        }
        $this->autre->boutiques()->create(['niveau' => 'RC', 'bloc' => 'A', 'numero' => 1, 'surface' => 9, 'type_boutique_id' => $this->types['BOUT']->id, 'statut' => StatutBoutique::Disponible]);
        $ids = array_map(fn (TypeBoutique $t) => $t->id, $this->types);
        Boutiques::grilleConfirmee($this->site, $ids, $this->regulateur);
        // Campagne ouverte sur le premier site : boutiques (BOUT) et pôle de préparation (PP) seulement.
        $this->campagne = CampagneBoutiques::create([
            'statut' => StatutCampagneBoutiques::Ouverte, 'nom' => 'Réservation 2027', 'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addMonth(),
            'canaux_actifs' => ['ORANGE_MONEY'], 'sites' => [$this->site->id],
            'types' => [$this->types['BOUT']->id, $this->types['PP']->id], 'version' => 2, 'cree_par' => $this->regulateur->id,
        ]);
    }

    private function boutique(string $code): Boutique
    {
        return Boutique::query()->where('code', $code)->sole();
    }

    public function test_types_obligatoires_et_chambre_froide_jamais_proposee(): void
    {
        $formulaire = [
            'nom' => 'Pôle boucherie 2027', 'date_ouverture' => now()->addDay()->format('Y-m-d\TH:i'), 'date_cloture' => now()->addMonth()->format('Y-m-d\TH:i'),
            'canaux_actifs' => ['ORANGE_MONEY'], 'sites' => [$this->site->id],
        ];
        $page = $this->en($this->regulateur)->get(route('admin.boutiques.create'))->assertOk()->assertSee('data-types-campagne', false)->getContent();
        $this->assertStringContainsString('value="'.$this->types['PP']->id.'"', $page);
        $this->assertStringNotContainsString('CF · Chambre froide', $page);

        $this->en($this->regulateur)->post(route('admin.boutiques.store'), $formulaire)->assertSessionHasErrors('types');
        $this->en($this->regulateur)->post(route('admin.boutiques.store'), [...$formulaire, 'types' => [$this->types['CF']->id]])->assertSessionHasErrors('types.0');
        $this->en($this->regulateur)->post(route('admin.boutiques.store'), [...$formulaire, 'types' => [$this->types['PP']->id, $this->types['MAG']->id],
            'limites' => [$this->types['PP']->id => 1, $this->types['MAG']->id => 1]])->assertSessionHasNoErrors();

        // Le VALIDATEUR voit les types choisis (version figée et fiche).
        $c = CampagneBoutiques::query()->where('nom', 'Pôle boucherie 2027')->sole();
        $this->en($this->regulateur)->post(route('admin.boutiques.soumettre', $c))->assertSessionHas('succes');
        $this->assertStringContainsString('PP · Pôle : préparation et découpe privative', $c->versions()->latest('numero')->value('parametres')['types']);
        $this->en($this->validateur)->get(route('admin.boutiques.show', $c))->assertOk()
            ->assertSee('<span class="campagne-fiche-code">PP</span> Pôle : préparation et découpe privative', false)
            ->assertSee('<span class="campagne-fiche-code">MAG</span> Magasin', false);
    }

    public function test_plan_filtre_par_site_et_par_type_avec_legende(): void
    {
        $plan = fn (array $q) => $this->get(route('boutiques', ['site' => $this->site->id, ...$q]))->assertOk();

        // Types de la campagne seulement : ni le magasin (bloc M), ni la chambre froide, ni l'autre site.
        $page = $plan(['niveau' => 'RC', 'bloc' => 'BP']);
        $page->assertSee('RAA-RC-BP-019')->assertDontSee('RAA-RC-BP-020')->assertSee('data-legende-types', false)
            ->assertSee('Tous les types')->assertSee('<span class="plan-sigle">PP</span>', false);
        $this->assertStringNotContainsString('bloc=M', $page->getContent());
        $this->assertStringNotContainsString('SAR · Marché de Sarfalao', $page->getContent());
        $plan(['niveau' => 'RC', 'bloc' => 'M'])->assertDontSee('RAA-RC-M-001');

        // Filtre par type : seuls les blocs et les boutiques de ce type.
        $pp = $plan(['type' => $this->types['PP']->id])->getContent();
        $this->assertStringContainsString('RAA-RC-BP-019', $pp);
        $this->assertStringNotContainsString('RAA-RC-A-001', $pp);
        $this->assertStringContainsString('data-type="PP"', $pp);

        // Hors campagne : récapitulatif introuvable, réservation refusée.
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+22670123456']);
        $this->actingAs($citoyen)->get(route('boutiques.boutique', 'RAA-RC-M-001'))->assertNotFound();
        $this->actingAs($citoyen)->get(route('boutiques.boutique', 'RAA-RC-BP-020'))->assertNotFound();
        Boutiques::reserver($this, $citoyen, $this->boutique('RAA-RC-M-001'))->assertSessionHasErrors(['boutique' => __('public.boutiques.erreurs.hors_campagne')]);
        Boutiques::reserver($this, $citoyen, $this->boutique('RAA-RC-BP-019'))->assertSessionHasNoErrors();

        // Compteurs : types de la campagne seulement (2 boutiques).
        $this->assertSame(['disponible' => 1, 'pre_reservee' => 1, 'reservee' => 0, 'attribuee' => 0], $this->campagne->compteurs());
    }

    public function test_detail_complet_pour_le_citoyen_sans_montant_pour_le_gestionnaire(): void
    {
        $pp = $this->boutique('RAA-RC-BP-019');
        $description = 'Zone de préparation et de découpe privative du pôle boucherie-poissonnerie.';
        $this->assertSame($description, $pp->type->description_citoyen);

        // Plan : détail au survol ou au clic (code, site, niveau, bloc, type et description, surface, observations, montants).
        $page = $this->get(route('boutiques', ['site' => $this->site->id, 'niveau' => 'RC', 'bloc' => 'BP']))->assertOk()->getContent();
        $this->assertStringContainsString('data-plan-detail', $page);
        // @js : JSON.parse('…') avec les guillemets échappés en ".
        preg_match("/@mouseenter=\"d = JSON\.parse\('(.+?)'\)\" @focus/s", $page, $m);
        $detail = json_decode(str_replace('\u0022', '"', html_entity_decode($m[1])), true);
        // 12 m² × 5 000 FCFA = 60 000 FCFA par mois ; caution 12 mois, avance 3 mois.
        $this->assertSame(['RAA-RC-BP-019', 'RAA · Marché Raaga', 'BP', 'PP', 'Pôle : préparation et découpe privative', $description, '12,00 m²', 'Évier double et carrelage'],
            [$detail['code'], $detail['site'], $detail['bloc'], $detail['type_code'], $detail['type'], $detail['type_description'], $detail['surface'], $detail['observations']]);
        $this->assertStringStartsWith('RC · ', $detail['niveau']);
        $this->assertSame(["60\u{00A0}000\u{00A0}FCFA", "720\u{00A0}000\u{00A0}FCFA", "180\u{00A0}000\u{00A0}FCFA", "900\u{00A0}000\u{00A0}FCFA"], array_values($detail['montants']));

        // Récapitulatif avant réservation.
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+22670123456']);
        $this->actingAs($citoyen)->get(route('boutiques.boutique', $pp->code))->assertOk()
            ->assertSeeInOrder(['RAA-RC-BP-019', 'RAA · Marché Raaga', 'Rez-de-chaussée', 'BP', 'PP', 'Pôle : préparation et découpe privative', $description,
                '12,00 m²', 'Évier double et carrelage', "60\u{00A0}000\u{00A0}FCFA", "720\u{00A0}000\u{00A0}FCFA", "180\u{00A0}000\u{00A0}FCFA", "900\u{00A0}000\u{00A0}FCFA"]);

        // GESTIONNAIRE : même détail dans la fiche de la boutique, sans aucun montant.
        $gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->en($gestionnaire)->get(route('admin.referencement.boutiques.show', $pp))->assertOk()
            ->assertSee('PP · Pôle : préparation et découpe privative')->assertSee($description)->assertSee('Évier double et carrelage')->assertDontSee('FCFA');
    }

    public function test_description_pour_le_citoyen_seeder_et_validation(): void
    {
        // Seeder : description par défaut pour chaque type, jamais écrasée ensuite.
        foreach (TypesBoutiqueSeeder::DESCRIPTIONS as $code => $texte) {
            $this->assertSame($texte, TypeBoutique::query()->where('code', $code)->value('description_citoyen'), $code);
        }
        TypeBoutique::query()->where('code', 'BOUT')->update(['description_citoyen' => 'Texte du gestionnaire']);
        TypeBoutique::query()->where('code', 'MAG')->update(['description_citoyen' => null]);
        $this->seed(TypesBoutiqueSeeder::class);
        $this->assertSame('Texte du gestionnaire', TypeBoutique::query()->where('code', 'BOUT')->value('description_citoyen'));
        $this->assertSame(TypesBoutiqueSeeder::DESCRIPTIONS['MAG'], TypeBoutique::query()->where('code', 'MAG')->value('description_citoyen'));

        // Type ajouté par le GESTIONNAIRE (non « par défaut ») : la description pour le citoyen passe quand même par le VALIDATEUR.
        $gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $type = TypeBoutique::create(['code' => 'KIOS', 'libelle' => 'Kiosque', 'statut' => 'VALIDE', 'actif' => true, 'attribuable' => true, 'par_defaut' => false, 'ordre' => 50]);
        $this->en($gestionnaire)->put(route('admin.sites.types.update', $type), ['libelle' => 'Kiosque', 'ordre' => 50, 'description_citoyen' => 'Petit local ouvert sur la rue.'])
            ->assertSessionHas('succes');
        $type->refresh();
        $this->assertNull($type->description_citoyen);
        $this->assertSame(['description_citoyen' => 'Petit local ouvert sur la rue.'], $type->modification_demandee);
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', ['rubrique' => 'referentiels', 'detail' => 'type:'.$type->id]))->assertOk()
            ->assertSee('Petit local ouvert sur la rue.');
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['type:'.$type->id], 'decision' => 'confirmer', 'mode' => 'ligne'])->assertSessionHas('succes');
        $this->assertSame('Petit local ouvert sur la rue.', $type->fresh()->description_citoyen);

        // Libellé seul (non « par défaut ») : toujours appliqué tout de suite.
        $this->en($gestionnaire)->put(route('admin.sites.types.update', $type), ['libelle' => 'Kiosque de rue', 'ordre' => 50, 'description_citoyen' => 'Petit local ouvert sur la rue.'])
            ->assertSessionHas('succes');
        $this->assertSame(['Kiosque de rue', null], [$type->fresh()->libelle, $type->fresh()->modification_demandee]);
    }

    public function test_campagnes_existantes_converties(): void
    {
        $migration = require database_path('migrations/2026_11_14_000000_types_par_campagne.php');
        $migration->down();
        $migration->up();

        // Tous les types attribuables des sites de la campagne, jamais la chambre froide.
        $types = json_decode((string) DB::table('campagnes_boutiques')->where('id', $this->campagne->id)->value('types'), true);
        sort($types);
        $attendus = [$this->types['BOUT']->id, $this->types['MAG']->id, $this->types['PP']->id];
        sort($attendus);
        $this->assertSame($attendus, $types);
    }
}
