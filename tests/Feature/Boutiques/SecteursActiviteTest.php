<?php

namespace Tests\Feature\Boutiques;

use App\Enums\Role;
use App\Enums\StatutCarte;
use App\Enums\StatutKyc;
use App\Enums\TypeCarte;
use App\Models\Carte;
use App\Models\FamilleSecteur;
use App\Models\ReservationBoutique;
use App\Models\SecteurActivite;
use App\Models\User;
use Database\Seeders\ReferentielsSeeder;
use Database\Seeders\SecteursActiviteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * Secteurs d'activité (03/11/2026) : 5 familles et 26 secteurs créés par les référentiels ; activité principale demandée
 * à la candidature à une boutique (liste groupée par famille, « Autre activité » avec précision libre), gardée sur le
 * compte, reprise à la candidature suivante, affichée dans le profil, au guichet et dans la fiche d'assistance.
 */
class SecteursActiviteTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerActeurs();
        Notification::fake();
        Storage::fake('local');
        Boutiques::campagneOuverte();
    }

    private function citoyen(): User
    {
        $citoyen = User::factory()->role(Role::Citoyen)->avecIfu()->create(['telephone' => '+22673000001', 'statut_kyc' => StatutKyc::Valide]);
        Boutiques::ifuVerifie($citoyen);

        return $citoyen;
    }

    private function candidature(array $donnees, string $boutique = 'A'): array
    {
        return ['type_piece' => 'CNIB', 'numero_piece' => 'B1234567', 'piece' => UploadedFile::fake()->image('piece.jpg'),
            ...array_diff_key(Boutiques::donneesCommercant(), ['secteur_activite_id' => 0]), ...$donnees];
    }

    public function test_familles_et_secteurs_crees_par_les_referentiels_sans_modifier_l_existant(): void
    {
        $this->seed(ReferentielsSeeder::class);
        $this->assertSame(5, FamilleSecteur::count());
        $this->assertSame(26, SecteurActivite::count());
        $this->assertSame(['Alimentation', 'Habillement et beauté', 'Maison et équipement', 'Autres commerces', 'Services'],
            FamilleSecteur::query()->orderBy('ordre')->pluck('libelle')->all());
        $this->assertSame('Services', SecteurActivite::query()->where('code', SecteurActivite::AUTRE)->sole()->famille->libelle);

        SecteurActivite::query()->where('code', 'EPICERIE')->update(['libelle' => 'Épicerie (modifié)']);
        $this->seed(SecteursActiviteSeeder::class);
        $this->assertSame(26, SecteurActivite::count());
        $this->assertSame('Épicerie (modifié)', SecteurActivite::query()->where('code', 'EPICERIE')->value('libelle'));
    }

    public function test_activite_principale_exigee_a_la_candidature_et_gardee_sur_le_compte(): void
    {
        $citoyen = $this->citoyen();
        $code = Boutiques::boutique('A', 1)->code;
        $tissus = Boutiques::secteur('TISSUS');

        $this->actingAs($citoyen)->get(route('boutiques.boutique', $code))->assertOk()
            ->assertSee('Votre activité principale')->assertSee('<optgroup label="Alimentation">', false)
            ->assertSee('<optgroup label="Habillement et beauté">', false)->assertSee('Tissus et pagnes (Faso Dan Fani, wax)');

        $this->actingAs($citoyen)->post(route('boutiques.reserver', $code), $this->candidature([]))
            ->assertSessionHasErrors(['secteur_activite_id' => 'Choisissez votre activité principale.']);
        $this->assertSame(0, ReservationBoutique::count());

        $this->actingAs($citoyen)->post(route('boutiques.reserver', $code), $this->candidature(['secteur_activite_id' => $tissus]))->assertSessionHasNoErrors();
        $citoyen->refresh();
        $this->assertSame([$tissus, null], [$citoyen->secteur_activite_id, $citoyen->secteur_activite_autre]);

        // Candidature suivante : l'activité est reprise dans la liste.
        $this->actingAs($citoyen)->get(route('boutiques.boutique', Boutiques::boutique('A', 2)->code))
            ->assertSee('<option value="'.$tissus.'" selected>', false);
    }

    public function test_autre_activite_demande_une_precision_libre(): void
    {
        $citoyen = $this->citoyen();
        $code = Boutiques::boutique('A', 1)->code;
        $autre = Boutiques::secteur(SecteurActivite::AUTRE);

        $this->actingAs($citoyen)->post(route('boutiques.reserver', $code), $this->candidature(['secteur_activite_id' => $autre, 'secteur_activite_autre' => '  ']))
            ->assertSessionHasErrors(['secteur_activite_autre' => 'Précisez votre activité.']);
        $this->actingAs($citoyen)->post(route('boutiques.reserver', $code), $this->candidature(['secteur_activite_id' => $autre, 'secteur_activite_autre' => 'Vente de pagnes tissés']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Vente de pagnes tissés', $citoyen->fresh()->secteur_activite_autre);
        $this->assertSame('Vente de pagnes tissés', SecteurActivite::libellePour($citoyen->fresh()));

        // Un autre secteur efface la précision.
        $this->actingAs($citoyen)->post(route('boutiques.reserver', Boutiques::boutique('A', 2)->code),
            $this->candidature(['secteur_activite_id' => Boutiques::secteur('BOISSONS'), 'secteur_activite_autre' => 'ignoré']))->assertSessionHasNoErrors();
        $this->assertNull($citoyen->fresh()->secteur_activite_autre);
    }

    public function test_activite_affichee_dans_le_profil_au_guichet_et_dans_l_assistance(): void
    {
        $citoyen = $this->citoyen();
        $citoyen->forceFill(['secteur_activite_id' => Boutiques::secteur('POISSON')])->save();
        Carte::query()->forceCreate(['type' => TypeCarte::Commercant, 'user_id' => $citoyen->id, 'statut' => StatutCarte::Active, 'courante' => true,
            'jeton' => str_repeat('a', 64), 'numero' => $citoyen->ifu, 'version' => 1, 'contenu' => []]);

        $this->actingAs($citoyen)->get('/espace?onglet=profil')->assertOk()->assertSeeInOrder(['Activité principale', 'Poisson frais, fumé ou séché']);

        $this->app['auth']->forgetGuards();
        $agent = User::factory()->role(Role::AgentGuichet)->create();
        $this->en($agent)->get(route('admin.guichet.titulaire', $citoyen))->assertOk()->assertSee('data-secteur-activite', false)
            ->assertSee('Poisson frais, fumé ou séché');
        $this->en(User::factory()->role(Role::Admin)->create())->get(route('admin.assistance.show', $citoyen))->assertOk()->assertSeeInOrder(['Activité principale', 'Poisson frais, fumé ou séché']);
    }
}
