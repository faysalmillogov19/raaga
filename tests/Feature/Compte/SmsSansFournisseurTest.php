<?php

namespace Tests\Feature\Compte;

use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Enums\StatutPaiement;
use App\Models\Paiement;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Services\Paiement\ServicePaiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * SMS du reçu d'avance sans fournisseur SMS en production : l'envoi est ignoré et tracé dans le journal, sans jamais
 * bloquer ni annuler la confirmation du paiement, et sans erreur pour le citoyen.
 */
class SmsSansFournisseurTest extends TestCase
{
    use RefreshDatabase;

    private ReservationBoutique $reservation;

    private Paiement $avance;

    protected function setUp(): void
    {
        parent::setUp();

        // Recette : caution payée, demande de paiement de l'avance en attente (avant de passer en production).
        config(['raaga.sms.pilote' => 'log', 'raaga.sms.fichier_recette' => storage_path('framework/testing/sms-sans-fournisseur.jsonl')]);
        @unlink(config('raaga.sms.fichier_recette'));
        Boutiques::campagneOuverte();
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+22670123456']);
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A'))->assertSessionHasNoErrors();
        $this->reservation = ReservationBoutique::sole();
        $this->actingAs($citoyen)->post(route('boutiques.reservation.payer', $this->reservation->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56']);
        app(ServicePaiement::class)->confirmer(Paiement::sole()->reference, true);
        $this->actingAs($citoyen)->post(route('boutiques.avance.payer', $this->reservation->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56']);
        $this->avance = Paiement::query()->where('nature', Paiement::AVANCE)->sole();

        $this->app['env'] = 'production';
    }

    private function confirmerAvance(): void
    {
        $paiement = app(ServicePaiement::class)->confirmer($this->avance->reference, true);

        $this->assertSame(StatutPaiement::Confirme, $paiement->statut);
        $this->assertNotNull($this->reservation->fresh()->avance_payee_le);
        $this->assertSame($this->avance->reference, $this->reservation->fresh()->reference_avance);
    }

    public function test_aucun_fournisseur_configure_envoi_ignore_et_trace(): void
    {
        Log::spy();

        $this->confirmerAvance();

        // Tracé : SMS non envoyé, numéro masqué ; jamais écrit dans le journal de recette en production.
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'SMS non envoyé : aucun fournisseur SMS configuré')
            && str_contains($m, 'reçue') && ! str_contains($m, '70123456'))->once();
        $this->assertFileDoesNotExist(config('raaga.sms.fichier_recette'));
    }

    public function test_pilote_inconnu_le_paiement_est_confirme_quand_meme(): void
    {
        config(['raaga.sms.pilote' => 'operateur_non_branche']);
        Log::spy();

        $this->confirmerAvance();

        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c = []) => str_contains($m, 'SMS de confirmation de paiement non envoyé')
            && ($c['paiement'] ?? null) === $this->avance->id)->once();
    }
}
