<?php

namespace Tests\Feature\Admin;

use App\Enums\StatutAvenant;
use App\Enums\StatutTranche;
use App\Http\Controllers\Admin\TranchesController;
use App\Models\AuditLog;
use App\Models\Avenant;
use App\Models\ParametrageDecision;
use App\Models\Tranche;
use App\Models\User;
use BackedEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\ActeursParametrage;
use Tests\Support\ParametresInvestissementConfirmes;
use Tests\TestCase;

class TranchesWorkflowTest extends TestCase
{
    use ActeursParametrage, ParametresInvestissementConfirmes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
        // Valeur indicative et minimum confirmés : ils préremplissent chaque nouvelle tranche.
        $this->confirmerParametresInvestissement(10_000, objectif: 3_000_000_000, minimum: 1);
    }

    /** Formulaire complet des 6 sections, tel que le navigateur l'envoie. */
    private function formulaire(array $surcharges = []): array
    {
        $valeurs = [...TranchesController::valeursParDefaut(), 'nom' => 'Tranche 1 · 2027'];
        foreach ($valeurs as $champ => $valeur) {
            $valeurs[$champ] = match (true) {
                $valeur instanceof BackedEnum => $valeur->value,
                $valeur instanceof \DateTimeInterface => $valeur->format('Y-m-d\TH:i'),
                is_bool($valeur) => $valeur ? '1' : '0',
                default => $valeur,
            };
        }

        return [...$valeurs, ...$surcharges];
    }

    private function trancheSoumise(): Tranche
    {
        $this->en($this->regulateur)->post(route('admin.tranches.store'), $this->formulaire())->assertRedirect();
        $tranche = Tranche::query()->latest('id')->firstOrFail();
        $this->en($this->regulateur)->post(route('admin.tranches.soumettre', $tranche))->assertRedirect();

        return $tranche->refresh();
    }

    private function trancheConfirmee(): Tranche
    {
        $tranche = $this->trancheSoumise();
        $this->en($this->validateur)->post(route('admin.tranches.confirmer', $tranche))->assertRedirect();

        return $tranche->refresh();
    }

    private function activerTotp(User $user): string
    {
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey(32);
        $user->forceFill(['totp_secret' => $secret, 'totp_active_le' => now()])->save();

        return $google2fa->oathTotp($secret, $google2fa->getTimestamp());
    }

    public function test_le_regulateur_cree_et_soumet_une_tranche_mais_ne_peut_pas_la_confirmer(): void
    {
        $this->en($this->regulateur)->get(route('admin.tranches.create'))->assertOk();
        $this->en($this->regulateur)->post(route('admin.tranches.store'), $this->formulaire())->assertSessionHasNoErrors();

        $tranche = Tranche::query()->firstOrFail();
        $this->assertSame(StatutTranche::Brouillon, $tranche->statut);

        $this->en($this->regulateur)->post(route('admin.tranches.soumettre', $tranche))->assertSessionHas('succes');
        $tranche->refresh();
        $this->assertSame(StatutTranche::Soumise, $tranche->statut);
        $this->assertSame(1, $tranche->version);
        $this->assertSame(1, $tranche->versions()->count());
        $this->assertSame($this->regulateur->id, $tranche->versions()->first()->auteur_id);

        $this->en($this->regulateur)->post(route('admin.tranches.confirmer', $tranche))->assertForbidden();
        $this->en($this->regulateur)->post(route('admin.tranches.rejeter', $tranche), ['motif' => 'Essai de rejet'])->assertForbidden();
        $this->en($this->regulateur)->post(route('admin.tranches.ouvrir', $tranche), ['confirmation' => 'CONFIRMER'])->assertForbidden();
        $this->assertSame(StatutTranche::Soumise, $tranche->refresh()->statut);
    }

    public function test_le_validateur_confirme_mais_ne_peut_ni_creer_ni_modifier(): void
    {
        $tranche = $this->trancheSoumise();

        $this->en($this->validateur)->get(route('admin.tranches.create'))->assertForbidden();
        $this->en($this->validateur)->post(route('admin.tranches.store'), $this->formulaire())->assertForbidden();
        $this->en($this->validateur)->get(route('admin.tranches.edit', $tranche))->assertForbidden();
        $this->en($this->validateur)->put(route('admin.tranches.update', $tranche), $this->formulaire())->assertForbidden();
        $this->en($this->validateur)->post(route('admin.tranches.soumettre', $tranche))->assertForbidden();
        $this->en($this->validateur)->post(route('admin.tranches.avenant.store', $tranche), ['motif' => 'Essai'])->assertForbidden();

        $this->en($this->validateur)->get(route('admin.tranches.show', $tranche))->assertOk()->assertSee('Modifications soumises');
        $this->en($this->validateur)->post(route('admin.tranches.confirmer', $tranche))->assertSessionHas('succes');

        $this->assertSame(StatutTranche::Confirmee, $tranche->refresh()->statut);
        $this->assertDatabaseHas('parametrage_decisions', ['objet_id' => $tranche->id, 'decision' => 'CONFIRME', 'decideur_id' => $this->validateur->id]);
        $this->assertTrue(AuditLog::query()->where('action', 'tranche.confirme')->exists());
    }

    public function test_le_rejet_exige_un_motif_et_renvoie_en_brouillon_avec_le_motif_visible(): void
    {
        $tranche = $this->trancheSoumise();

        $this->en($this->validateur)->post(route('admin.tranches.rejeter', $tranche), ['motif' => ''])->assertSessionHasErrors('motif');
        $this->assertSame(StatutTranche::Soumise, $tranche->refresh()->statut);

        $this->en($this->validateur)->post(route('admin.tranches.rejeter', $tranche), ['motif' => 'Objectif trop élevé'])->assertSessionHas('succes');
        $tranche->refresh();
        $this->assertSame(StatutTranche::Brouillon, $tranche->statut);
        $this->assertSame('Objectif trop élevé', $tranche->motif_rejet);
        $this->assertSame(1, ParametrageDecision::query()->where('decision', 'REJETE')->count());

        $this->en($this->regulateur)->get(route('admin.tranches.show', $tranche))->assertSee('Objectif trop élevé');
        $this->en($this->regulateur)->get(route('admin.tableau'))->assertSee('Objectif trop élevé')->assertSee('Rejetée');

        // Le régulateur corrige et soumet de nouveau : une deuxième version est figée.
        $this->en($this->regulateur)->put(route('admin.tranches.update', $tranche), $this->formulaire(['objectif_montant' => 2_000_000_000]))->assertSessionHasNoErrors();
        $this->en($this->regulateur)->post(route('admin.tranches.soumettre', $tranche));
        $this->assertSame(2, $tranche->refresh()->version);
        $this->assertNull($tranche->motif_rejet);
    }

    public function test_le_validateur_voit_les_soumissions_dans_a_traiter(): void
    {
        $tranche = $this->trancheSoumise();

        $this->en($this->validateur)->get(route('admin.tableau'))->assertOk()
            ->assertSee('À traiter')->assertSee($tranche->nom)->assertSee('Examiner')->assertSee('Soumise');
        $this->en($this->regulateur)->get(route('admin.tableau'))->assertOk()->assertSee('Rien à traiter');
    }

    public function test_le_super_admin_n_a_aucune_action_d_ecriture_sur_les_tranches(): void
    {
        $tranche = $this->trancheSoumise();
        $sa = $this->superAdmin;

        $this->en($sa)->get(route('admin.tranches'))->assertOk();
        $this->en($sa)->get(route('admin.tranches.show', $tranche))->assertOk();

        $this->en($sa)->get(route('admin.tranches.create'))->assertForbidden();
        $this->en($sa)->post(route('admin.tranches.store'), $this->formulaire())->assertForbidden();
        $this->en($sa)->put(route('admin.tranches.update', $tranche), $this->formulaire())->assertForbidden();
        $this->en($sa)->post(route('admin.tranches.soumettre', $tranche))->assertForbidden();
        $this->en($sa)->post(route('admin.tranches.confirmer', $tranche))->assertForbidden();
        $this->en($sa)->post(route('admin.tranches.rejeter', $tranche), ['motif' => 'Essai de rejet'])->assertForbidden();
        $this->en($sa)->post(route('admin.tranches.avenant.store', $tranche), ['motif' => 'Essai'])->assertForbidden();
        $this->en($sa)->post(route('admin.tranches.ouvrir', $tranche), ['confirmation' => 'CONFIRMER'])->assertForbidden();
        $this->en($sa)->post(route('admin.tranches.cloturer', $tranche), ['confirmation' => 'CONFIRMER'])->assertForbidden();

        $this->assertSame(StatutTranche::Soumise, $tranche->refresh()->statut);
    }

    public function test_une_tranche_confirmee_ne_change_que_par_avenant_confirme(): void
    {
        $tranche = $this->trancheConfirmee();
        $objectif = $tranche->objectif_montant;

        // Modification directe refusée.
        $this->en($this->regulateur)->get(route('admin.tranches.edit', $tranche))->assertRedirect(route('admin.tranches.show', $tranche));
        $this->en($this->regulateur)->put(route('admin.tranches.update', $tranche), $this->formulaire(['objectif_montant' => 1_000_000_000]))
            ->assertSessionHas('erreur');
        $this->assertSame($objectif, $tranche->refresh()->objectif_montant);

        // Avenant soumis : rien ne change tant qu'il n'est pas confirmé.
        $this->en($this->regulateur)->post(route('admin.tranches.avenant.store', $tranche), [
            ...$this->formulaire(['objectif_montant' => 1_000_000_000]), 'motif' => 'Réduction de l’objectif',
        ])->assertSessionHas('succes');
        $avenant = Avenant::query()->firstOrFail();
        $this->assertSame(StatutAvenant::EnAttente, $avenant->statut);
        $this->assertSame(['objectif_montant'], array_keys($avenant->nouvelle_valeur));
        $this->assertSame($objectif, $tranche->refresh()->objectif_montant);

        // Le régulateur ne peut pas confirmer son avenant ; le validateur ne peut pas en proposer.
        $this->en($this->regulateur)->post(route('admin.tranches.avenant.confirmer', [$tranche, $avenant]))->assertForbidden();

        $this->en($this->validateur)->post(route('admin.tranches.avenant.confirmer', [$tranche, $avenant]))->assertSessionHas('succes');
        $tranche->refresh();
        $this->assertSame(1_000_000_000, $tranche->objectif_montant);
        $this->assertSame(2, $tranche->version);
        $this->assertSame(StatutAvenant::Approuve, $avenant->refresh()->statut);
    }

    public function test_la_creation_est_preremplie_par_les_parametres_d_investissement_et_ajustable(): void
    {
        // Prix et minimum préremplis avec la valeur indicative et le nombre minimum d'actions confirmés.
        $this->en($this->regulateur)->get(route('admin.tranches.create'))->assertOk()
            ->assertSee('name="valeur_unitaire" type="number" value="10000"', false)
            ->assertSee('name="min_actions_par_souscription" type="number" value="1"', false)
            ->assertSee('Prérempli avec la valeur indicative confirmée');

        // Le RÉGULATEUR peut les ajuster avant la confirmation.
        $this->en($this->regulateur)->post(route('admin.tranches.store'), $this->formulaire(['valeur_unitaire' => 12_000, 'min_actions_par_souscription' => 2]))
            ->assertSessionHasNoErrors();
        $tranche = Tranche::query()->latest('id')->firstOrFail();
        $this->assertSame([12_000, 2], [(int) $tranche->valeur_unitaire, $tranche->min_actions_par_souscription]);
    }

    public function test_prix_et_minimum_figes_apres_confirmation_de_la_tranche(): void
    {
        $tranche = $this->trancheConfirmee();
        $this->assertSame([10_000, 1], [(int) $tranche->valeur_unitaire, $tranche->min_actions_par_souscription]);

        // Formulaire d'avenant : prix et minimum affichés, non modifiables.
        $this->en($this->regulateur)->get(route('admin.tranches.avenant', $tranche))->assertOk()
            ->assertSee('Figé depuis la confirmation de la tranche.')->assertDontSee('name="valeur_unitaire" type="number"', false);

        // Avenant sur le prix ou le minimum : refusé, rien ne change.
        foreach ([['valeur_unitaire' => 12_000], ['min_actions_par_souscription' => 5]] as $changement) {
            $this->en($this->regulateur)->post(route('admin.tranches.avenant.store', $tranche), [...$this->formulaire($changement), 'motif' => 'Nouveau prix'])
                ->assertSessionHas('erreur');
        }
        $this->assertSame(0, Avenant::query()->count());

        // Une nouvelle valeur indicative ne touche pas la tranche confirmée.
        $this->confirmerParametresInvestissement(15_000, minimum: 3);
        $tranche->refresh();
        $this->assertSame([10_000, 1], [(int) $tranche->valeur_unitaire, $tranche->min_actions_par_souscription]);
    }

    public function test_un_avenant_rejete_ne_change_rien(): void
    {
        $tranche = $this->trancheConfirmee();

        $this->en($this->regulateur)->post(route('admin.tranches.avenant.store', $tranche), [
            ...$this->formulaire(['delai_paiement_heures' => 24]), 'motif' => 'Délai plus court',
        ]);
        $avenant = Avenant::query()->firstOrFail();

        $this->en($this->validateur)->post(route('admin.tranches.avenant.rejeter', [$tranche, $avenant]), ['motif' => 'Délai trop court'])->assertSessionHas('succes');

        $this->assertSame(StatutAvenant::Rejete, $avenant->refresh()->statut);
        $this->assertSame(48, $tranche->refresh()->delai_paiement_heures);
        $this->assertSame(1, $tranche->version);
    }

    public function test_l_ouverture_exige_la_confirmation_renforcee_et_seule_la_date_de_cloture_reste_modifiable(): void
    {
        $tranche = $this->trancheConfirmee();
        $code = $this->activerTotp($this->validateur);

        $this->en($this->validateur)->post(route('admin.tranches.ouvrir', $tranche), ['confirmation' => 'confirmer', 'code_totp' => $code])->assertSessionHas('erreur');
        $this->en($this->validateur)->post(route('admin.tranches.ouvrir', $tranche), ['confirmation' => 'CONFIRMER', 'code_totp' => '000000'])->assertSessionHas('erreur');
        $this->assertSame(StatutTranche::Confirmee, $tranche->refresh()->statut);

        $this->en($this->validateur)->post(route('admin.tranches.ouvrir', $tranche), ['confirmation' => 'CONFIRMER', 'code_totp' => $code])->assertSessionHas('succes');
        $this->assertSame(StatutTranche::Ouverte, $tranche->refresh()->statut);

        // Sur une tranche ouverte, l'avenant ne porte que sur la date de clôture.
        $nouvelleCloture = $tranche->date_cloture->copy()->addWeeks(2)->format('Y-m-d\TH:i');
        $this->en($this->regulateur)->post(route('admin.tranches.avenant.store', $tranche), [
            ...$this->formulaire(['objectif_montant' => 1_000_000_000]), 'date_cloture' => $nouvelleCloture, 'motif' => 'Prolongation de deux semaines',
        ])->assertSessionHas('succes');

        $this->assertSame(['date_cloture'], array_keys(Avenant::query()->firstOrFail()->nouvelle_valeur));
    }
}
