<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutSite;
use App\Enums\StatutTypeBoutique;
use App\Models\AuditLog;
use App\Models\DecisionPhase;
use App\Models\Niveau;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/** Soumission groupée à validation depuis les listes de gestion, retrait de la validation, démarches proposées par le RÉGULATEUR. */
class SoumissionGroupeeTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private User $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
        $this->gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->en($this->gestionnaire)->post(route('admin.sites.types.store'), ['code' => 'BOUT', 'libelle' => 'Petite boutique', 'attribuable' => 1]);
        $this->en($this->validateur)->post(route('admin.sites.types.confirmer', TypeBoutique::query()->sole()))->assertSessionHas('succes');
        // RAA confirmé, SAR prêt (une boutique), A04 sans boutique.
        foreach (['RAA' => true, 'SAR' => true, 'A04' => false] as $code => $boutique) {
            $this->en($this->gestionnaire)->post(route('admin.sites.store'), ['code' => $code, 'nom' => 'Marché '.$code, 'region' => 'Centre', 'ville' => 'Ouagadougou']);
            if ($boutique) {
                $this->en($this->gestionnaire)->post(route('admin.referencement.boutiques.store', $this->site($code)), [
                    'niveau' => 'R1', 'bloc' => 'A', 'numero' => 1, 'surface' => 9, 'type_boutique_id' => TypeBoutique::query()->value('id'), 'etat_physique' => 'LIVREE',
                ]);
            }
        }
        $this->en($this->gestionnaire)->post(route('admin.sites.soumettre', $this->site('RAA')))->assertSessionHas('succes');
        $this->en($this->validateur)->post(route('admin.sites.confirmer', $this->site('RAA')))->assertSessionHas('succes');
    }

    private function site(string $code): Site
    {
        return Site::query()->where('code', $code)->sole();
    }

    private function cle(string $code): string
    {
        return 'site:'.$this->site($code)->id;
    }

    public function test_cases_uniquement_sur_les_lignes_soumissibles(): void
    {
        $page = $this->en($this->gestionnaire)->get(route('admin.sites'))->assertOk();

        $page->assertSee('value="'.$this->cle('SAR').'" data-etat="soumettre"', false)->assertSee('value="'.$this->cle('A04').'" data-etat="soumettre"', false)
            ->assertDontSee('value="'.$this->cle('RAA').'"', false)
            ->assertSee('data-tout-cocher', false)->assertSee('À soumettre (2)')->assertSee('Soumettre à validation');

        // Filtre rapide : brouillons et rejetés seulement.
        $this->en($this->gestionnaire)->get(route('admin.sites', ['a_soumettre' => 1]))->assertSee('aria-current="true"', false)
            ->assertSee('data-libelle="Code">SAR<', false)->assertDontSee('data-libelle="Code">RAA<', false);
    }

    public function test_soumission_groupee_avec_resultat_partiel(): void
    {
        $this->en($this->gestionnaire)->post(route('admin.soumission.soumettre'), ['elements' => [$this->cle('SAR'), $this->cle('A04')], 'commentaire' => 'Recensement terminé'])
            ->assertSessionHas('succes', '1 soumis, 1 refusé : A04, ajoutez au moins une boutique avant de soumettre le site.');

        $this->assertSame(StatutSite::Soumis, $this->site('SAR')->statut);
        $this->assertSame(StatutSite::Brouillon, $this->site('A04')->statut);
        $this->assertTrue(AuditLog::query()->where('action', 'site.soumis')->where('entite_id', $this->site('SAR')->id)->exists());

        // Apparition dans « À valider › Sites », avec le commentaire.
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'sites'))->assertSee('SAR · Marché SAR')->assertDontSee('A04 · Marché A04');
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', ['rubrique' => 'sites', 'detail' => $this->cle('SAR')]))->assertSee('Recensement terminé');

        // Soumis entre-temps : « déjà soumis ».
        $this->en($this->gestionnaire)->post(route('admin.soumission.soumettre'), ['elements' => [$this->cle('SAR')]])
            ->assertSessionHas('erreur', 'Aucun élément soumis, 1 refusé : SAR, déjà soumis.');
    }

    public function test_controle_prealable_sans_effet(): void
    {
        $reponse = $this->en($this->gestionnaire)->postJson(route('admin.soumission.controle'), ['elements' => [$this->cle('SAR'), $this->cle('A04')]])->assertOk();

        $this->assertSame([
            ['cle' => $this->cle('SAR'), 'libelle' => 'SAR', 'pret' => true, 'raison' => null],
            ['cle' => $this->cle('A04'), 'libelle' => 'A04', 'pret' => false, 'raison' => 'Ajoutez au moins une boutique avant de soumettre le site.'],
        ], $reponse->json('elements'));
        $this->assertSame(StatutSite::Brouillon, $this->site('SAR')->statut);
        $this->assertFalse(AuditLog::query()->where('action', 'site.soumis')->where('entite_id', $this->site('SAR')->id)->exists());
    }

    public function test_retrait_de_la_validation(): void
    {
        $this->en($this->gestionnaire)->post(route('admin.soumission.soumettre'), ['elements' => [$this->cle('SAR')]])->assertSessionHas('succes');
        $this->en($this->gestionnaire)->get(route('admin.sites'))->assertSee('value="'.$this->cle('SAR').'" data-etat="retirer"', false)->assertSee('data-retirer-ligne', false);

        // Pas son élément : refusé.
        $autre = User::factory()->role(Role::GestionnaireSites)->create();
        $this->en($autre)->post(route('admin.soumission.retirer'), ['elements' => [$this->cle('SAR')]])->assertSessionHas('erreur');
        $this->assertSame(StatutSite::Soumis, $this->site('SAR')->statut);

        $this->en($this->gestionnaire)->post(route('admin.soumission.retirer'), ['elements' => [$this->cle('SAR')]])->assertSessionHas('succes', '1 retiré.');
        $this->assertSame(StatutSite::Brouillon, $this->site('SAR')->statut);
        $this->assertTrue(AuditLog::query()->where('action', 'site.retire')->where('entite_id', $this->site('SAR')->id)->where('acteur_id', $this->gestionnaire->id)->exists());
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'sites'))->assertDontSee('SAR · Marché SAR');

        // Référentiel : retiré (brouillon), puis soumis de nouveau depuis la liste.
        $this->en($this->gestionnaire)->post(route('admin.referencement.zones.store', 'niveaux'), ['code' => 'R4', 'libelle' => 'R+4', 'rang' => 4])->assertSessionHas('succes');
        $r4 = Niveau::query()->where('code', 'R4')->sole();
        $this->en($this->gestionnaire)->post(route('admin.soumission.retirer'), ['elements' => ['niveaux:'.$r4->id]])->assertSessionHas('succes');
        $this->assertSame(StatutTypeBoutique::Brouillon, $r4->refresh()->statut);
        $this->en($this->gestionnaire)->post(route('admin.soumission.soumettre'), ['elements' => ['niveaux:'.$r4->id]])->assertSessionHas('succes');
        $this->assertSame(StatutTypeBoutique::AValider, $r4->refresh()->statut);
    }

    public function test_droits_par_profil(): void
    {
        $sar = $this->site('SAR');
        // GESTIONNAIRE : ni grille ni campagne ; RÉGULATEUR : ni site ni référentiel du GESTIONNAIRE.
        $this->en($this->gestionnaire)->post(route('admin.soumission.soumettre'), ['elements' => ['grille:'.$sar->id]])->assertForbidden();
        $this->en($this->regulateur)->post(route('admin.soumission.soumettre'), ['elements' => [$this->cle('SAR')]])->assertForbidden();
        $this->en($this->regulateur)->postJson(route('admin.soumission.controle'), ['elements' => ['type:'.TypeBoutique::query()->value('id')]])->assertForbidden();
        $this->en($this->gestionnaire)->post(route('admin.soumission.soumettre'), ['elements' => [$this->cle('SAR'), 'campagne:1']])->assertForbidden();
        $this->assertSame(StatutSite::Brouillon, $sar->refresh()->statut);
    }

    public function test_super_admin_sans_case_ni_bouton(): void
    {
        // Plus d'accès aux sites depuis le 30/10/2026.
        $this->en($this->superAdmin)->get(route('admin.sites'))->assertForbidden();
        $this->en($this->superAdmin)->post(route('admin.soumission.soumettre'), ['elements' => [$this->cle('SAR')]])->assertForbidden();
        $this->en($this->superAdmin)->post(route('admin.phase.proposer'), ['presentation_actions' => 1, 'souscription_actions' => 0, 'motif' => 'Ouverture prochaine'])->assertForbidden();
        // Démarches du site public : plus d'accès depuis le 25/10/2026 (RÉGULATEUR et VALIDATEUR seuls).
        $this->en($this->superAdmin)->get(route('admin.phase'))->assertForbidden();
    }

    public function test_le_regulateur_propose_une_demarche(): void
    {
        $this->en($this->regulateur)->post(route('admin.phase.proposer'), ['presentation_actions' => 1, 'souscription_actions' => 0, 'motif' => 'Ouverture prochaine'])
            ->assertSessionHas('succes');
        $decision = DecisionPhase::query()->sole();
        $this->assertSame($this->regulateur->id, $decision->propose_par);
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'demarches'))->assertSee('aria-label="Valider Présentation des actions visible', false);

        // Retrait par son auteur : la proposition quitte « À valider ».
        $this->en($this->regulateur)->post(route('admin.phase.retirer', $decision->id))->assertSessionHas('succes');
        $this->assertSame(DecisionPhase::RETIREE, $decision->refresh()->statut);
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'demarches'))->assertSee('Rien à valider dans cette rubrique.');
    }
}
