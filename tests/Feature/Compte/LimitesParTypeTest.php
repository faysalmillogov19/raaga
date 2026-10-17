<?php

namespace Tests\Feature\Compte;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutKyc;
use App\Enums\StatutReservationBoutique;
use App\Enums\StatutSite;
use App\Models\AuditLog;
use App\Models\Boutique;
use App\Models\CampagneBoutiques;
use App\Models\ReservationBoutique;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use Database\Seeders\ReferentielsSitesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * Limite de réservation par type d'emplacement : comptée sur les réservations actives de la personne (compte et numéro
 * de pièce) pour ce type dans la campagne, dans la transaction de réservation ; une réservation annulée ou expirée ne
 * compte plus ; le citoyen voit ce qui lui reste et « Limite atteinte pour ce type ».
 */
class LimitesParTypeTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private CampagneBoutiques $campagne;

    /** @var array<string, TypeBoutique> */
    private array $types;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
        $this->seed(ReferentielsSitesSeeder::class);
        $this->types = TypeBoutique::query()->whereIn('code', ['PV', 'PP', 'PF', 'BOUT'])->get()->keyBy('code')->all();
        $site = Site::create(['statut' => StatutSite::Confirme, 'code' => 'RAA', 'nom' => 'Marché Raaga', 'region' => 'Centre', 'commune' => 'Ouagadougou',
            'adresse' => 'Avenue', 'version' => 1, 'cree_par' => $this->regulateur->id]);
        foreach ([[1, 'PV'], [2, 'PV'], [3, 'PV'], [4, 'PP'], [5, 'PF']] as [$n, $type]) {
            $site->boutiques()->create(['niveau' => 'RC', 'bloc' => 'BP', 'numero' => $n, 'surface' => 10, 'type_boutique_id' => $this->types[$type]->id, 'statut' => StatutBoutique::Disponible]);
        }
        Boutiques::grilleConfirmee($site, array_map(fn ($t) => $t->id, $this->types), $this->regulateur);
        $limites = collect(['PV', 'PP', 'PF'])->mapWithKeys(fn ($c) => [$this->types[$c]->id => 1])->all();
        $this->campagne = CampagneBoutiques::create([
            'statut' => StatutCampagneBoutiques::Ouverte, 'nom' => 'Pôle boucherie 2027', 'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addMonth(),
            'canaux_actifs' => ['ORANGE_MONEY'], 'sites' => [$site->id], 'types' => array_keys($limites), 'limites' => $limites, 'version' => 2, 'cree_par' => $this->regulateur->id,
        ]);
    }

    private function citoyen(string $telephone = '+22670123456'): User
    {
        return User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => $telephone]);
    }

    private function boutique(int $numero): Boutique
    {
        return Boutique::query()->where('code', 'RAA-RC-BP-'.sprintf('%03d', $numero))->sole();
    }

    private function plan(User $citoyen)
    {
        return $this->actingAs($citoyen)->get(route('boutiques', ['niveau' => 'RC', 'bloc' => 'BP']))->assertOk();
    }

    /** Limites en clair : nom du type et nombre (« Boutique (1), Magasin (1) »). */
    private function enClair(array $codes, int $n = 1): string
    {
        return collect($codes)->map(fn ($c) => $this->types[$c]->libelle.' ('.$n.')')->implode(', ');
    }

    public function test_un_de_chaque_type_accepte_et_deuxieme_du_meme_type_refuse(): void
    {
        $citoyen = $this->citoyen();
        $this->plan($citoyen)->assertSee('Vous pouvez encore réserver : '.$this->enClair(['PV', 'PP', 'PF']).'.');

        Boutiques::reserver($this, $citoyen, $this->boutique(1))->assertSessionHasNoErrors();
        $plan = $this->plan($citoyen)->assertSee('Vous pouvez encore réserver : '.$this->enClair(['PP', 'PF']).'.');
        // PV : visibles, plus réservables pour lui ; PP et PF : toujours liés au récapitulatif.
        $html = $plan->getContent();
        $this->assertStringContainsString('data-limite-atteinte', $html);
        $this->assertStringNotContainsString('href="'.route('boutiques.boutique', 'RAA-RC-BP-002').'"', $html);
        $this->assertStringContainsString('href="'.route('boutiques.boutique', 'RAA-RC-BP-004').'"', $html);
        $this->actingAs($citoyen)->get(route('boutiques.boutique', 'RAA-RC-BP-002'))->assertOk()
            ->assertSee('Limite atteinte pour ce type')->assertDontSee('data-formulaire-reservation', false);

        Boutiques::reserver($this, $citoyen, $this->boutique(2))
            ->assertSessionHasErrors(['boutique' => __('public.boutiques.erreurs.limite_type', ['n' => 1, 'type' => 'PV'])]);
        Boutiques::reserver($this, $citoyen, $this->boutique(4))->assertSessionHasNoErrors();
        Boutiques::reserver($this, $citoyen, $this->boutique(5))->assertSessionHasNoErrors();
        $this->assertSame(3, ReservationBoutique::query()->where('user_id', $citoyen->id)->count());
        $this->plan($citoyen)->assertSee('Vous avez atteint vos limites de réservation dans cette campagne.');

        // Visiteur : les limites seules.
        $this->app['auth']->forgetGuards();
        $this->get(route('boutiques'))->assertOk()->assertSee('Limite par personne : '.$this->enClair(['PV', 'PP', 'PF']).'.');
    }

    public function test_limite_controlee_sur_le_numero_de_piece_meme_depuis_un_autre_compte(): void
    {
        Boutiques::reserver($this, $this->citoyen(), $this->boutique(1), 'B1234567')->assertSessionHasNoErrors();

        // Autre compte, même pièce (casse et séparateurs ignorés) : refusé pour le même type, accepté pour un autre type.
        $autre = $this->citoyen('+22670999999');
        Boutiques::reserver($this, $autre, $this->boutique(2), 'b 123-4567')->assertSessionHasErrors('boutique');
        Boutiques::reserver($this, $autre, $this->boutique(4), 'b 123-4567')->assertSessionHasNoErrors();
        // Autre pièce : accepté.
        Boutiques::reserver($this, $this->citoyen('+22670888888'), $this->boutique(2), 'C7654321')->assertSessionHasNoErrors();
    }

    public function test_deux_demandes_simultanees_du_meme_type_une_seule_acceptee(): void
    {
        // Deux comptes, une même pièce, deux PV différents : la campagne est verrouillée pendant chaque réservation, la
        // seconde demande compte la première et est refusée.
        $premier = $this->citoyen();
        $second = $this->citoyen('+22670999999');
        Boutiques::reserver($this, $premier, $this->boutique(1), 'B1234567')->assertSessionHasNoErrors();
        $requetes = [];
        DB::listen(function ($q) use (&$requetes) {
            $requetes[] = strtolower($q->sql);
        });
        Boutiques::reserver($this, $second, $this->boutique(2), 'B1234567')->assertSessionHasErrors('boutique');

        // Le comptage du type a lieu après le verrou de la campagne : une demande concurrente attend la fin de l'autre.
        $verrou = collect($requetes)->search(fn ($s) => str_contains($s, 'from "campagnes_boutiques"') && str_contains($s, 'for update'));
        $comptage = collect($requetes)->search(fn ($s) => str_contains($s, 'count(*)') && str_contains($s, 'reservations_boutiques') && str_contains($s, 'type_boutique_id'));
        $this->assertNotFalse($verrou);
        $this->assertNotFalse($comptage);
        $this->assertLessThan($comptage, $verrou);

        $this->assertSame(1, ReservationBoutique::query()->where('numero_piece', 'B1234567')->count());
        $this->assertSame(StatutBoutique::Disponible, $this->boutique(2)->statut);
    }

    public function test_place_liberee_apres_expiration_ou_annulation(): void
    {
        $citoyen = $this->citoyen();
        Boutiques::reserver($this, $citoyen, $this->boutique(1))->assertSessionHasNoErrors();
        Boutiques::reserver($this, $citoyen, $this->boutique(2))->assertSessionHasErrors('boutique');

        // Expiration (caution non payée sous 72 heures) : la place est libérée.
        $this->travel(73)->hours();
        $this->artisan('raaga:expirer-reservations')->assertSuccessful();
        $this->assertSame(StatutReservationBoutique::Expiree, ReservationBoutique::query()->where('boutique_id', $this->boutique(1)->id)->sole()->statut);
        $this->plan($citoyen)->assertSee('Vous pouvez encore réserver : '.$this->enClair(['PV', 'PP', 'PF']).'.');
        Boutiques::reserver($this, $citoyen, $this->boutique(2))->assertSessionHasNoErrors();

        // Annulation : la place est libérée aussi.
        ReservationBoutique::query()->where('boutique_id', $this->boutique(2)->id)->sole()->forceFill(['statut' => StatutReservationBoutique::Annulee])->save();
        $this->boutique(2)->forceFill(['statut' => StatutBoutique::Disponible])->save();
        Boutiques::reserver($this, $citoyen, $this->boutique(3))->assertSessionHasNoErrors();
    }

    public function test_limite_d_au_moins_1_obligatoire_et_visible_au_validateur(): void
    {
        $pv = $this->types['PV']->id;
        $pp = $this->types['PP']->id;
        $formulaire = ['nom' => 'Pôle 2028', 'date_ouverture' => now()->addDay()->format('Y-m-d\TH:i'), 'date_cloture' => now()->addMonth()->format('Y-m-d\TH:i'),
            'canaux_actifs' => ['ORANGE_MONEY'], 'sites' => [Site::query()->value('id')], 'types' => [$pv, $pp]];
        $creation = $this->en($this->regulateur)->get(route('admin.boutiques.create'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="limites\['.$pv.'\]"[^>]*value="1"/', $creation);

        $this->en($this->regulateur)->post(route('admin.boutiques.store'), [...$formulaire, 'limites' => [$pv => 0, $pp => 1]])->assertSessionHasErrors('limites.'.$pv);
        $this->en($this->regulateur)->post(route('admin.boutiques.store'), [...$formulaire, 'limites' => [$pv => 2]])->assertSessionHasErrors('limites.'.$pp);
        $this->en($this->regulateur)->post(route('admin.boutiques.store'), [...$formulaire, 'limites' => [$pv => 2, $pp => 1]])->assertSessionHasNoErrors();

        $c = CampagneBoutiques::query()->where('nom', 'Pôle 2028')->sole();
        $this->assertSame([2, 1], [$c->limitePour($pv), $c->limitePour($pp)]);
        $this->en($this->regulateur)->post(route('admin.boutiques.soumettre', $c))->assertSessionHas('succes');
        $this->assertSame('PV : 2, PP : 1', $c->versions()->latest('numero')->value('parametres')['limites']);
        $this->en($this->validateur)->get(route('admin.boutiques.show', $c))->assertOk()->assertSee('PV : 2, PP : 1');
    }

    public function test_conversion_des_campagnes_existantes(): void
    {
        $migration = require database_path('migrations/2026_11_15_000000_limites_par_type.php');
        $migration->down();
        DB::table('campagnes_boutiques')->where('id', $this->campagne->id)->update(['max_boutiques_par_personne' => 2]);
        $migration->up();

        $limites = json_decode((string) DB::table('campagnes_boutiques')->where('id', $this->campagne->id)->value('limites'), true);
        $this->assertSame(collect(['PV', 'PP', 'PF'])->mapWithKeys(fn ($c) => [(string) $this->types[$c]->id => 2])->sortKeys()->all(), collect($limites)->sortKeys()->all());
        $this->assertTrue(AuditLog::query()->where('action', 'campagne_boutiques.limites_converties')->where('entite_id', (string) $this->campagne->id)->exists());
    }
}
