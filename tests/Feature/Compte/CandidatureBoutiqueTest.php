<?php

namespace Tests\Feature\Compte;

use App\Enums\DocumentAccepte;
use App\Enums\Role;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutCandidatureBoutique;
use App\Enums\StatutKyc;
use App\Models\Acceptation;
use App\Models\CampagneBoutiques;
use App\Models\CandidatureBoutique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CandidatureBoutiqueTest extends TestCase
{
    use RefreshDatabase;

    private const TOUS = ['TIRAGE_SANS_GARANTIE', 'CAUTION_SANS_LIEN_ACTIONS', 'REGLEMENT_CAMPAGNE_BOUTIQUES', 'ORIGINE_LICITE_FONDS'];

    private CampagneBoutiques $campagne;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campagne = self::campagneOuverte();
    }

    public static function campagneOuverte(array $attributs = []): CampagneBoutiques
    {
        $campagne = CampagneBoutiques::create([
            'statut' => StatutCampagneBoutiques::Ouverte, 'nom' => 'Attribution 2027', 'description' => 'Marché central.',
            'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addMonth(), 'delai_paiement_heures' => 72,
            'canaux_actifs' => ['ORANGE_MONEY', 'VIREMENT'], 'version' => 2, 'cree_par' => User::factory()->create()->id, ...$attributs,
        ]);
        $campagne->categories()->create(['ordre' => 1, 'libelle' => 'Petite boutique', 'description' => '9 m²', 'nombre_boutiques' => 2, 'caution' => 150_000]);
        $campagne->categories()->create(['ordre' => 2, 'libelle' => 'Grande boutique', 'description' => '24 m²', 'nombre_boutiques' => 1, 'caution' => 500_000]);

        return $campagne;
    }

    private function citoyen(StatutKyc $kyc = StatutKyc::Valide): User
    {
        return User::factory()->role(Role::Citoyen)->create(['statut_kyc' => $kyc]);
    }

    private function categorie(string $libelle): int
    {
        return $this->campagne->categories()->where('libelle', $libelle)->value('id');
    }

    public function test_la_page_publique_presente_les_categories_les_cautions_et_l_independance_des_actions(): void
    {
        $this->get('/boutiques')->assertOk()
            ->assertSee('Attribution 2027')
            ->assertSee('Petite boutique')->assertSee("150\u{00A0}000\u{00A0}FCFA")
            ->assertSee("500\u{00A0}000\u{00A0}FCFA")
            ->assertSee('L’achat d’actions ne donne aucun droit ni priorité pour obtenir une boutique.')
            ->assertSee('tirage au sort')
            ->assertSee('href="'.route('boutiques.candidater').'"', false);

        $this->get('/boutiques/reglement')->assertOk()->assertSee('Règlement — Attribution 2027')->assertSee('Version 2')
            ->assertSee('Les modalités de restitution de la caution des candidats non retenus seront communiquées avant le tirage au sort.');

        // Invité : la candidature exige une connexion.
        $this->get('/boutiques/candidater')->assertRedirect(route('connexion'));
    }

    public function test_sans_campagne_ouverte_un_etat_vide_est_affiche(): void
    {
        $this->campagne->forceFill(['statut' => StatutCampagneBoutiques::Brouillon])->save();

        $this->get('/boutiques')->assertOk()->assertSee('Aucune campagne d’attribution ouverte')->assertDontSee('Attribution 2027');
        $this->actingAs($this->citoyen())->get('/boutiques/candidater')->assertNotFound();
    }

    public function test_une_candidature_est_reservee_avec_la_caution_figee_et_les_engagements_horodates(): void
    {
        $citoyen = $this->citoyen();

        $page = $this->actingAs($citoyen)->get('/boutiques/candidater')->assertOk()
            ->assertSee('Je comprends que les boutiques sont attribuées par tirage au sort');
        $this->assertSame(4, substr_count($page->getContent(), 'name="engagements[]"'));
        $this->assertDoesNotMatchRegularExpression('/name="engagements\[\]"[^>]*\bchecked\b/', $page->getContent());

        $this->actingAs($citoyen)->post('/boutiques/candidater', ['categorie' => $this->categorie('Grande boutique'), 'engagements' => self::TOUS])
            ->assertRedirect();

        $candidature = CandidatureBoutique::sole();
        $this->assertSame(StatutCandidatureBoutique::Reservee, $candidature->statut);
        $this->assertSame(500_000, $candidature->caution);
        $this->assertMatchesRegularExpression('/^BTQ-[A-Z2-9]{8}$/', $candidature->reference);
        $this->assertTrue($candidature->expire_le->between(now()->addHours(71), now()->addHours(73)));

        $acceptations = Acceptation::query()->where('candidature_boutique_id', $candidature->id)->get();
        $this->assertEqualsCanonicalizing(self::TOUS, $acceptations->map(fn ($a) => $a->document->value)->all());
        $this->assertNull($acceptations->first()->souscription_id);
        $this->assertSame('2', $acceptations->firstWhere('document', DocumentAccepte::ReglementCampagneBoutiques)->version_document);

        // La caution reste celle du moment de la candidature, même si la catégorie change ensuite.
        $this->campagne->categories()->update(['caution' => 900_000]);
        $this->actingAs($citoyen)->get(route('boutiques.candidature', $candidature->reference))->assertOk()
            ->assertSee("500\u{00A0}000\u{00A0}FCFA")->assertSee('Caution à payer');
    }

    public function test_refus_sans_les_quatre_engagements_sans_categorie_ou_en_double(): void
    {
        $citoyen = $this->citoyen();

        $this->actingAs($citoyen)->post('/boutiques/candidater', ['categorie' => $this->categorie('Petite boutique'), 'engagements' => array_slice(self::TOUS, 0, 3)])
            ->assertSessionHasErrors(['engagements' => 'Cochez les quatre engagements pour continuer.']);
        $this->actingAs($citoyen)->post('/boutiques/candidater', ['engagements' => self::TOUS])->assertSessionHasErrors('categorie');
        $this->assertSame(0, CandidatureBoutique::count());

        $this->actingAs($citoyen)->post('/boutiques/candidater', ['categorie' => $this->categorie('Petite boutique'), 'engagements' => self::TOUS]);
        $this->actingAs($citoyen)->post('/boutiques/candidater', ['categorie' => $this->categorie('Grande boutique'), 'engagements' => self::TOUS])
            ->assertSessionHasErrors(['categorie' => 'Vous avez déjà une candidature pour cette campagne.']);
        $this->assertSame(1, CandidatureBoutique::count());

        // Une deuxième visite du formulaire renvoie vers la candidature existante.
        $this->actingAs($citoyen)->get('/boutiques/candidater')->assertRedirect(route('boutiques.candidature', CandidatureBoutique::sole()->reference));
    }

    public function test_identite_non_verifiee_et_independance_des_actions(): void
    {
        $citoyen = $this->citoyen(StatutKyc::EnAttente);

        $this->actingAs($citoyen)->get('/boutiques/candidater')->assertOk()->assertSee('Candidature possible après vérification de votre identité.');
        $this->actingAs($citoyen)->post('/boutiques/candidater', ['categorie' => $this->categorie('Petite boutique'), 'engagements' => self::TOUS])
            ->assertSessionHasErrors(['identite' => 'Votre identité doit être vérifiée avant de candidater.']);
        $this->assertSame(0, CandidatureBoutique::count());
    }

    public function test_une_candidature_n_est_visible_que_par_son_titulaire(): void
    {
        $titulaire = $this->citoyen();
        $this->actingAs($titulaire)->post('/boutiques/candidater', ['categorie' => $this->categorie('Petite boutique'), 'engagements' => self::TOUS]);
        $reference = CandidatureBoutique::sole()->reference;

        $this->actingAs($this->citoyen())->get(route('boutiques.candidature', $reference))->assertForbidden();
        $this->actingAs(User::factory()->role(Role::Investisseur)->create())->get('/boutiques/candidater')->assertForbidden();
    }
}
