<?php

namespace Tests\Feature\Admin;

use App\Contracts\SmsProvider;
use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutCandidatureBoutique;
use App\Models\AffectationBoutiques;
use App\Models\Boutique;
use App\Models\CampagneBoutiques;
use App\Models\CandidatureBoutique;
use App\Models\TirageBoutiques;
use App\Models\User;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Boutiques\TirageAuSort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Compte\CandidatureBoutiqueTest;
use Tests\Support\ActeursParametrage;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

class TirageBoutiquesTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private FakeSmsProvider $sms;

    private CampagneBoutiques $campagne;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
        $this->sms = new FakeSmsProvider;
        $this->app->instance(SmsProvider::class, $this->sms);

        // Lots : « Petite boutique » (P-1, P-2) et « Grande boutique » (G-1) au marché central.
        $this->campagne = CandidatureBoutiqueTest::campagneOuverte(['statut' => StatutCampagneBoutiques::Cloturee]);
    }

    private function candidature(string $lot, StatutCandidatureBoutique $statut, ?User $user = null, array $attributs = []): CandidatureBoutique
    {
        $this->n++;

        return CandidatureBoutique::create([
            'reference' => 'BTQ-TEST'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT),
            'user_id' => ($user ?? User::factory()->role(Role::Citoyen)->create(['telephone' => '+2267000'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT)]))->id,
            'campagne_boutiques_id' => $this->campagne->id,
            'categorie_boutique_id' => $this->campagne->categories()->where('libelle', $lot)->value('id'),
            'caution' => 150_000, 'statut' => $statut, 'expire_le' => now()->subHour(), ...$attributs,
        ]);
    }

    private function tirage(string $lot): TirageBoutiques
    {
        return TirageBoutiques::query()->whereHas('categorie', fn ($q) => $q->where('libelle', $lot))->sole();
    }

    public function test_premier_tirage_puis_second_tirage_des_boutiques_tous_deux_verifiables(): void
    {
        $payees = collect(range(1, 5))->map(fn () => $this->candidature('Petite boutique', StatutCandidatureBoutique::Payee));
        $expiree = $this->candidature('Petite boutique', StatutCandidatureBoutique::Expiree);
        $service = app(TirageAuSort::class);

        // 1. Candidats.
        $service->tirer($this->campagne, $this->validateur);
        $this->assertSame(StatutCampagneBoutiques::Tiree, $this->campagne->fresh()->statut);
        $this->assertSame(StatutCandidatureBoutique::Expiree, $expiree->fresh()->statut);

        $tirage = $this->tirage('Petite boutique');
        $references = $payees->pluck('reference')->all();
        $this->assertSame(TirageAuSort::ordonner($tirage->graine, $references), $tirage->ordre);
        $this->assertSame(TirageAuSort::empreinte($references), $tirage->empreinte_participants);
        $this->assertSame(array_slice($tirage->ordre, 0, 2), $tirage->retenus);
        foreach ($tirage->ordre as $i => $reference) {
            $c = CandidatureBoutique::query()->where('reference', $reference)->sole();
            $this->assertSame($i + 1, $c->rang_tirage);
            $this->assertSame($i < 2 ? StatutCandidatureBoutique::Retenue : StatutCandidatureBoutique::NonRetenue, $c->statut);
        }
        // Les non-retenus sont prévenus tout de suite.
        $this->assertCount(3, $this->sms->envoyes);
        $this->assertTrue(collect($this->sms->envoyes)->every(fn ($e) => str_contains($e['message'], 'n’a pas été retenue')));

        // 2. Boutiques : le 1er retenu reçoit la 1re boutique de l'ordre du second tirage, etc.
        $service->affecter($this->campagne->fresh(), $this->validateur);
        $this->assertSame(StatutCampagneBoutiques::Attribuee, $this->campagne->fresh()->statut);

        $affectation = AffectationBoutiques::query()->where('tirage_boutiques_id', $tirage->id)->sole();
        $this->assertSame(TirageAuSort::ordonner($affectation->graine, ['P-1', 'P-2']), $affectation->ordre_boutiques);
        foreach ($tirage->retenus as $i => $reference) {
            $c = CandidatureBoutique::query()->where('reference', $reference)->with('boutique')->sole();
            $this->assertSame($affectation->ordre_boutiques[$i], $c->boutique->numero);
            $this->assertSame(StatutBoutique::Attribuee, $c->boutique->statut);
            $this->assertSame($c->boutique->numero, $affectation->affectations[$reference]);
        }

        // Les retenus reçoivent leur numéro de boutique par SMS.
        $this->assertCount(5, $this->sms->envoyes);
        $retenus = collect($this->sms->envoyes)->filter(fn ($e) => str_contains($e['message'], 'Boutique attribuée'));
        $this->assertCount(2, $retenus);
        $this->assertTrue($retenus->every(fn ($e) => preg_match('/Boutique attribuée : P-[12] /', $e['message']) === 1));

        // Définitif : pas de second passage.
        $this->expectException(RegleGestionException::class);
        $service->affecter($this->campagne->fresh(), $this->validateur);
    }

    public function test_le_plafond_par_personne_s_applique_et_la_place_revient_au_suivant(): void
    {
        $this->campagne->forceFill(['max_boutiques_par_personne' => 1])->save();
        $awa = User::factory()->role(Role::Citoyen)->create(['telephone' => '+22670111111']);
        // Awa candidate trois fois ; d'autres candidats complètent.
        $this->candidature('Petite boutique', StatutCandidatureBoutique::Payee, $awa);
        $this->candidature('Petite boutique', StatutCandidatureBoutique::Payee, $awa);
        $this->candidature('Grande boutique', StatutCandidatureBoutique::Payee, $awa);
        $this->candidature('Petite boutique', StatutCandidatureBoutique::Payee);
        $this->candidature('Petite boutique', StatutCandidatureBoutique::Payee);
        $this->candidature('Grande boutique', StatutCandidatureBoutique::Payee);

        app(TirageAuSort::class)->tirer($this->campagne, $this->validateur);

        $this->assertLessThanOrEqual(1, CandidatureBoutique::query()->where('user_id', $awa->id)->where('statut', StatutCandidatureBoutique::Retenue)->count());
        // Toutes les places sont pourvues malgré les candidatures écartées.
        $this->assertCount(2, $this->tirage('Petite boutique')->retenus);
        $this->assertCount(1, $this->tirage('Grande boutique')->retenus);
        $ecartees = collect(TirageBoutiques::all())->flatMap(fn ($t) => (array) $t->ecartes);
        $this->assertTrue($ecartees->every(fn ($ref) => CandidatureBoutique::query()->where('reference', $ref)->value('user_id') === $awa->id));
    }

    public function test_les_boutiques_non_attribuees_redeviennent_disponibles(): void
    {
        $seule = $this->candidature('Petite boutique', StatutCandidatureBoutique::Payee);
        $service = app(TirageAuSort::class);
        $service->tirer($this->campagne, $this->validateur);
        $service->affecter($this->campagne->fresh(), $this->validateur);

        $this->assertSame(StatutBoutique::Attribuee, $seule->fresh()->boutique->statut);
        $this->assertSame(1, Boutique::query()->where('numero', 'like', 'P-%')->where('statut', StatutBoutique::Disponible)->count());
        $this->assertSame(StatutBoutique::Disponible, Boutique::query()->where('numero', 'G-1')->sole()->statut);
    }

    public function test_le_tirage_attend_la_cloture_et_la_fin_des_delais_de_caution(): void
    {
        $this->candidature('Petite boutique', StatutCandidatureBoutique::Reservee, null, ['expire_le' => now()->addDay()]);

        try {
            app(TirageAuSort::class)->tirer($this->campagne, $this->validateur);
            $this->fail('Le tirage aurait dû attendre la fin du délai de paiement.');
        } catch (RegleGestionException $e) {
            $this->assertStringContainsString('délai de paiement', $e->getMessage());
        }

        $this->expectException(RegleGestionException::class);
        app(TirageAuSort::class)->affecter($this->campagne, $this->validateur);
    }

    public function test_seul_le_validateur_lance_les_tirages_et_le_resultat_est_publie(): void
    {
        $this->candidature('Grande boutique', StatutCandidatureBoutique::Payee);

        foreach (['tirer', 'affecter'] as $action) {
            $this->en($this->regulateur)->post(route('admin.boutiques.'.$action, $this->campagne), ['confirmation' => 'CONFIRMER'])->assertForbidden();
            $this->en(User::factory()->role(Role::SuperAdmin)->create())->post(route('admin.boutiques.'.$action, $this->campagne), ['confirmation' => 'CONFIRMER'])->assertForbidden();
        }
        $this->en($this->validateur)->get(route('admin.boutiques.show', $this->campagne))->assertOk()->assertSee('Lancer le tirage au sort');

        $service = app(TirageAuSort::class);
        $service->tirer($this->campagne, $this->validateur);
        $this->en($this->validateur)->get(route('admin.boutiques.show', $this->campagne))->assertOk()->assertSee('Lancer le second tirage (boutiques)');
        $service->affecter($this->campagne->fresh(), $this->validateur);

        $tirage = $this->tirage('Grande boutique');
        $this->en($this->regulateur)->get(route('admin.boutiques.show', $this->campagne))->assertOk()
            ->assertSee($tirage->graine)->assertSee($tirage->affectation->graine)->assertSee('G-1')->assertSee('BTQ-TEST');
    }

    public function test_le_resultat_et_la_boutique_apparaissent_dans_mon_espace(): void
    {
        $retenue = $this->candidature('Grande boutique', StatutCandidatureBoutique::Payee);
        $service = app(TirageAuSort::class);
        $service->tirer($this->campagne, $this->validateur);
        $service->affecter($this->campagne->fresh(), $this->validateur);

        $this->actingAs($retenue->user)->get('/espace?onglet=boutiques')->assertOk()
            ->assertSee($retenue->reference)->assertSee('Retenue au tirage au sort')->assertSee('G-1');
        $this->actingAs($retenue->user)->get(route('boutiques.candidature', $retenue->reference))->assertOk()
            ->assertSee('Boutique attribuée')->assertSee('G-1');
        $this->actingAs(User::factory()->role(Role::Citoyen)->create())->get('/espace?onglet=boutiques')->assertOk()
            ->assertSee('Aucune candidature à une boutique')->assertDontSee($retenue->reference);
    }
}
