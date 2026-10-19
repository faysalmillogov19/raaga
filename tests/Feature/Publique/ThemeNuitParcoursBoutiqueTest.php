<?php

namespace Tests\Feature\Publique;

use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Models\ReservationBoutique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * Thème « nuit », lot 2 : détail d'une boutique et réservation, caution (choix du moyen, paiement manuel, compte à
 * rebours), suivi de la réservation (frise du parcours), page d'attente du paiement. Mêmes états qu'en thème « clair ».
 */
class ThemeNuitParcoursBoutiqueTest extends TestCase
{
    use RefreshDatabase;

    private function citoyen(string $telephone = '+22670123456'): User
    {
        return User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => $telephone]);
    }

    public function test_detail_et_formulaire_en_theme_nuit_puis_clair(): void
    {
        Boutiques::campagneOuverte();
        $citoyen = $this->citoyen();
        $url = route('boutiques.boutique', Boutiques::boutique('A')->code);

        $this->actingAs($citoyen)->get($url)->assertOk()
            ->assertSee('nuit-page', false)->assertSee('nuit-bandeau', false)
            ->assertSee('class="nuit-fiche" aria-labelledby="recap-titre" data-recapitulatif', false)
            ->assertSee('data-formulaire-reservation', false)->assertSee('nuit-choix flex', false)
            ->assertSee(format_fcfa(540_000));

        config(['raaga.theme_public' => 'clair']);
        $this->actingAs($citoyen)->get($url)->assertOk()->assertDontSee('nuit-page', false)
            ->assertSee('class="carte p-5" aria-labelledby="recap-titre" data-recapitulatif', false);
    }

    public function test_formulaire_en_erreur(): void
    {
        Boutiques::campagneOuverte();
        $citoyen = $this->citoyen();
        $code = Boutiques::boutique('A')->code;

        $this->actingAs($citoyen)->from(route('boutiques.boutique', $code))->post(route('boutiques.reserver', $code), ['type_piece' => 'CNIB'])
            ->assertSessionHasErrors(['numero_piece', 'piece']);
        $this->actingAs($citoyen)->followingRedirects()->from(route('boutiques.boutique', $code))->post(route('boutiques.reserver', $code), ['type_piece' => 'CNIB'])
            ->assertOk()->assertSee('nuit-page', false)->assertSee('aria-invalid="true"', false)->assertSee('text-rouge', false);
    }

    public function test_limite_atteinte(): void
    {
        Boutiques::campagneOuverte();
        $citoyen = $this->citoyen();
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A'))->assertSessionHasNoErrors();

        $this->actingAs($citoyen)->get(route('boutiques.boutique', Boutiques::boutique('A', 2)->code))->assertOk()
            ->assertSee('nuit-page', false)->assertSee('data-limite-atteinte', false)->assertDontSee('data-formulaire-reservation', false);
    }

    public function test_caution_compte_a_rebours_choix_et_paiement_manuel(): void
    {
        Boutiques::campagneOuverte();
        $citoyen = $this->citoyen();
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A'))->assertSessionHasNoErrors();
        $reference = ReservationBoutique::query()->sole()->reference;

        $this->actingAs($citoyen)->get(route('boutiques.reservation.show', $reference))->assertOk()
            ->assertSee('nuit-page', false)->assertSee('class="nuit-fiche" aria-labelledby="reservation-titre"', false)
            ->assertSee('data-compte-rebours', false)->assertSee('nuit-rebours-cases', false)->assertSee('Temps restant pour payer la caution')
            ->assertSee('nuit-choix flex', false)->assertSee('Virement bancaire');

        $this->actingAs($citoyen)->post(route('boutiques.reservation.payer', $reference), ['canal' => 'VIREMENT'])->assertSessionHasNoErrors();
        $this->actingAs($citoyen)->get(route('boutiques.reservation.show', $reference))->assertOk()
            ->assertSee('data-paiement-manuel', false)->assertSee('data-reference-versement', false)->assertSee('name="preuve"', false);

        // Thème « clair » : compte à rebours historique (texte), aucune case.
        config(['raaga.theme_public' => 'clair']);
        $this->actingAs($citoyen)->post(route('boutiques.reservation.changer', $reference));
        $this->actingAs($citoyen)->get(route('boutiques.reservation.show', $reference))->assertOk()
            ->assertSee('class="compte-rebours mt-2"', false)->assertDontSee('nuit-rebours-cases', false);
    }

    public function test_suivi_avec_frise_du_parcours(): void
    {
        Boutiques::campagneOuverte();
        $citoyen = $this->citoyen();
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A'))->assertSessionHasNoErrors();
        $reference = ReservationBoutique::query()->sole()->reference;

        $this->actingAs($citoyen)->get(route('boutiques.suivi', $reference))->assertOk()
            ->assertSee('nuit-page', false)->assertSee('data-parcours', false)
            ->assertSeeInOrder(['Je choisis ma boutique', 'Terminé', 'Je confirme', 'Terminé', 'Je paie la caution', 'En cours', 'Avance et contrat', 'À venir']);

        config(['raaga.theme_public' => 'clair']);
        $this->actingAs($citoyen)->get(route('boutiques.suivi', $reference))->assertOk()->assertDontSee('data-parcours', false);
    }

    public function test_page_d_attente_du_paiement(): void
    {
        config(['raaga.phase.presentation_actions' => true]);

        $this->get(route('paiement'))->assertOk()->assertSee('nuit-page', false)->assertSee('nuit-bandeau', false);
    }
}
