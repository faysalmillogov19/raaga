<?php

namespace Tests\Feature\Boutiques;

use App\Enums\Role;
use App\Enums\StatutInscriptionBoutique;
use App\Models\Boutique;
use App\Models\InscriptionBoutique;
use App\Models\User;
use App\Services\Boutiques\ReservationsBoutiques;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * Recherche des boutiques (réunion du 05/11/2026) : coût à l'entrée (caution + avance) minimum et maximum par zone,
 * filtres budget (sur le total à l'entrée), zone, niveau, surface ; « Total à l'entrée » et « Loyer mensuel » côte à
 * côte, montants de la grille confirmée ; ligne « 1re tranche » absente tant que l'échéancier n'existe pas ; nombre
 * de personnes en liste d'attente sur chaque boutique, jamais de nom.
 */
class RechercheBoutiquesTest extends TestCase
{
    use RefreshDatabase;

    private int $totalPetite;

    private int $totalGrande;

    protected function setUp(): void
    {
        parent::setUp();
        Boutiques::campagneOuverte();
        $this->totalPetite = ReservationsBoutiques::totalEntree(ReservationsBoutiques::tarifer(Boutiques::boutique('A', 1)));
        $this->totalGrande = ReservationsBoutiques::totalEntree(ReservationsBoutiques::tarifer(Boutiques::boutique('B', 1)));
    }

    public function test_cout_a_l_entree_minimum_et_maximum_par_zone(): void
    {
        $couts = ReservationsBoutiques::coutsParZone(Boutiques::campagneOuverte(), Boutiques::boutique()->site_id);
        $this->assertSame(['A' => ['min' => $this->totalPetite, 'max' => $this->totalPetite, 'nombre' => 4], 'B' => ['min' => $this->totalGrande, 'max' => $this->totalGrande, 'nombre' => 1]],
            $couts->all());

        $this->get(route('boutiques'))->assertOk()->assertSee('data-couts-zones', false)->assertSee('Coût à l’entrée (caution + avance) par zone', false)
            ->assertSee(format_fcfa($this->totalPetite), false)->assertSee(format_fcfa($this->totalGrande), false);
    }

    public function test_filtres_budget_zone_niveau_surface(): void
    {
        $codes = fn (array $q) => collect($this->get(route('boutiques', $q))->assertOk()->viewData('resultats'))->pluck('code')->all();
        $petites = Boutique::query()->where('bloc', 'A')->orderBy('code')->pluck('code')->all();
        $grande = Boutiques::boutique('B', 1)->code;

        // Budget sur le total à l'entrée (espaces et points acceptés), de la moins chère à la plus chère.
        $this->assertSame([...$petites, $grande], $codes(['budget_min' => '0']));
        $this->assertSame($petites, $codes(['budget_max' => number_format($this->totalPetite, 0, ',', ' ')]));
        $this->assertSame([$grande], $codes(['budget_min' => $this->totalPetite + 1]));
        // Zone, niveau, surface.
        $this->assertSame([$grande], $codes(['zone' => 'B']));
        $this->assertSame([...$petites, $grande], $codes(['niveau_recherche' => 'RC']));
        $this->assertSame([], $codes(['niveau_recherche' => 'R1']));
        $this->assertSame([$grande], $codes(['surface_min' => '10']));
        $this->assertSame($petites, $codes(['surface_max' => '9,5']));
        // Sans filtre : pas de résultats (le plan seul) ; filtre invalide ignoré.
        $this->assertNull($this->get(route('boutiques'))->viewData('resultats'));
        $this->assertNull($this->get(route('boutiques', ['surface_min' => 'abc']))->viewData('resultats'));
    }

    public function test_deux_montants_cote_a_cote_attente_et_sans_premiere_tranche(): void
    {
        $grande = Boutiques::boutique('B', 1);
        foreach (range(1, 2) as $i) {
            InscriptionBoutique::create(['campagne_boutiques_id' => Boutiques::campagneOuverte()->id, 'boutique_id' => $grande->id,
                'user_id' => User::factory()->role(Role::Citoyen)->create()->id, 'statut' => StatutInscriptionBoutique::EnAttente, 'inscrite_le' => now()]);
        }
        $page = $this->get(route('boutiques', ['zone' => 'B']))->assertOk()
            ->assertSee('data-resultat="'.$grande->code.'"', false)->assertSee('1 boutique correspond à ces critères.', false)
            ->assertSeeInOrder(['Total à l’entrée (caution + avance)', format_fcfa($this->totalGrande), 'Loyer mensuel'], false)
            ->assertSee('2 personnes en liste d’attente.', false)
            ->assertDontSee('data-premiere-tranche', false);
        $this->assertStringNotContainsString('Montant de la 1re tranche</dt><dd>', $page->getContent());

        // Plan : nombre en attente sur la case de la boutique (bloc B).
        $this->get(route('boutiques', ['niveau' => 'RC', 'bloc' => 'B']))->assertOk()->assertSee('data-attente', false)->assertSee('2 en attente');
    }
}
