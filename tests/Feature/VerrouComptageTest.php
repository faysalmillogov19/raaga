<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Lignes verrouillées puis comptées (règles « au moins 2 SUPER_ADMIN », « dernier signataire ») : PostgreSQL refuse
 * FOR UPDATE avec un agrégat ; compterVerrouille() verrouille par un SELECT des identifiants, puis compte.
 */
class VerrouComptageTest extends TestCase
{
    use RefreshDatabase;

    public function test_compter_verrouille_verrouille_sans_agregat(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        User::factory()->count(3)->role(Role::SuperAdmin)->create();
        User::factory()->role(Role::SuperAdmin)->create(['actif' => false]);

        DB::enableQueryLog();
        $n = DB::transaction(fn () => User::query()->where('actif', true)
            ->whereHas('role', fn ($q) => $q->where('code', Role::SuperAdmin->value))->compterVerrouille());
        $sql = collect(DB::getQueryLog())->pluck('query')->first(fn ($q) => str_contains($q, 'for update'));

        $this->assertSame(3, $n);
        $this->assertNotNull($sql);
        $this->assertStringNotContainsString('count(', $sql);
        $this->assertStringStartsWith('select "users"."id" from "users"', $sql);
    }

    public function test_aucun_agregat_verrouille_dans_le_code(): void
    {
        $fautifs = [];
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $fichier) {
            // lockForUpdate() / sharedLock() suivi (même à la ligne) de count, sum, max, min ou avg : refusé par PostgreSQL.
            if (preg_match('/(lockForUpdate|sharedLock)\(\)\s*->\s*(count|sum|max|min|avg)\(/', $fichier->getContents())) {
                $fautifs[] = $fichier->getRelativePathname();
            }
        }

        $this->assertSame([], $fautifs);
    }
}
