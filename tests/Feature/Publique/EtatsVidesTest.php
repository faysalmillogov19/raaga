<?php

namespace Tests\Feature\Publique;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EtatsVidesTest extends TestCase
{
    use RefreshDatabase;

    public function test_souscrire_et_paiement_affichent_un_etat_vide_honnete(): void
    {
        // Sans tranche ouverte : « Souscription bientôt ouverte » (alerte SMS) au lieu du formulaire.
        $this->get('/souscrire')->assertRedirect(route('souscription.bientot'));

        $this->get('/paiement')->assertOk()->assertSee('Le paiement en ligne n’est pas encore ouvert');
    }

    public function test_l_espace_et_la_verification_du_citoyen(): void
    {
        $citoyen = User::factory()->role(Role::Citoyen)->create();

        $this->actingAs($citoyen)->get('/espace')->assertOk()
            ->assertSee('Aucune souscription pour le moment')
            ->assertSee('href="'.route('espace.profil').'"', false);

        $this->actingAs($citoyen)->get('/espace/verification')->assertOk()
            ->assertSee('Déposer mes pièces');
    }
}
