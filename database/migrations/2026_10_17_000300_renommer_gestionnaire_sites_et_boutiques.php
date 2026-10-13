<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Profil renommé « Gestionnaire des sites et boutiques » (code gestionnaire_sites inchangé).
        Role::synchroniser();
    }

    public function down(): void
    {
        Role::query()->where('code', 'gestionnaire_sites')->update(['libelle' => 'Gestionnaire des sites']);
    }
};
