<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoleTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_table_contient_un_role_par_valeur_de_l_enum(): void
    {
        $codes = Role::query()->pluck('code')->map(fn (RoleEnum $code) => $code->value)->sort()->values()->all();
        $attendus = collect(RoleEnum::cases())->map(fn (RoleEnum $role) => $role->value)->sort()->values()->all();

        $this->assertSame($attendus, $codes);
    }

    public function test_synchroniser_est_idempotent(): void
    {
        Role::synchroniser();
        Role::synchroniser();

        $this->assertSame(count(RoleEnum::cases()), Role::query()->count());
    }

    public function test_l_utilisateur_est_rattache_a_son_role(): void
    {
        $user = User::factory()->role(RoleEnum::Regulateur)->create();

        $this->assertSame(RoleEnum::Regulateur, $user->fresh()->codeRole());
        $this->assertSame('Régulateur', $user->fresh()->role->label());
        $this->assertTrue($user->fresh()->hasRole(RoleEnum::Regulateur));
    }

    public function test_un_role_inexistant_est_refuse_par_la_base(): void
    {
        $this->expectException(QueryException::class);

        User::factory()->create(['role_id' => 999_999]);
    }

    public function test_un_role_attribue_ne_peut_pas_etre_supprime(): void
    {
        $user = User::factory()->role(RoleEnum::Validateur)->create();

        $this->expectException(QueryException::class);

        DB::table('roles')->where('id', $user->role_id)->delete();
    }
}
