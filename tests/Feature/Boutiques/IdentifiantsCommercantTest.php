<?php

namespace Tests\Feature\Boutiques;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutKyc;
use App\Enums\StatutReservationBoutique;
use App\Enums\TypeCarte;
use App\Models\AuditLog;
use App\Models\Boutique;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Services\Boutiques\ReservationsBoutiques;
use App\Services\Cartes\Cartes;
use App\Services\Cartes\RenduCarte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\Support\ReglagesCarteComplets;
use Tests\TestCase;

/**
 * Identifiant fiscal et numéro de registre Raaga du commerçant : attribués à la première attribution d'une boutique,
 * conservés ensuite, imprimés au verso de la carte de commerçant, affichés au guichet, au dossier d'identité et dans
 * le profil ; recherche au guichet ; rattrapage des commerçants déjà attributaires.
 */
class IdentifiantsCommercantTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase, ReglagesCarteComplets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerActeurs();
        Notification::fake();
        Boutiques::campagneOuverte();
    }

    private function citoyen(): User
    {
        return User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'nom' => 'OUEDRAOGO', 'prenoms' => 'Awa',
            'piece_expire_le' => now()->addYears(10)->toDateString(), 'identite_validee' => ['nom' => 'OUEDRAOGO', 'prenoms' => 'Awa']]);
    }

    /** Réservation réservée, avance payée (prête à attribuer). */
    private function reservee(User $citoyen, Boutique $boutique): ReservationBoutique
    {
        $campagne = Boutiques::campagneOuverte();
        $boutique->forceFill(['statut' => StatutBoutique::Reservee])->save();

        return ReservationBoutique::create(['reference' => 'BTQ-'.strtoupper(substr(md5((string) $boutique->id), 0, 8)), 'user_id' => $citoyen->id,
            'campagne_boutiques_id' => $campagne->id, 'boutique_id' => $boutique->id, 'caution' => 540_000, 'avance' => 135_000, 'mois_avance' => 3,
            'statut' => StatutReservationBoutique::Reservee, 'expire_le' => now()->subDay(), 'avance_payee_le' => now(), 'reference_avance' => 'PAY-AV']);
    }

    private function attribuer(User $citoyen, Boutique $boutique): void
    {
        app(ReservationsBoutiques::class)->attribuer($this->reservee($citoyen, $boutique), now()->toDateString(), $this->validateur);
    }

    public function test_attribues_a_la_premiere_attribution_puis_conserves(): void
    {
        $citoyen = $this->citoyen();
        $this->reservee($citoyen, Boutiques::boutique('A', 1));
        // Réservée, pas encore attribuée : aucun identifiant.
        $this->assertNull($citoyen->fresh()->identifiant_fiscal_raaga);

        app(ReservationsBoutiques::class)->attribuer(ReservationBoutique::query()->sole(), now()->toDateString(), $this->validateur);
        $citoyen->refresh();
        $fiscal = sprintf('RG-F-%06d', $citoyen->id);
        $registre = sprintf('RG-R-%d-%06d', now()->year, $citoyen->id);
        $this->assertSame($fiscal, $citoyen->identifiant_fiscal_raaga);
        $this->assertSame($registre, $citoyen->numero_registre_raaga);
        $this->assertNotNull($citoyen->identifiants_raaga_le);
        $journal = AuditLog::query()->where('action', 'commercant.identifiants_attribues')->sole();
        $this->assertSame($this->validateur->id, $journal->acteur_id);

        // Carte de commerçant émise avec les deux identifiants.
        $carte = Cartes::courante($citoyen, TypeCarte::Commercant);
        $this->assertSame($fiscal, $carte->contenu['identifiant_fiscal']);
        $this->assertSame($registre, $carte->contenu['numero_registre']);

        // Deuxième boutique, l'année suivante : mêmes identifiants, rien de nouveau au journal.
        $this->travel(1)->years();
        $this->attribuer($citoyen, Boutiques::boutique('A', 2));
        $this->assertSame($registre, $citoyen->fresh()->numero_registre_raaga);
        $this->assertSame(1, AuditLog::query()->where('action', 'commercant.identifiants_attribues')->count());
    }

    public function test_message_de_l_attribution_au_back_office(): void
    {
        $citoyen = $this->citoyen();
        $r = $this->reservee($citoyen, Boutiques::boutique('A', 1));

        $this->en($this->validateur)->post(route('admin.boutiques.attribuer', [$r->campagne_boutiques_id, $r->id]), ['contrat_signe_le' => now()->toDateString()])
            ->assertSessionHas('succes', __('admin.boutiques.messages.attribuee', ['fiscal' => sprintf('RG-F-%06d', $citoyen->id),
                'registre' => sprintf('RG-R-%d-%06d', now()->year, $citoyen->id)]));
    }

    public function test_imprimes_au_verso_de_la_carte_de_commercant_seulement(): void
    {
        $this->completerReglagesCarte();
        $citoyen = $this->citoyen();
        $this->attribuer($citoyen, Boutiques::boutique('A', 1));
        $d = app(RenduCarte::class)->donnees(Cartes::courante($citoyen, TypeCarte::Commercant));
        $html = view('cartes._faces', ['d' => $d])->render();
        $this->assertStringContainsString('data-champ="identifiant_fiscal">'.$citoyen->fresh()->identifiant_fiscal_raaga.'<', $html);
        $this->assertStringContainsString('data-champ="numero_registre">'.$citoyen->fresh()->numero_registre_raaga.'<', $html);
        $this->assertStringContainsString(__('carte.identifiant_fiscal'), $html);

        // Spécimen de la carte d'actionnaire : pas d'identifiants de commerçant.
        $specimen = app(RenduCarte::class)->specimen([], [], TypeCarte::Actionnaire);
        $this->assertStringNotContainsString('data-champ="identifiant_fiscal"', view('cartes._faces', ['d' => $specimen])->render());
    }

    public function test_guichet_recherche_et_dossier_du_commercant(): void
    {
        $citoyen = $this->citoyen();
        $this->attribuer($citoyen, Boutiques::boutique('A', 1));
        $citoyen->refresh();
        $agent = User::factory()->role(Role::AgentGuichet)->create();

        foreach ([$citoyen->identifiant_fiscal_raaga, strtolower($citoyen->numero_registre_raaga)] as $saisie) {
            $this->en($agent)->post(route('admin.guichet.rechercher'), ['carte' => $saisie])
                ->assertRedirect(route('admin.guichet.titulaire', $citoyen));
        }
        $this->en($agent)->from(route('admin.guichet'))->post(route('admin.guichet.rechercher'), ['carte' => 'RG-F-999999'])
            ->assertSessionHasErrors(['carte' => __('admin.guichet.erreurs.commercant_introuvable')]);

        $this->en($agent)->get(route('admin.guichet.titulaire', $citoyen))->assertOk()
            ->assertSee($citoyen->identifiant_fiscal_raaga)->assertSee($citoyen->numero_registre_raaga)->assertSee('Identifiant fiscal Raaga');
        $carte = Cartes::courante($citoyen, TypeCarte::Commercant);
        $this->en($agent)->get(route('admin.guichet.carte', $carte->jeton))->assertOk()
            ->assertSee('data-identifiant-fiscal', false)->assertSee($citoyen->numero_registre_raaga);
    }

    public function test_dossier_d_identite_et_profil_du_commercant(): void
    {
        $citoyen = $this->citoyen();
        $sansBoutique = $this->citoyen();
        $this->attribuer($citoyen, Boutiques::boutique('A', 1));
        $citoyen->refresh();

        $admin = User::factory()->role(Role::Admin)->create();
        $this->en($admin)->get(route('admin.kyc.dossier', $citoyen))->assertOk()
            ->assertSee($citoyen->identifiant_fiscal_raaga)->assertSee($citoyen->numero_registre_raaga);

        $this->app['auth']->forgetGuards();
        $this->actingAs($citoyen)->get('/espace?onglet=profil')->assertOk()
            ->assertSee('Identifiant fiscal Raaga')->assertSee($citoyen->identifiant_fiscal_raaga)
            ->assertSee('Numéro de registre Raaga')->assertSee($citoyen->numero_registre_raaga);
        $this->app['auth']->forgetGuards();
        $this->actingAs($sansBoutique)->get('/espace?onglet=profil')->assertOk()->assertDontSee('Identifiant fiscal Raaga');
    }

    public function test_rattrapage_des_commercants_deja_attributaires(): void
    {
        $citoyen = $this->citoyen();
        $this->attribuer($citoyen, Boutiques::boutique('A', 1));
        // Attribué avant la mise en place des identifiants (2025) : carte émise sans eux.
        ReservationBoutique::query()->update(['attribuee_le' => '2025-06-01 10:00:00']);
        $citoyen->forceFill(['identifiant_fiscal_raaga' => null, 'numero_registre_raaga' => null, 'identifiants_raaga_le' => null])->save();
        $carte = Cartes::courante($citoyen, TypeCarte::Commercant);
        $carte->forceFill(['contenu' => array_diff_key($carte->contenu, ['identifiant_fiscal' => 1, 'numero_registre' => 1])])->save();

        $this->artisan('raaga:identifiants-commercants')->expectsOutput('Commerçants traités : 1.')->assertSuccessful();
        $citoyen->refresh();
        $this->assertSame(sprintf('RG-R-2025-%06d', $citoyen->id), $citoyen->numero_registre_raaga);
        $mise = Cartes::courante($citoyen, TypeCarte::Commercant);
        $this->assertSame($carte->id, $mise->id);
        $this->assertSame($carte->version + 1, $mise->version);
        $this->assertSame($citoyen->identifiant_fiscal_raaga, $mise->contenu['identifiant_fiscal']);

        // Deuxième passage : rien à faire.
        $this->artisan('raaga:identifiants-commercants')->expectsOutput('Commerçants traités : 0.')->assertSuccessful();
    }
}
