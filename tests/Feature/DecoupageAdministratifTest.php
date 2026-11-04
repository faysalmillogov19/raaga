<?php

namespace Tests\Feature;

use App\Models\Commune;
use App\Models\Province;
use App\Models\Region;
use Database\Seeders\CommunesSeeder;
use Database\Seeders\ReferentielsSeeder;
use Database\Seeders\RegionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Découpage administratif du Burkina Faso (03/11/2026) : 17 régions, 47 provinces, 351 communes, rattachées entre elles,
 * créées par les seeders de référentiel (aussi en production) ; idempotents, jamais de modification d'une ligne existante.
 */
class DecoupageAdministratifTest extends TestCase
{
    use RefreshDatabase;

    public function test_regions_provinces_et_communes_creees_par_les_referentiels(): void
    {
        $this->seed(ReferentielsSeeder::class);

        $this->assertSame(17, Region::count());
        $this->assertSame(47, Province::count());
        $this->assertSame(351, Commune::count());

        // Noms en français avec leurs accents, chef-lieu, rattachements ; aucune trace de l'ancien découpage.
        $region = Region::query()->where('code', 'BF62')->sole();
        $this->assertSame(['Bankui', 'Dédougou'], [$region->nom, $region->chef_lieu]);
        $this->assertSame(['Kuilsé', 'Kaya'], [Region::query()->where('code', 'BF49')->value('nom'), Region::query()->where('code', 'BF49')->value('chef_lieu')]);
        $this->assertSame(0, Region::query()->whereNull('chef_lieu')->count());
        $this->assertFalse(Schema::hasColumn('regions', 'ancien_nom'));
        $this->assertFalse(Schema::hasColumn('communes', 'ancien_nom'));
        $this->assertSame('Nakambé', Region::query()->where('code', 'BF48')->value('nom'));
        $province = Province::query()->where('code', 'BF6201')->sole();
        $this->assertSame(['Balé', 'BF62'], [$province->nom, $province->region->code]);
        $this->assertTrue($region->provinces->contains($province));
        $this->assertSame(0, Province::query()->doesntHave('communes')->count());
        $this->assertSame(0, Region::query()->doesntHave('provinces')->count());
        $this->assertSame(351, Region::query()->withCount('communes')->get()->sum('communes_count'));
    }

    public function test_idempotent_sans_modifier_une_ligne_existante(): void
    {
        $this->seed(CommunesSeeder::class);
        Region::query()->where('code', 'BF13')->update(['nom' => 'Kadiogo (modifié)']);

        $this->seed([RegionsSeeder::class, CommunesSeeder::class]);

        $this->assertSame([17, 47, 351], [Region::count(), Province::count(), Commune::count()]);
        $this->assertSame('Kadiogo (modifié)', Region::query()->where('code', 'BF13')->value('nom'));
    }
}
