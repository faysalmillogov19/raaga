<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recherches insensibles aux accents (App\Support\Recherche) : extension PostgreSQL unaccent (« de confiance » depuis
 * PostgreSQL 13 : le propriétaire de la base peut la créer). Sans effet sur MySQL, dont la collation ignore les accents.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        }
    }

    public function down(): void
    {
        // L'extension reste : d'autres objets de la base peuvent s'en servir.
    }
};
