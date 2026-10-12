<?php

namespace Tests\Feature\Admin;

use App\Contracts\SmsProvider;
use App\Enums\Role;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutCandidatureBoutique;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
        $this->sms = new FakeSmsProvider;
        $this->app->instance(SmsProvider::class, $this->sms);

        // « Petite boutique » : 2 boutiques ; « Grande boutique » : 1 boutique.
        $this->campagne = CandidatureBoutiqueTest::campagneOuverte(['statut' => StatutCampagneBoutiques::Cloturee]);
    }

    private function candidature(string $categorie, StatutCandidatureBoutique $statut, array $attributs = []): CandidatureBoutique
    {
        static $n = 0;
        $n++;

        return CandidatureBoutique::create([
            'reference' => 'BTQ-TEST'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'user_id' => User::factory()->role(Role::Citoyen)->create(['telephone' => '+2267000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT)])->id,
            'campagne_boutiques_id' => $this->campagne->id,
            'categorie_boutique_id' => $this->campagne->categories()->where('libelle', $categorie)->value('id'),
            'caution' => 150_000, 'statut' => $statut, 'expire_le' => now()->subHour(), ...$attributs,
        ]);
    }

    public function test_le_tirage_retient_les_premiers_tires_dans_la_limite_des_boutiques_et_se_verifie(): void
    {
        $payees = collect(range(1, 5))->map(fn () => $this->candidature('Petite boutique', StatutCandidatureBoutique::Payee));
        $expiree = $this->candidature('Petite boutique', StatutCandidatureBoutique::Expiree);

        app(TirageAuSort::class)->tirer($this->campagne, $this->validateur);

        $this->assertSame(StatutCampagneBoutiques::Attribuee, $this->campagne->fresh()->statut);
        $this->assertSame(StatutCandidatureBoutique::Expiree, $expiree->fresh()->statut);

        $tirage = TirageBoutiques::query()->whereHas('categorie', fn ($q) => $q->where('libelle', 'Petite boutique'))->sole();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $tirage->graine);
        $this->assertSame(5, $tirage->nombre_participants);

        // Vérifiable : la graine publiée et les références redonnent exactement le même ordre.
        $references = $payees->pluck('reference')->all();
        $this->assertSame(TirageAuSort::ordonner($tirage->graine, $references), $tirage->ordre);
        $this->assertSame(TirageAuSort::empreinte($references), $tirage->empreinte_participants);

        foreach ($tirage->ordre as $i => $reference) {
            $c = CandidatureBoutique::query()->where('reference', $reference)->sole();
            $this->assertSame($i + 1, $c->rang_tirage);
            $this->assertSame($i < 2 ? StatutCandidatureBoutique::Retenue : StatutCandidatureBoutique::NonRetenue, $c->statut);
        }

        // Catégorie sans participant : tirage enregistré, vide.
        $this->assertSame(0, TirageBoutiques::query()->whereHas('categorie', fn ($q) => $q->where('libelle', 'Grande boutique'))->sole()->nombre_participants);

        // Chaque participant est prévenu par SMS de son résultat.
        $this->assertCount(5, $this->sms->envoyes);
        $this->assertSame(2, collect($this->sms->envoyes)->filter(fn ($e) => str_contains($e['message'], 'est retenue'))->count());
        $this->assertSame(3, collect($this->sms->envoyes)->filter(fn ($e) => str_contains($e['message'], 'n’a pas été retenue'))->count());

        // Définitif : un second tirage est refusé.
        $this->expectException(RegleGestionException::class);
        app(TirageAuSort::class)->tirer($this->campagne->fresh(), $this->validateur);
    }

    public function test_le_tirage_attend_la_cloture_et_la_fin_des_delais_de_caution(): void
    {
        $this->candidature('Petite boutique', StatutCandidatureBoutique::Reservee, ['expire_le' => now()->addDay()]);

        try {
            app(TirageAuSort::class)->tirer($this->campagne, $this->validateur);
            $this->fail('Le tirage aurait dû attendre la fin du délai de paiement.');
        } catch (RegleGestionException $e) {
            $this->assertStringContainsString('délai de paiement', $e->getMessage());
        }

        $this->campagne->forceFill(['statut' => StatutCampagneBoutiques::Ouverte])->save();
        $this->expectException(RegleGestionException::class);
        app(TirageAuSort::class)->tirer($this->campagne->fresh(), $this->validateur);
    }

    public function test_seul_le_validateur_lance_le_tirage_et_tout_le_monde_voit_le_resultat_publie(): void
    {
        $this->candidature('Grande boutique', StatutCandidatureBoutique::Payee);

        $this->en($this->regulateur)->post(route('admin.boutiques.tirer', $this->campagne), ['confirmation' => 'CONFIRMER'])->assertForbidden();
        $this->en(User::factory()->role(Role::SuperAdmin)->create())->post(route('admin.boutiques.tirer', $this->campagne), ['confirmation' => 'CONFIRMER'])->assertForbidden();
        $this->assertSame(0, TirageBoutiques::count());

        $this->en($this->validateur)->get(route('admin.boutiques.show', $this->campagne))->assertOk()->assertSee('Lancer le tirage au sort');

        app(TirageAuSort::class)->tirer($this->campagne, $this->validateur);
        $tirage = TirageBoutiques::query()->whereHas('categorie', fn ($q) => $q->where('libelle', 'Grande boutique'))->sole();
        $this->en($this->regulateur)->get(route('admin.boutiques.show', $this->campagne))->assertOk()
            ->assertSee($tirage->graine)->assertSee('BTQ-TEST')->assertSee('Retenues');
    }

    public function test_le_resultat_apparait_dans_mon_espace(): void
    {
        $retenue = $this->candidature('Grande boutique', StatutCandidatureBoutique::Payee);
        app(TirageAuSort::class)->tirer($this->campagne, $this->validateur);

        $this->actingAs($retenue->user)->get('/espace?onglet=boutiques')->assertOk()
            ->assertSee('Mes boutiques')->assertSee($retenue->reference)->assertSee('Retenue : boutique attribuée');
        $this->actingAs($retenue->user)->get(route('boutiques.candidature', $retenue->reference))->assertOk()
            ->assertSee('une boutique de cette catégorie vous est attribuée');
        $this->actingAs(User::factory()->role(Role::Citoyen)->create())->get('/espace?onglet=boutiques')->assertOk()
            ->assertSee('Aucune candidature à une boutique')->assertDontSee($retenue->reference);
    }
}
