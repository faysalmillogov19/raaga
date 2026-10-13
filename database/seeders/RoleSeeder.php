<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Table des rôles alignée sur l'enum App\Enums\Role : une ligne par rôle, libellé et description à jour.
 * Sans effet de bord : se relance autant de fois que voulu. Exécuté automatiquement après chaque
 * « php artisan migrate » (AppServiceProvider), et par DatabaseSeeder.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        if (Schema::hasTable('roles')) {
            Role::synchroniser();
        }
    }
}
