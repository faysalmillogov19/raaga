<?php

namespace Tests\Feature\Boutiques;

use App\Enums\FaceDocument;
use App\Enums\Role;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutKyc;
use App\Enums\TypeDocumentKyc;
use App\Models\InscriptionBoutique;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Boutiques\ReservationsBoutiques;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * Réservation d’une boutique avec une pièce d’identité déjà transmise sur le compte, validée ou en cours de vérification (05/11/2026) : la pièce n’est pas
 * redemandée, son type et son numéro sont repris ; sexe et lieu de naissance seulement s'ils manquent au profil.
 * Identité ou pièce rejetée, à mettre à jour, ou pièce expirée : la pièce est demandée, comme avant.
 */
class PieceDuCompteReservationTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerActeurs();
        Boutiques::campagneOuverte();
    }

    /** Citoyen dont la CNIB (recto, numéro B7654321) est validée. */
    private function citoyenVerifie(array $attributs = []): User
    {
        $user = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+2267000'.sprintf('%04d', User::query()->withoutGlobalScopes()->max('id') + 1), 'email' => null,
            'sexe' => 'M', 'lieu_naissance' => 'Bobo-Dioulasso', 'piece_expire_le' => today()->addYears(2), ...$attributs]);
        KycDocument::create(['user_id' => $user->id, 'type' => TypeDocumentKyc::Cnib, 'face' => FaceDocument::Recto, 'chemin_fichier' => 'kyc/'.$user->id.'/cnib.jpg',
            'nom_original' => 'cnib.jpg', 'mime' => 'image/jpeg', 'taille' => 1000, 'empreinte_sha256' => str_repeat('0', 64),
            'statut' => StatutDocumentKyc::Valide, 'numero' => 'B7654321']);
        Boutiques::ifuVerifie($user);

        return $user;
    }

    /** Candidature sans pièce, sans sexe ni lieu de naissance. */
    private function donneesSansPiece(): array
    {
        return array_diff_key(Boutiques::donneesCommercant(), array_flip(['sexe', 'lieu_naissance']));
    }

    public function test_piece_verifiee_reprise_sans_rien_redeposer(): void
    {
        Storage::fake('local');
        $user = $this->citoyenVerifie();
        $boutique = Boutiques::boutique('A', 1);

        $this->actingAs($user)->get(route('boutiques.boutique', $boutique->code))->assertOk()
            ->assertSee('data-piece-compte', false)->assertSee(__('public.boutiques.attente.piece_verifiee', ['type' => 'CNIB', 'numero' => 'B7654321']), false)
            ->assertDontSee('name="piece"', false)->assertDontSee('name="numero_piece"', false)->assertDontSee('data-sexe', false);

        $this->actingAs($user)->post(route('boutiques.reserver', $boutique->code), $this->donneesSansPiece())->assertSessionHasNoErrors();
        $inscription = InscriptionBoutique::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame([TypeDocumentKyc::Cnib->value, 'B7654321'], [$inscription->type_piece, $inscription->numero_piece]);
        // Aucune nouvelle pièce déposée ; sexe et lieu de naissance du profil gardés.
        $this->assertSame(1, KycDocument::query()->where('user_id', $user->id)->where('type', TypeDocumentKyc::Cnib)->count());
        $this->assertSame(['M', 'Bobo-Dioulasso'], [$user->fresh()->sexe, $user->fresh()->lieu_naissance]);
    }

    public function test_piece_verifiee_sexe_et_lieu_demandes_s_ils_manquent(): void
    {
        $user = $this->citoyenVerifie(['sexe' => null, 'lieu_naissance' => null]);
        $boutique = Boutiques::boutique('A', 1);

        $this->actingAs($user)->get(route('boutiques.boutique', $boutique->code))->assertOk()
            ->assertSee('data-piece-compte', false)->assertDontSee('name="piece"', false)->assertSee('data-sexe', false);
        $this->actingAs($user)->post(route('boutiques.reserver', $boutique->code), $this->donneesSansPiece())->assertSessionHasErrors(['sexe', 'lieu_naissance']);
        $this->actingAs($user)->post(route('boutiques.reserver', $boutique->code), [...$this->donneesSansPiece(), 'sexe' => 'F', 'lieu_naissance' => 'Koudougou'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['F', 'Koudougou'], [$user->fresh()->sexe, $user->fresh()->lieu_naissance]);
    }

    public function test_activite_personne_a_prevenir_et_ifu_deja_connus_sont_rappeles(): void
    {
        Storage::fake('local');
        $user = $this->citoyenVerifie();
        $a1 = Boutiques::boutique('A', 1);
        $a2 = Boutiques::boutique('A', 2);

        // Première réservation : rien n'est encore connu, les champs sont demandés.
        $this->actingAs($user)->get(route('boutiques.boutique', $a1->code))->assertOk()->assertDontSee('data-infos-connues', false);
        $this->actingAs($user)->post(route('boutiques.reserver', $a1->code), $this->donneesSansPiece())->assertSessionHasNoErrors();

        // Deuxième : activité et personne à prévenir rappelées, modifiables ; IFU rappelé.
        $page = $this->actingAs($user->fresh())->get(route('boutiques.boutique', $a2->code))->assertOk()
            ->assertSee('data-infos-connues', false)->assertSee(__('public.boutiques.reservation.infos_modifier'))
            ->assertSee('OUEDRAOGO Awa')->assertSee('70 00 00 01');
        $this->assertStringContainsString('x-data="{ modifier: false }"', $page->getContent());

        // Envoi des valeurs reprises (champs préremplis) : la réservation passe sans rien ressaisir.
        $this->actingAs($user->fresh())->post(route('boutiques.reserver', $a2->code), $this->donneesSansPiece())->assertSessionHasNoErrors();
    }

    public function test_ifu_connu_rappele_seule_l_attestation_est_demandee(): void
    {
        $user = $this->citoyenVerifie();
        KycDocument::query()->where('user_id', $user->id)->where('type', TypeDocumentKyc::Ifu)->delete();

        $this->actingAs($user)->get(route('boutiques.boutique', Boutiques::boutique('A', 1)->code))->assertOk()
            ->assertSee('data-ifu-connu', false)->assertSee($user->ifu)->assertSee('name="attestation_ifu"', false)
            ->assertSee('type="hidden" name="ifu" value="'.$user->ifu.'"', false);
    }

    public function test_piece_transmise_en_cours_de_verification_reprise(): void
    {
        Storage::fake('local');
        $user = $this->citoyenVerifie(['statut_kyc' => StatutKyc::EnAttente]);
        KycDocument::query()->where('user_id', $user->id)->where('type', TypeDocumentKyc::Cnib)->update(['statut' => StatutDocumentKyc::EnAttente]);
        $boutique = Boutiques::boutique('A', 1);

        $this->actingAs($user)->get(route('boutiques.boutique', $boutique->code))->assertOk()
            ->assertSee(__('public.boutiques.attente.piece_en_verification', ['type' => 'CNIB', 'numero' => 'B7654321']), false)
            ->assertDontSee('name="piece"', false)->assertDontSee('name="numero_piece"', false);
        $this->actingAs($user)->post(route('boutiques.reserver', $boutique->code), $this->donneesSansPiece())->assertSessionHasNoErrors();
        $this->assertSame('B7654321', InscriptionBoutique::query()->where('user_id', $user->id)->value('numero_piece'));

        // Pièce rejetée : redemandée.
        KycDocument::query()->where('user_id', $user->id)->where('type', TypeDocumentKyc::Cnib)->update(['statut' => StatutDocumentKyc::Rejete]);
        $this->assertNull(ReservationsBoutiques::pieceDuCompte($user->fresh()));
    }

    public function test_identite_rejetee_a_mettre_a_jour_ou_piece_expiree_la_piece_est_demandee(): void
    {
        $boutique = Boutiques::boutique('A', 1);
        foreach ([['statut_kyc' => StatutKyc::Rejete], ['statut_kyc' => StatutKyc::AMettreAJour], ['piece_expire_le' => today()->subDay()]] as $cas) {
            $user = $this->citoyenVerifie($cas);
            $this->actingAs($user)->get(route('boutiques.boutique', $boutique->code))->assertOk()
                ->assertDontSee('data-piece-compte', false)->assertSee('name="piece"', false);
            $this->actingAs($user)->post(route('boutiques.reserver', $boutique->code), $this->donneesSansPiece())
                ->assertSessionHasErrors(['type_piece', 'numero_piece', 'piece']);
        }
    }
}
