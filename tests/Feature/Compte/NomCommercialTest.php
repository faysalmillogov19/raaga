<?php

namespace Tests\Feature\Compte;

use App\Enums\Role;
use App\Enums\StatutCarte;
use App\Enums\StatutKyc;
use App\Enums\TypeCarte;
use App\Models\AuditLog;
use App\Models\Commune;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Services\Boutiques\ReservationsBoutiques;
use App\Services\Cartes\Cartes;
use Database\Seeders\CommunesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Boutiques;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/**
 * Nom commercial (réunion du 05/11/2026, lot 3) : facultatif, saisi dans « Mon profil » une fois l'IFU validé ; affiché
 * sur la fiche de la boutique, dans le dossier d'identité, au guichet et dans « Mes boutiques » ; jamais sur la carte.
 */
class NomCommercialTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    private function profil(User $user, array $modifs = []): array
    {
        return ['nom' => $user->nom ?? 'OUEDRAOGO', 'prenoms' => $user->prenoms ?? 'Awa', 'date_naissance' => '1990-01-01', 'pays_residence' => 'BF',
            'commune_id' => (string) Commune::query()->where('code', 'BF130004')->value('id'), 'email' => '', ...$modifs];
    }

    public function test_saisi_seulement_une_fois_l_ifu_valide_et_jamais_sur_la_carte(): void
    {
        $this->seed(CommunesSeeder::class);
        Boutiques::campagneOuverte();
        $commune = Commune::query()->where('code', 'BF130004')->value('id');

        // IFU pas encore validé : champ absent, saisie refusée.
        $sans = User::factory()->role(Role::Citoyen)->create(['ifu' => '00011111A', 'nom' => 'OUEDRAOGO', 'prenoms' => 'Awa', 'date_naissance' => '1990-01-01', 'commune_id' => $commune]);
        $this->actingAs($sans)->get(route('espace.profil'))->assertOk()->assertSee('data-nom-commercial-attente', false)->assertDontSee('name="nom_commercial"', false);
        $this->actingAs($sans)->post(route('espace.profil.update'), $this->profil($sans, ['nom_commercial' => 'Chez Awa']))->assertSessionHasErrors('nom_commercial');
        $this->assertNull($sans->fresh()->nom_commercial);

        // IFU validé : saisie possible, journalisée.
        $user = User::factory()->role(Role::Citoyen)->avecIfu()->create(['statut_kyc' => StatutKyc::Valide, 'nom' => 'OUEDRAOGO', 'prenoms' => 'Awa',
            'date_naissance' => '1990-01-01', 'commune_id' => $commune, 'piece_expire_le' => now()->addYears(5)->toDateString()]);
        $this->actingAs($user)->get(route('espace.profil'))->assertOk()->assertSee('name="nom_commercial"', false);
        $this->actingAs($user)->post(route('espace.profil.update'), $this->profil($user, ['nom_commercial' => '  Pagnes   d’Awa  ']))->assertSessionHasNoErrors();
        $this->assertSame('Pagnes d’Awa', $user->fresh()->nom_commercial);
        $this->assertContains('nom_commercial', AuditLog::query()->where('action', 'profil.modifie')->latest('id')->value('nouvelle_valeur')['champs']);

        // Boutique attribuée : fiche de la boutique (back-office), dossier, guichet, Mes boutiques ; jamais la carte.
        $boutique = Boutiques::boutique('A', 1);
        $r = ReservationBoutique::create(['reference' => 'BTQ-NOMCOM01', 'user_id' => $user->id, 'campagne_boutiques_id' => Boutiques::campagneOuverte()->id,
            'boutique_id' => $boutique->id, 'caution' => 540_000, 'avance' => 135_000, 'mois_avance' => 3, 'statut' => 'RESERVEE',
            'expire_le' => now(), 'avance_payee_le' => now(), 'reference_avance' => 'PAY-NC']);
        app(ReservationsBoutiques::class)->attribuer($r, now()->toDateString(), User::factory()->role(Role::Validateur)->create());
        $carte = Cartes::courante($user->fresh(), TypeCarte::Commercant);
        $this->assertSame(StatutCarte::Active, $carte->statut);
        $this->assertStringNotContainsString('Pagnes', json_encode($carte->contenu, JSON_UNESCAPED_UNICODE));

        $this->actingAs($user->fresh())->get(route('espace', ['onglet' => 'boutiques']))->assertSee('data-nom-commercial', false)->assertSee('Pagnes d’Awa', false);
        $this->connecterBackoffice(Role::GestionnaireSites);
        $this->get(route('admin.referencement.boutiques.show', $boutique))->assertOk()->assertSee('Nom commercial de l’attributaire', false)->assertSee('Pagnes d’Awa', false);
        $this->connecterBackoffice(Role::Admin);
        $this->get(route('admin.kyc.dossier', $user))->assertOk()->assertSee('data-nom-commercial', false)->assertSee('Pagnes d’Awa', false);
        $this->get(route('admin.guichet.titulaire', $user))->assertOk()->assertSee('Pagnes d’Awa', false);
    }
}
