<?php

namespace Tests\Feature\Admin;

use App\Domain\Dividendes\Detention;
use App\Domain\Dividendes\MoteurDistribution;
use App\Enums\PayeurFrais;
use App\Enums\StatutAvenant;
use App\Enums\StatutDistribution;
use App\Models\Avenant;
use App\Models\DistributionDividende;
use App\Services\Dividendes\SimulationDistribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

class DividendesWorkflowTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->creerActeurs();
    }

    private function formulaire(array $surcharges = [], bool $avecPv = true): array
    {
        return [
            'classe_actions' => 'B',
            'exercice' => 2025,
            'ag_date' => '2026-06-30',
            'ag_pv_numero' => 'PV-AGO-2026-01',
            'resultat_distribuable' => 100_000_000,
            'mode_calcul' => 'MONTANT_GLOBAL',
            'montant_global' => 50_000_000,
            'date_arrete' => '2026-07-15',
            'date_mise_en_paiement' => '2026-08-01',
            'taux_irvm' => '12.5',
            'frais_supportes_par' => 'RAAGA',
            'seuil_minimal' => 500,
            'canaux_autorises' => ['ORANGE_MONEY', 'MOOV_MONEY', 'VIREMENT'],
            'canal_defaut' => 'ORANGE_MONEY',
            'tentatives' => 3,
            'delai_tentatives_heures' => 24,
            'conservation_non_reclames_mois' => 60,
            'traitement_non_reclames' => 'CONSIGNATION',
            ...($avecPv ? ['pv' => UploadedFile::fake()->create('pv.pdf', 100, 'application/pdf')] : []),
            ...$surcharges,
        ];
    }

    private function distributionSoumise(): DistributionDividende
    {
        $this->en($this->regulateur)->post(route('admin.dividendes.store'), $this->formulaire())->assertSessionHasNoErrors();
        $d = DistributionDividende::query()->firstOrFail();
        $this->en($this->regulateur)->post(route('admin.dividendes.soumettre', $d), ['montant_conforme_pv' => '1'])->assertSessionHas('succes');

        return $d->refresh();
    }

    private function distributionConfirmee(): DistributionDividende
    {
        $d = $this->distributionSoumise();
        $this->en($this->validateur)->post(route('admin.dividendes.confirmer', $d))->assertSessionHas('succes');

        return $d->refresh();
    }

    public function test_le_regulateur_cree_et_soumet_un_parametrage_mais_ne_peut_pas_le_confirmer(): void
    {
        $this->en($this->regulateur)->get(route('admin.dividendes.create'))->assertOk();
        $d = $this->distributionSoumise();

        $this->assertSame(StatutDistribution::Soumis, $d->statut);
        $this->assertSame(1, $d->version);
        $this->assertSame(1250, $d->taux_irvm_pb);
        $this->assertNotNull($d->pv_empreinte);
        Storage::disk('local')->assertExists($d->pv_chemin);

        $this->en($this->regulateur)->post(route('admin.dividendes.confirmer', $d))->assertForbidden();
        $this->en($this->regulateur)->post(route('admin.dividendes.rejeter', $d), ['motif' => 'Essai de rejet'])->assertForbidden();
        $this->assertSame(StatutDistribution::Soumis, $d->refresh()->statut);
    }

    public function test_un_parametrage_ne_peut_pas_etre_soumis_sans_pv_d_ag(): void
    {
        $this->en($this->regulateur)->post(route('admin.dividendes.store'), $this->formulaire(avecPv: false))->assertSessionHasNoErrors();
        $d = DistributionDividende::query()->firstOrFail();

        $this->en($this->regulateur)->post(route('admin.dividendes.soumettre', $d), ['montant_conforme_pv' => '1'])
            ->assertSessionHas('erreur', __('admin.dividendes.erreurs.pv_obligatoire'));

        $this->assertSame(StatutDistribution::Brouillon, $d->refresh()->statut);
        $this->assertSame(0, $d->versions()->count());
    }

    public function test_la_soumission_exige_l_attestation_du_montant(): void
    {
        $this->en($this->regulateur)->post(route('admin.dividendes.store'), $this->formulaire());
        $d = DistributionDividende::query()->firstOrFail();

        $this->en($this->regulateur)->post(route('admin.dividendes.soumettre', $d))->assertSessionHasErrors('montant_conforme_pv');
        $this->assertSame(StatutDistribution::Brouillon, $d->refresh()->statut);
    }

    public function test_un_parametrage_par_exercice_et_par_classe_d_actions(): void
    {
        $this->en($this->regulateur)->post(route('admin.dividendes.store'), $this->formulaire())->assertSessionHasNoErrors();

        // Même exercice, actions A : paramétrage distinct, versement par virement uniquement.
        $this->en($this->regulateur)->post(route('admin.dividendes.store'), $this->formulaire(['classe_actions' => 'A']))
            ->assertSessionHasErrors(['canaux_autorises.0' => __('admin.dividendes.erreurs.classe_a_virement')]);
        $this->en($this->regulateur)->post(route('admin.dividendes.store'), $this->formulaire([
            'classe_actions' => 'A', 'canaux_autorises' => ['VIREMENT'], 'canal_defaut' => 'VIREMENT', 'montant_global' => 30_000_000,
        ]))->assertSessionHasNoErrors();

        // Un second paramétrage B pour le même exercice est refusé.
        $this->en($this->regulateur)->post(route('admin.dividendes.store'), $this->formulaire())->assertSessionHasErrors('exercice');

        $this->assertSame(['A', 'B'], DistributionDividende::query()->orderBy('classe_actions')->pluck('classe_actions')->map->value->all());
    }

    public function test_le_pv_doit_etre_un_pdf_et_les_dates_coherentes(): void
    {
        $this->en($this->regulateur)->post(route('admin.dividendes.store'), $this->formulaire([
            'pv' => UploadedFile::fake()->create('pv.docx', 10, 'application/msword'),
            'date_arrete' => '2026-06-01',
            'montant_global' => 200_000_000,
        ]))->assertSessionHasErrors(['pv', 'date_arrete', 'montant_global']);

        $this->assertSame(0, DistributionDividende::query()->count());
    }

    public function test_le_validateur_confirme_ou_rejette_mais_ne_peut_ni_creer_ni_modifier(): void
    {
        $d = $this->distributionSoumise();

        $this->en($this->validateur)->get(route('admin.dividendes.create'))->assertForbidden();
        $this->en($this->validateur)->post(route('admin.dividendes.store'), $this->formulaire(['exercice' => 2024]))->assertForbidden();
        $this->en($this->validateur)->put(route('admin.dividendes.update', $d), $this->formulaire())->assertForbidden();
        $this->en($this->validateur)->post(route('admin.dividendes.soumettre', $d), ['montant_conforme_pv' => '1'])->assertForbidden();

        $this->en($this->validateur)->get(route('admin.dividendes.show', $d))->assertOk()->assertSee('Modifications soumises');
        $this->en($this->validateur)->get(route('admin.dividendes.pv', $d))->assertOk();
        $this->en($this->validateur)->get(route('admin.tableau'))->assertSee('<td class="font-semibold">Dividendes</td>', false)->assertSee('Exercice 2025 · v1');

        $this->en($this->validateur)->post(route('admin.dividendes.rejeter', $d), ['motif' => 'Montant non conforme au PV'])->assertSessionHas('succes');
        $this->assertSame(StatutDistribution::Brouillon, $d->refresh()->statut);
        $this->en($this->regulateur)->get(route('admin.dividendes.show', $d))->assertSee('Montant non conforme au PV');

        $this->en($this->regulateur)->post(route('admin.dividendes.soumettre', $d), ['montant_conforme_pv' => '1']);
        $this->en($this->validateur)->post(route('admin.dividendes.confirmer', $d))->assertSessionHas('succes');
        $this->assertSame(StatutDistribution::Confirme, $d->refresh()->statut);
        $this->assertSame(2, $d->version);
    }

    public function test_le_super_admin_n_a_aucune_action_d_ecriture_sur_les_dividendes(): void
    {
        $d = $this->distributionSoumise();
        $sa = $this->superAdmin;

        $this->en($sa)->get(route('admin.dividendes'))->assertOk();
        $this->en($sa)->get(route('admin.dividendes.show', $d))->assertOk();

        $this->en($sa)->get(route('admin.dividendes.create'))->assertForbidden();
        $this->en($sa)->post(route('admin.dividendes.store'), $this->formulaire(['exercice' => 2024]))->assertForbidden();
        $this->en($sa)->put(route('admin.dividendes.update', $d), $this->formulaire())->assertForbidden();
        $this->en($sa)->post(route('admin.dividendes.soumettre', $d), ['montant_conforme_pv' => '1'])->assertForbidden();
        $this->en($sa)->post(route('admin.dividendes.confirmer', $d))->assertForbidden();
        $this->en($sa)->post(route('admin.dividendes.rejeter', $d), ['motif' => 'Essai de rejet'])->assertForbidden();
        $this->en($sa)->post(route('admin.dividendes.avenant.store', $d), ['motif' => 'Essai'])->assertForbidden();
        $this->en($sa)->get(route('admin.dividendes.simulation', $d))->assertForbidden();

        $this->assertSame(StatutDistribution::Soumis, $d->refresh()->statut);
    }

    public function test_un_parametrage_confirme_ne_change_que_par_avenant_confirme(): void
    {
        $d = $this->distributionConfirmee();

        $this->en($this->regulateur)->put(route('admin.dividendes.update', $d), $this->formulaire(['montant_global' => 40_000_000]))
            ->assertSessionHas('erreur');
        $this->assertSame(50_000_000, $d->refresh()->montant_global);

        $this->en($this->regulateur)->post(route('admin.dividendes.avenant.store', $d), [
            ...$this->formulaire(['seuil_minimal' => 1000], avecPv: false), 'motif' => 'Relèvement du seuil',
        ])->assertSessionHas('succes');
        $avenant = Avenant::query()->firstOrFail();
        $this->assertSame(['seuil_minimal'], array_keys($avenant->nouvelle_valeur));
        $this->assertSame(500, $d->refresh()->seuil_minimal);

        $this->en($this->regulateur)->post(route('admin.dividendes.avenant.confirmer', [$d, $avenant]))->assertForbidden();
        $this->en($this->validateur)->post(route('admin.dividendes.avenant.confirmer', [$d, $avenant]))->assertSessionHas('succes');

        $this->assertSame(1000, $d->refresh()->seuil_minimal);
        $this->assertSame(2, $d->version);
        $this->assertSame(StatutAvenant::Approuve, $avenant->refresh()->statut);
    }

    /**
     * Détentions reproductibles (200 actionnaires, quelques actions bloquées, canaux variés) pour vérifier le moteur.
     *
     * @return list<Detention>
     */
    private function detentions(): array
    {
        mt_srand(2026);
        $canaux = ['ORANGE_MONEY', 'MOOV_MONEY', 'VIREMENT', null];
        $detentions = [];
        for ($i = 1; $i <= 200; $i++) {
            $tirage = mt_rand(1, 100);
            $actions = match (true) {
                $tirage <= 60 => mt_rand(1, 10),
                $tirage <= 90 => mt_rand(10, 100),
                $tirage <= 98 => mt_rand(100, 1_000),
                default => mt_rand(1_000, 5_000),
            };
            $detentions[] = new Detention("actionnaire-{$i}", $actions, bloquee: $i % 67 === 0, canal: $canaux[mt_rand(0, 3)]);
        }
        mt_srand();

        return $detentions;
    }

    public function test_la_simulation_respecte_l_egalite_comptable(): void
    {
        $this->en($this->regulateur)->post(route('admin.dividendes.store'), $this->formulaire(['montant_global' => 49_999_999]));
        $d = DistributionDividende::query()->firstOrFail();
        $service = app(SimulationDistribution::class);

        foreach ([PayeurFrais::Raaga, PayeurFrais::Souscripteur] as $payeur) {
            $d->forceFill(['frais_supportes_par' => $payeur])->save();
            $r = (new MoteurDistribution)->calculer($service->parametres($d), $this->detentions());
            $fraisDeduits = $r->fraisParActionnaire ? $r->totalFrais : 0;

            $this->assertGreaterThan(0, $r->nbBeneficiaires);
            $this->assertSame(49_999_999, $r->totalNetAPayer + $r->totalRetenue + $fraisDeduits + $r->totalReporte + $r->totalConsigne + $r->reliquat);
        }

        // Simulation sur le registre seulement : vide tant qu'aucune action n'est allouée, sans jeu fictif.
        $this->en($this->regulateur)->get(route('admin.dividendes.simulation', $d))->assertOk()->assertSee('aucune action')
            ->assertSee('la simulation sera possible après la première allocation')->assertDontSee('fictif');
        $this->en($this->validateur)->get(route('admin.dividendes.simulation', $d))->assertOk();
        $this->en($this->regulateur)->get(route('admin.dividendes.avis', $d))->assertOk()->assertSee('PV-AGO-2026-01');
    }
}
