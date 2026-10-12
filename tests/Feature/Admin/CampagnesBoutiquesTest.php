<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutCampagneBoutiques;
use App\Models\CampagneBoutiques;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

class CampagnesBoutiquesTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
    }

    private function activerTotp(User $user): string
    {
        $google2fa = new Google2FA;
        $user->forceFill(['totp_secret' => $google2fa->generateSecretKey(32), 'totp_active_le' => now()])->save();

        return $this->codeTotp($user);
    }

    private function codeTotp(User $user, int $decalage = 0): string
    {
        $google2fa = new Google2FA;

        // Le rejeu d'un code est refusé : une seconde action utilise le code de la fenêtre suivante (tolérée).
        return $google2fa->oathTotp($user->fresh()->totp_secret, $google2fa->getTimestamp() + $decalage);
    }

    private function formulaire(array $surcharges = []): array
    {
        return [
            'nom' => 'Attribution 2027 — marché central',
            'description' => 'Boutiques du nouveau marché, attribuées par tirage au sort.',
            'date_ouverture' => now()->addDay()->format('Y-m-d\TH:i'),
            'date_cloture' => now()->addMonth()->format('Y-m-d\TH:i'),
            'delai_paiement_heures' => 72,
            'canaux_actifs' => ['ORANGE_MONEY', 'VIREMENT'],
            'categories' => [
                ['libelle' => 'Petite boutique', 'description' => '9 m², allée intérieure', 'nombre_boutiques' => 120, 'caution' => 150000],
                ['libelle' => 'Grande boutique', 'description' => '24 m², façade', 'nombre_boutiques' => 30, 'caution' => 500000],
                ['libelle' => '', 'description' => '', 'nombre_boutiques' => '', 'caution' => ''], // ligne vide ignorée
            ],
            ...$surcharges,
        ];
    }

    private function campagneSoumise(): CampagneBoutiques
    {
        $this->en($this->regulateur)->post(route('admin.boutiques.store'), $this->formulaire())->assertRedirect()->assertSessionHasNoErrors();
        $campagne = CampagneBoutiques::query()->firstOrFail();
        $this->en($this->regulateur)->post(route('admin.boutiques.soumettre', $campagne))->assertSessionHas('succes');

        return $campagne->refresh();
    }

    public function test_le_regulateur_saisit_les_categories_et_les_cautions_puis_soumet(): void
    {
        $campagne = $this->campagneSoumise();

        $this->assertSame(StatutCampagneBoutiques::Soumise, $campagne->statut);
        $this->assertSame(1, $campagne->version);
        $this->assertSame([['Petite boutique', 120, 150000], ['Grande boutique', 30, 500000]],
            $campagne->categories->map(fn ($c) => [$c->libelle, $c->nombre_boutiques, $c->caution])->all());
        $this->assertStringContainsString("Petite boutique : 120 boutique(s), caution 150\u{00A0}000\u{00A0}FCFA", $campagne->versions()->first()->parametres['categories']);

        // Le RÉGULATEUR ne confirme ni n'ouvre jamais.
        $this->en($this->regulateur)->post(route('admin.boutiques.confirmer', $campagne))->assertForbidden();
        $this->en($this->regulateur)->post(route('admin.boutiques.ouvrir', $campagne), ['confirmation' => 'CONFIRMER'])->assertForbidden();

        // Le VALIDATEUR voit les paramètres soumis.
        $this->en($this->validateur)->get(route('admin.boutiques.show', $campagne))->assertOk()
            ->assertSee('Petite boutique')->assertSee("500\u{00A0}000\u{00A0}FCFA")->assertSee('Soumise à confirmation');
    }

    public function test_une_campagne_sans_categorie_complete_est_refusee(): void
    {
        $this->en($this->regulateur)->post(route('admin.boutiques.store'), $this->formulaire(['categories' => []]))
            ->assertSessionHasErrors(['categories' => 'Ajoutez au moins une catégorie de boutiques.']);
        $this->en($this->regulateur)->post(route('admin.boutiques.store'), $this->formulaire(['categories' => [['libelle' => 'Kiosque', 'nombre_boutiques' => 10, 'caution' => '']]]))
            ->assertSessionHasErrors();

        $this->assertSame(0, CampagneBoutiques::count());
    }

    public function test_rejet_puis_confirmation_et_ouverture_par_le_validateur(): void
    {
        $campagne = $this->campagneSoumise();

        $this->en($this->validateur)->post(route('admin.boutiques.rejeter', $campagne), ['motif' => 'Caution trop élevée'])->assertSessionHas('succes');
        $this->assertSame(StatutCampagneBoutiques::Brouillon, $campagne->refresh()->statut);

        $this->en($this->regulateur)->put(route('admin.boutiques.update', $campagne), $this->formulaire([
            'categories' => [['libelle' => 'Petite boutique', 'nombre_boutiques' => 120, 'caution' => 100000]],
        ]))->assertSessionHasNoErrors();
        $this->assertSame([100000], $campagne->categories()->pluck('caution')->all());
        $this->en($this->regulateur)->post(route('admin.boutiques.soumettre', $campagne));

        $this->en($this->validateur)->post(route('admin.boutiques.confirmer', $campagne))->assertSessionHas('succes');
        $this->assertSame(StatutCampagneBoutiques::Confirmee, $campagne->refresh()->statut);

        // Ouverture : confirmation renforcée (code TOTP), puis clôture.
        $code = $this->activerTotp($this->validateur);
        $this->en($this->validateur)->post(route('admin.boutiques.ouvrir', $campagne), ['confirmation' => 'CONFIRMER', 'code_totp' => '000000'])->assertSessionHas('erreur');
        $this->en($this->validateur)->post(route('admin.boutiques.ouvrir', $campagne), ['confirmation' => 'CONFIRMER', 'code_totp' => $code])->assertSessionHas('succes');
        $this->assertSame(StatutCampagneBoutiques::Ouverte, $campagne->refresh()->statut);
        $this->assertTrue(CampagneBoutiques::ouverte()->is($campagne));

        $this->en($this->validateur)->post(route('admin.boutiques.cloturer', $campagne), ['confirmation' => 'CONFIRMER', 'code_totp' => $this->codeTotp($this->validateur, 1)])->assertSessionHas('succes');
        $this->assertSame(StatutCampagneBoutiques::Cloturee, $campagne->refresh()->statut);

        $this->assertDatabaseHas('audit_logs', ['action' => 'campagne_boutiques.ouverte', 'entite_id' => $campagne->id]);
    }

    public function test_les_profils_de_support_consultent_sans_rien_modifier(): void
    {
        $campagne = $this->campagneSoumise();
        $superAdmin = User::factory()->role(Role::SuperAdmin)->create();

        $this->en($superAdmin)->get(route('admin.boutiques.show', $campagne))->assertOk();
        $this->en($superAdmin)->post(route('admin.boutiques.confirmer', $campagne))->assertForbidden();
        $this->en($superAdmin)->get(route('admin.boutiques.create'))->assertForbidden();
        $this->en(User::factory()->role(Role::Admin)->create())->get(route('admin.boutiques'))->assertForbidden();
    }
}
