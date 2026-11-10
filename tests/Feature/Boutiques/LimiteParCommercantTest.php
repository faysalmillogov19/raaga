<?php

namespace Tests\Feature\Boutiques;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutKyc;
use App\Enums\StatutReservationBoutique;
use App\Models\Boutique;
use App\Models\CampagneBoutiques;
use App\Models\InscriptionBoutique;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Services\Boutiques\ReservationsBoutiques;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * Un commerçant n'est jamais attributaire de plus de deux boutiques, toutes campagnes et tous types confondus
 * (comité restreint du 03/11/2026) : ses réservations en cours comptent ; une troisième sélection est bloquée avant tout
 * paiement, liste d'attente comprise ; contrôle sur le compte, le numéro de pièce et l'IFU (deux comptes ne partagent
 * jamais un IFU) ; garde-fou à l'attribution.
 */
class LimiteParCommercantTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private CampagneBoutiques $campagne;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerActeurs();
        Notification::fake();
        $this->campagne = Boutiques::campagneOuverte();
        // Limite par type relevée : seule la limite par commerçant joue ici.
        $type = Boutiques::boutique('A', 1)->type_boutique_id;
        $this->campagne->forceFill(['limites' => [(string) $type => 10]])->save();
    }

    private function citoyen(array $attributs = []): User
    {
        static $n = 0;
        $n++;

        return User::factory()->role(Role::Citoyen)->create(['telephone' => '+2267400'.sprintf('%04d', $n), 'statut_kyc' => StatutKyc::Valide,
            'piece_expire_le' => now()->addYears(5)->toDateString(), ...$attributs]);
    }

    private function reservation(User $user, Boutique $boutique, StatutReservationBoutique $statut, ?string $numero = null): ReservationBoutique
    {
        $boutique->forceFill(['statut' => $statut === StatutReservationBoutique::Attribuee ? StatutBoutique::Attribuee : StatutBoutique::Reservee])->save();

        return ReservationBoutique::create(['reference' => 'BTQ-'.strtoupper(substr(md5($boutique->code), 0, 8)), 'user_id' => $user->id,
            'campagne_boutiques_id' => $this->campagne->id, 'boutique_id' => $boutique->id, 'caution' => 540_000, 'avance' => 135_000, 'mois_avance' => 3,
            'statut' => $statut, 'numero_piece' => $numero, 'expire_le' => now()->addDay(), 'avance_payee_le' => now(), 'reference_avance' => 'PAY-'.$boutique->code]);
    }

    public function test_deux_boutiques_puis_troisieme_selection_bloquee_avant_paiement(): void
    {
        $citoyen = $this->citoyen();
        $this->reservation($citoyen, Boutiques::boutique('A', 1), StatutReservationBoutique::Attribuee);
        $this->assertSame(1, ReservationsBoutiques::restantCommercant($citoyen));

        // Deuxième boutique : retenue tout de suite.
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A', 2))->assertSessionHasNoErrors();
        $this->assertSame(2, ReservationBoutique::query()->where('user_id', $citoyen->id)->count());
        $this->assertSame(0, ReservationsBoutiques::restantCommercant($citoyen));

        // Troisième, pourtant libre : message, aucun formulaire ; envoi refusé, rien n'est enregistré (ni réservation ni
        // liste d'attente), donc aucun paiement possible.
        $troisieme = Boutiques::boutique('A', 3);
        $this->actingAs($citoyen)->get(route('boutiques.boutique', $troisieme->code))->assertOk()
            ->assertSee('data-limite-commercant', false)->assertSee('Vous ne pouvez pas être attributaire de plus de deux boutiques.')
            ->assertDontSee('data-formulaire-reservation', false)
            ->assertSee('Un commerçant n’est jamais attributaire de plus de 2 boutiques, toutes campagnes confondues.', false);
        Boutiques::reserver($this, $citoyen, $troisieme)->assertSessionHasErrors(['boutique' => 'Vous ne pouvez pas être attributaire de plus de deux boutiques.']);
        $this->assertSame(2, ReservationBoutique::query()->where('user_id', $citoyen->id)->count());
        $this->assertSame(StatutBoutique::Disponible, $troisieme->fresh()->statut);
        $this->assertFalse(InscriptionBoutique::query()->where('user_id', $citoyen->id)->where('boutique_id', $troisieme->id)->exists());
        // Boutique occupée : la liste d'attente est bloquée de même.
        $occupee = Boutiques::boutique('A', 4);
        $occupee->forceFill(['statut' => StatutBoutique::Reservee])->save();
        Boutiques::reserver($this, $citoyen, $occupee)->assertSessionHasErrors('boutique');
        $this->assertSame(0, InscriptionBoutique::query()->where('user_id', $citoyen->id)->where('boutique_id', $occupee->id)->count());
    }

    public function test_les_reservations_en_cours_comptent_et_les_annulees_non(): void
    {
        $citoyen = $this->citoyen();
        $this->reservation($citoyen, Boutiques::boutique('A', 1), StatutReservationBoutique::Reservee);
        $this->reservation($citoyen, Boutiques::boutique('A', 2), StatutReservationBoutique::PreReservee);
        $this->assertSame(0, ReservationsBoutiques::restantCommercant($citoyen));

        ReservationBoutique::query()->where('user_id', $citoyen->id)->first()->forceFill(['statut' => StatutReservationBoutique::Annulee])->save();
        $this->assertSame(1, ReservationsBoutiques::restantCommercant($citoyen));
    }

    public function test_controle_sur_le_numero_de_piece_et_l_ifu_d_un_autre_compte(): void
    {
        // Deux boutiques au nom d'un premier compte, avec cette pièce.
        $premier = $this->citoyen();
        $this->reservation($premier, Boutiques::boutique('A', 1), StatutReservationBoutique::Attribuee, 'B1234567');
        $this->reservation($premier, Boutiques::boutique('A', 2), StatutReservationBoutique::Attribuee, 'B1234567');

        // Un autre compte avec la même pièce : déjà à la limite.
        $this->assertSame(0, ReservationsBoutiques::restantCommercant($this->citoyen(['numero_piece' => 'B1234567'])));
        // Personne morale : le numéro retenu est son IFU.
        $morale = $this->citoyen(['ifu' => '00012345A']);
        $this->reservation($morale, Boutiques::boutique('A', 3), StatutReservationBoutique::Attribuee, '00012345A');
        $this->reservation($morale, Boutiques::boutique('A', 4), StatutReservationBoutique::Reservee, '00012345A');
        $this->assertSame(0, ReservationsBoutiques::restantCommercant($morale));
        // Un compte sans lien : ses deux boutiques restent possibles.
        $this->assertSame(2, ReservationsBoutiques::restantCommercant($this->citoyen()));
    }

    public function test_deux_comptes_ne_partagent_jamais_un_ifu_et_la_limite_suit_l_ifu(): void
    {
        // Premier compte : deux boutiques, IFU déjà vérifié.
        $premier = $this->citoyen(['ifu' => '00004742U']);
        Boutiques::reserver($this, $premier, Boutiques::boutique('A', 1))->assertSessionHasNoErrors();
        Boutiques::reserver($this, $premier, Boutiques::boutique('A', 2), 'B7654321')->assertSessionHasNoErrors();
        $this->assertSame(0, ReservationsBoutiques::restantCommercant($premier));

        // Second compte de la même personne : son IFU est refusé (déjà utilisé), rien n'est enregistré.
        Storage::fake('local');
        $second = $this->citoyen();
        // IFU déposé dans « Vérifier mon identité » (10/11/2026 : plus à la réservation).
        Boutiques::demandeIdentite($second, 'B5555555');
        $this->actingAs($second)->post(route('espace.verification.ifu'), [
            'numero_ifu' => '0000 4742 u', 'attestation_ifu' => UploadedFile::fake()->create('ifu.pdf', 50, 'application/pdf'),
        ])->assertSessionHasErrors(['numero_ifu' => 'Ce numéro IFU est déjà utilisé par un autre compte.']);
        $this->assertSame(0, ReservationBoutique::query()->where('user_id', $second->id)->count());
        $this->assertNull($second->fresh()->ifu);
        // Même en base, deux comptes ne portent jamais le même IFU (transaction imbriquée : la suite du test continue).
        $this->assertThrows(fn () => DB::transaction(fn () => $second->forceFill(['ifu' => '00004742U'])->save()), UniqueConstraintViolationException::class);

        // Personne morale qui présente le même IFU comme pièce : déjà à la limite.
        $this->assertSame(0, ReservationsBoutiques::restantCommercant($this->citoyen(), '00004742U'));
    }

    public function test_garde_fou_a_l_attribution(): void
    {
        $citoyen = $this->citoyen();
        $this->reservation($citoyen, Boutiques::boutique('A', 1), StatutReservationBoutique::Attribuee);
        $this->reservation($citoyen, Boutiques::boutique('A', 2), StatutReservationBoutique::Attribuee);
        // Réservation ancienne, antérieure à la règle : son attribution est refusée.
        $troisieme = $this->reservation($citoyen, Boutiques::boutique('A', 3), StatutReservationBoutique::Reservee);

        try {
            app(ReservationsBoutiques::class)->attribuer($troisieme, now()->toDateString(), $this->validateur);
            $this->fail('Attribution acceptée au-delà de deux boutiques.');
        } catch (ValidationException $e) {
            $this->assertSame('Attribution impossible : ce commerçant est déjà attributaire de 2 boutiques, le maximum autorisé.', $e->errors()['contrat_signe_le'][0]);
        }
        $this->assertSame(StatutReservationBoutique::Reservee, $troisieme->fresh()->statut);
    }
}
