<?php

namespace Tests\Feature\Admin;

use App\Enums\DecisionParametrage;
use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Models\Duree;
use App\Models\Paiement;
use App\Models\ParametrageDecision;
use App\Models\ParametresPaiements;
use App\Models\ParametresPaiementsVersion;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Services\Paiement\ServicePaiement;
use Database\Seeders\DureesSeeder;
use Database\Seeders\ReferentielsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * Durées (SECONDE, MINUTE, HEURE, JOUR) et délais de paiement en valeur + durée : caution (72 heures par défaut),
 * complément (48 heures), avance (30 jours) ; date limite de l'avance affichée au citoyen.
 */
class DelaisPaiementTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
    }

    /** Saisie des quatre délais. */
    private function saisie(array $surcharges = []): array
    {
        return [
            'delai_caution_valeur' => 72, 'delai_caution_duree' => 'HEURE',
            'delai_complement_valeur' => 48, 'delai_complement_duree' => 'HEURE',
            'delai_avance_valeur' => 30, 'delai_avance_duree' => 'JOUR',
            'delai_kyc_valeur' => 48, 'delai_kyc_duree' => 'HEURE',
            ...$surcharges,
        ];
    }

    /** Saisie par le RÉGULATEUR, soumission, confirmation par le VALIDATEUR dans « À valider ». */
    private function confirmer(array $saisie): void
    {
        $this->en($this->regulateur)->put(route('admin.parametres-paiements.update'), $saisie)->assertSessionHasNoErrors()->assertSessionHas('succes');
        $this->en($this->regulateur)->post(route('admin.parametres-paiements.soumettre'))->assertSessionHas('succes');
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['delais:'.ParametresPaiements::fiche()->id], 'decision' => 'confirmer', 'mode' => 'ligne'])
            ->assertSessionHas('succes');
    }

    public function test_seeder_quatre_durees_idempotent(): void
    {
        $attendu = [['SECONDE', 1], ['MINUTE', 60], ['HEURE', 3_600], ['JOUR', 86_400]];
        $lire = fn () => Duree::query()->orderBy('ordre')->get()->map(fn (Duree $d) => [$d->code, $d->secondes])->all();

        // La migration crée les durées. Base vide : le seeder crée exactement les quatre durées.
        $this->assertSame($attendu, $lire());
        Duree::query()->delete();
        $this->seed(DureesSeeder::class);
        $this->assertSame($attendu, $lire());
        $this->assertSame(4, Duree::count());

        // Le seeder (dans ReferentielsSeeder) ne crée ni ne modifie rien de plus.
        Duree::query()->where('code', 'JOUR')->update(['libelle' => 'jour ouvré']);
        $this->seed(ReferentielsSeeder::class);
        $this->seed(DureesSeeder::class);
        $this->assertSame($attendu, $lire());
        $this->assertSame('jour ouvré', Duree::query()->where('code', 'JOUR')->value('libelle'));

        // Une seule route (25/10/2026) : libellés modifiés directement par le SUPER_ADMIN ; ni création, ni suppression,
        // ni changement de valeur, ni journal de validation.
        $routes = collect(app('router')->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'durees'));
        $this->assertSame(['admin.parametres-paiements.duree'], $routes->map->getName()->values()->all());
        $this->assertSame(['PUT'], $routes->first()->methods());
        // (Seules les valeurs initiales des délais de paiement sont confirmées d'office par ReferentielsSeeder.)
        $this->assertSame(0, ParametrageDecision::query()->where('objet_type', '!=', 'delais')->count());
    }

    public function test_valeurs_par_defaut(): void
    {
        $this->assertSame([72 * 3_600, 48 * 3_600, 30 * 86_400],
            [ParametresPaiements::delaiSecondes('caution'), ParametresPaiements::delaiSecondes('complement'), ParametresPaiements::delaiSecondes('avance')]);

        $page = $this->en($this->regulateur)->get(route('admin.parametres-paiements'))->assertOk();
        $page->assertSeeInOrder(['Délai de paiement de la caution', '72 heures', 'Délai de complément d’un paiement incomplet', '48 heures',
            'Délai pour payer l’avance', '30 jours'], false);
        $html = $page->getContent();
        $this->assertMatchesRegularExpression('/name="delai_avance_valeur"[^>]*value="30"/', $html);
        $this->assertMatchesRegularExpression('/<select[^>]*name="delai_avance_duree"[^>]*>.*?<option value="JOUR" selected/s', $html);
    }

    public function test_conversion_en_secondes_calculee_et_stockee(): void
    {
        $this->confirmer($this->saisie(['delai_caution_valeur' => 2, 'delai_caution_duree' => 'JOUR', 'delai_complement_valeur' => 90, 'delai_complement_duree' => 'MINUTE',
            'delai_avance_valeur' => 45, 'delai_avance_duree' => 'SECONDE']));

        $fiche = ParametresPaiements::fiche();
        $this->assertSame([2, Duree::parCode('JOUR')->id, 172_800], [$fiche->delai_caution_valeur, $fiche->delai_caution_duree_id, $fiche->delai_caution_secondes]);
        $this->assertSame([90, 5_400], [$fiche->delai_complement_valeur, $fiche->delai_complement_secondes]);
        $this->assertSame(45, $fiche->delai_avance_secondes);
        // Version figée : valeur, code de la durée et secondes.
        $this->assertSame(['valeur' => 2, 'duree' => 'JOUR', 'secondes' => 172_800],
            collect($fiche->versions()->latest('numero')->value('parametres'))->only(['delai_caution_valeur', 'delai_caution_duree', 'delai_caution_secondes'])
                ->mapWithKeys(fn ($v, $k) => [str_replace('delai_caution_', '', $k) => $v])->all());

        // En vigueur après confirmation.
        $this->assertSame([172_800, 5_400, 45], [ParametresPaiements::delaiSecondes('caution'), ParametresPaiements::delaiSecondes('complement'), ParametresPaiements::delaiSecondes('avance')]);
        $this->assertSame(['2 jours', '90 minutes', '45 secondes'], array_map(fn ($d) => ParametresPaiements::libelleEnVigueur($d), ['caution', 'complement', 'avance']));

        // Le délai de caution s'applique aux nouvelles réservations.
        Boutiques::campagneOuverte();
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+22670123456']);
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A'))->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(172_800, now()->diffInSeconds(ReservationBoutique::sole()->expire_le), 5);
    }

    public function test_valeur_et_duree_obligatoires(): void
    {
        $route = route('admin.parametres-paiements.update');
        $this->en($this->regulateur)->put($route, $this->saisie(['delai_avance_duree' => '']))->assertSessionHasErrors('delai_avance_duree');
        $this->en($this->regulateur)->put($route, collect($this->saisie())->except('delai_caution_duree')->all())->assertSessionHasErrors('delai_caution_duree');
        $this->en($this->regulateur)->put($route, $this->saisie(['delai_complement_duree' => 'SEMAINE']))->assertSessionHasErrors('delai_complement_duree');
        $this->en($this->regulateur)->put($route, $this->saisie(['delai_caution_valeur' => '']))->assertSessionHasErrors('delai_caution_valeur');
        $this->en($this->regulateur)->put($route, $this->saisie(['delai_caution_valeur' => 0]))->assertSessionHasErrors('delai_caution_valeur');
        // Plus d'un an : refusé.
        $this->en($this->regulateur)->put($route, $this->saisie(['delai_avance_valeur' => 400, 'delai_avance_duree' => 'JOUR']))
            ->assertSessionHasErrors(['delai_avance_valeur' => 'Un délai ne peut pas dépasser un an.']);
        $this->assertFalse(ParametresPaiements::query()->exists());
    }

    public function test_ancien_format_lu_et_delai_propre_d_une_campagne_garde(): void
    {
        // Version confirmée de l'ancien format (délai de complément en heures) : toujours lue.
        $fiche = ParametresPaiements::create(['statut' => 'CONFIRME', 'version' => 1]);
        ParametresPaiementsVersion::create(['parametres_paiements_id' => $fiche->id, 'numero' => 1, 'parametres' => ['delai_complement_heures' => 36],
            'auteur_id' => $this->regulateur->id, 'created_at' => now()]);
        ParametrageDecision::create(['objet_type' => 'delais', 'objet_id' => $fiche->id, 'version' => 1, 'decision' => DecisionParametrage::Confirme,
            'decideur_id' => $this->validateur->id, 'created_at' => now()]);
        $this->assertSame([36 * 3_600, 72 * 3_600], [ParametresPaiements::delaiSecondes('complement'), ParametresPaiements::delaiSecondes('caution')]);

        // Campagne qui avait son propre délai (converti en valeur + durée) : elle le garde.
        $campagne = Boutiques::campagneOuverte();
        $campagne->forceFill(['delai_paiement_valeur' => 24, 'delai_paiement_duree_id' => Duree::parCode('HEURE')->id, 'delai_paiement_secondes' => 86_400])->save();
        $this->assertSame([86_400, '24 heures'], [$campagne->delaiPaiementSecondes(), $campagne->libelleDelaiPaiement()]);
        $this->en($this->regulateur)->get(route('admin.boutiques.show', $campagne))->assertOk()->assertSee('24 heures')->assertSee('propre à la campagne');
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+22670123456']);
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A'))->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(86_400, now()->diffInSeconds(ReservationBoutique::sole()->expire_le), 5);
    }

    public function test_date_limite_de_l_avance_affichee_au_citoyen(): void
    {
        Boutiques::campagneOuverte();
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+22670123456']);
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A'))->assertSessionHasNoErrors();
        $r = ReservationBoutique::sole();
        $this->assertNull($r->avance_due_le);

        // Caution payée : boutique réservée, date limite de l'avance = passage en « réservée » + 30 jours.
        $this->actingAs($citoyen)->post(route('boutiques.reservation.payer', $r->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56']);
        app(ServicePaiement::class)->confirmer(Paiement::sole()->reference, true);
        $r->refresh();
        $this->assertTrue($r->avance_due_le->equalTo($r->reservee_le->copy()->addDays(30)));

        $this->actingAs($citoyen)->get(route('boutiques.suivi', $r->reference))->assertOk()
            ->assertSee('data-avance-due', false)
            ->assertSee('Avance à payer au plus tard le '.$r->avance_due_le->translatedFormat('j F Y à H\hi').', avant la signature du contrat.', false);

        // Délai dépassé sans avance : la réservation est annulée automatiquement, le citoyen le voit dans son dossier.
        $this->travel(31)->days();
        $this->actingAs($citoyen)->get(route('boutiques.suivi', $r->reference))->assertOk()->assertSee('Annulée : avance non payée dans le délai');
    }
}
