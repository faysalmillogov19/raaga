<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutAvenant;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutKyc;
use App\Enums\StatutReservationBoutique;
use App\Models\Avenant;
use App\Models\CampagneBoutiques;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Services\Boutiques\EcheancesCampagnes;
use App\Support\ATraiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * Avenant d'une campagne de boutiques : confirmée (pas encore ouverte), tous les paramètres ; ouverte, date de
 * clôture, présentation, délais, moyens de paiement et élargissement du périmètre seulement. Le RÉGULATEUR propose,
 * le VALIDATEUR confirme (jamais l'auteur) ; rien ne change avant la décision.
 */
class AvenantCampagneTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerActeurs();
    }

    /** Formulaire complet repris de la campagne, avec des surcharges. */
    private function formulaire(CampagneBoutiques $c, array $surcharges = []): array
    {
        return [
            'nom' => $c->nom, 'description' => $c->description,
            'date_ouverture' => $c->date_ouverture->format('Y-m-d\TH:i'), 'date_cloture' => $c->date_cloture->format('Y-m-d\TH:i'),
            'canaux_actifs' => $c->canaux_actifs, 'sites' => $c->sites, 'types' => $c->types,
            'limites' => collect($c->types)->mapWithKeys(fn ($id) => [$id => $c->limitePour((int) $id)])->all(),
            'motif' => 'Ajustement après la réunion du comité', ...$surcharges,
        ];
    }

    private function proposer(CampagneBoutiques $c, array $surcharges = [])
    {
        return $this->en($this->regulateur)->post(route('admin.boutiques.avenant.store', $c), $this->formulaire($c, $surcharges));
    }

    public function test_campagne_confirmee_tous_les_parametres_et_ouverture_bloquee_pendant_l_avenant(): void
    {
        $c = Boutiques::campagneOuverte(['statut' => StatutCampagneBoutiques::Confirmee, 'date_ouverture' => now()->addHour(), 'date_cloture' => now()->addMonth()]);
        $this->en($this->regulateur)->get(route('admin.boutiques.show', $c))->assertOk()->assertSee('data-proposer-avenant', false);
        $this->en($this->regulateur)->get(route('admin.boutiques.avenant', $c))->assertOk()->assertSee('data-avenant-campagne', false)->assertSee('name="motif"', false);

        // Motif obligatoire ; aucun changement : refusé.
        $this->proposer($c, ['motif' => ''])->assertSessionHasErrors('motif');
        $this->proposer($c)->assertSessionHas('erreur', __('admin.parametrage.regles.avenant_vide'));

        $nouvelleOuverture = now()->addDays(2)->setTime(9, 0);
        $this->proposer($c, ['nom' => 'Réservation 2027 (corrigée)', 'date_ouverture' => $nouvelleOuverture->format('Y-m-d\TH:i'),
            'delai_relance_valeur' => 3, 'delai_relance_duree' => 'JOUR'])->assertSessionHas('succes');
        $avenant = Avenant::sole();
        $this->assertEqualsCanonicalizing(['nom', 'date_ouverture', 'delai_relance_valeur', 'delai_relance_duree_id', 'delai_relance_secondes'], array_keys($avenant->nouvelle_valeur));
        // Rien ne change avant la décision, et la campagne ne s'ouvre pas.
        $this->assertSame('Réservation 2027', $c->fresh()->nom);
        $this->travel(2)->hours();
        app(EcheancesCampagnes::class)->appliquer();
        $this->assertSame(StatutCampagneBoutiques::Confirmee, $c->fresh()->statut);
        $this->assertSame(__('admin.parametrage.regles.avenant_en_cours'), app(EcheancesCampagnes::class)->blocage($c->fresh()));
        // Un seul avenant à la fois ; il apparaît dans « À traiter » du VALIDATEUR.
        $this->en($this->regulateur)->get(route('admin.boutiques.show', $c))->assertDontSee('data-proposer-avenant', false);
        $this->assertTrue(collect(ATraiter::pour($this->validateur))->contains(fn ($e) => $e['objet'] === 'Réservation 2027' && $e['statut'] === 'Soumis'));

        // Le VALIDATEUR confirme : valeurs appliquées, nouvelle version.
        $this->en($this->validateur)->post(route('admin.boutiques.avenant.confirmer', [$c, $avenant]))->assertSessionHas('succes');
        $c->refresh();
        $this->assertSame(StatutAvenant::Approuve, $avenant->fresh()->statut);
        $this->assertSame(['Réservation 2027 (corrigée)', 259_200], [$c->nom, $c->delaiListeSecondes('relance')]);
        $this->assertSame($nouvelleOuverture->format('Y-m-d H:i'), $c->date_ouverture->format('Y-m-d H:i'));
        $this->assertSame(StatutCampagneBoutiques::Confirmee, $c->statut);
        $this->assertSame([1, 1], [$c->version, $c->versions()->count()]);
    }

    public function test_l_auteur_ne_confirme_pas_son_avenant_et_le_rejet_ne_change_rien(): void
    {
        $c = Boutiques::campagneOuverte(['statut' => StatutCampagneBoutiques::Confirmee, 'date_ouverture' => now()->addDay(), 'date_cloture' => now()->addMonth()]);
        $this->proposer($c, ['description' => 'Nouvelle présentation.'])->assertSessionHas('succes');
        $avenant = Avenant::sole();
        // Saisi par un VALIDATEUR (cas impossible à l'écran) : quatre yeux contrôlés par le circuit commun.
        $avenant->forceFill(['saisi_par' => $this->validateur->id])->save();
        $this->en($this->validateur)->post(route('admin.boutiques.avenant.confirmer', [$c, $avenant]))->assertSessionHas('erreur', __('admin.parametrage.regles.quatre_yeux'));
        $avenant->forceFill(['saisi_par' => $this->regulateur->id])->save();

        $this->en($this->validateur)->post(route('admin.boutiques.avenant.rejeter', [$c, $avenant]), ['motif' => 'Présentation incomplète'])->assertSessionHas('succes');
        $this->assertSame(StatutAvenant::Rejete, $avenant->fresh()->statut);
        $this->assertSame('Marché central.', $c->fresh()->description);
    }

    public function test_campagne_ouverte_elargissement_seulement_puis_liste_d_attente_reevaluee(): void
    {
        $c = Boutiques::campagneOuverte();
        [$petite, $grande] = $c->types;

        // Retraits et baisse refusés ; nom et ouverture figés ; clôture dans le passé refusée.
        $this->proposer($c, ['types' => [$petite]])->assertSessionHasErrors('types');
        $this->proposer($c, ['limites' => [$petite => 1, $grande => 1], 'sites' => []])->assertSessionHasErrors('sites');
        $this->proposer($c, ['nom' => 'Autre nom'])->assertSessionHasErrors('nom');
        $this->proposer($c, ['date_cloture' => now()->subHour()->format('Y-m-d\TH:i')])->assertSessionHasErrors('date_cloture');
        $this->en($this->regulateur)->get(route('admin.boutiques.avenant', $c))->assertOk()->assertSee('adm-verrouille', false);

        // Un citoyen attributaire d'une petite boutique s'inscrit sur une autre : liste d'attente (limite 1).
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+22670111111']);
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A', 1))->assertSessionHasNoErrors();
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A', 2))->assertSessionHasNoErrors();
        $this->assertSame(1, ReservationBoutique::query()->where('user_id', $citoyen->id)->count());

        // Avenant : clôture prolongée, limite des petites boutiques relevée à 2.
        $cloture = now()->addMonths(2)->setTime(23, 59);
        $this->proposer($c, ['date_cloture' => $cloture->format('Y-m-d\TH:i'), 'limites' => [$petite => 2, $grande => 1]])->assertSessionHas('succes');
        $avenant = Avenant::sole();
        $this->en($this->validateur)->post(route('admin.boutiques.avenant.confirmer', [$c, $avenant]))->assertSessionHas('succes');
        $c->refresh();
        $this->assertSame([$cloture->format('Y-m-d H:i'), 2], [$c->date_cloture->format('Y-m-d H:i'), $c->limitePour($petite)]);
        // Sa deuxième inscription est aussitôt retenue.
        $this->assertSame(StatutReservationBoutique::PreReservee, ReservationBoutique::query()->where('user_id', $citoyen->id)->where('boutique_id', Boutiques::boutique('A', 2)->id)->sole()->statut);
        $this->en($this->regulateur)->get(route('admin.boutiques.show', $c))->assertOk()->assertSee(__('admin.parametrage.avenant_numero', ['n' => $avenant->id]));
    }

    public function test_brouillon_ou_campagne_close_pas_d_avenant(): void
    {
        foreach ([StatutCampagneBoutiques::Brouillon, StatutCampagneBoutiques::Cloturee] as $statut) {
            $c = Boutiques::campagneOuverte(['statut' => $statut, 'nom' => 'Campagne '.$statut->value]);
            $this->en($this->regulateur)->get(route('admin.boutiques.avenant', $c))->assertRedirect(route('admin.boutiques.show', $c))
                ->assertSessionHas('erreur', __('admin.parametrage.regles.avenant_impossible'));
        }
        // Le VALIDATEUR ne propose pas d'avenant.
        $c = Boutiques::campagneOuverte(['nom' => 'Ouverte']);
        $this->en($this->validateur)->get(route('admin.boutiques.avenant', $c))->assertForbidden();
    }
}
