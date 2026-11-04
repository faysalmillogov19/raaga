<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Découpage administratif du Burkina Faso (03/11/2026) : 17 régions, 47 provinces, 351 communes, en vigueur depuis le
 * 01/08/2025. Code officiel (« BF62 », « BF6201 », « BF620101 »), nom, ancien code et ancien nom avant la réforme.
 * Remplies par RegionsSeeder, ProvincesSeeder et CommunesSeeder (database/seeders/data/decoupage-administratif.json).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('nom', 100);
            $table->string('ancien_code', 10)->nullable();
            $table->string('ancien_nom', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
            $table->string('code', 10)->unique();
            $table->string('nom', 100);
            $table->string('ancien_code', 10)->nullable();
            $table->string('ancien_nom', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('communes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->constrained('provinces')->restrictOnDelete();
            $table->string('code', 12)->unique();
            $table->string('nom', 100);
            $table->string('ancien_code', 12)->nullable();
            $table->string('ancien_nom', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communes');
        Schema::dropIfExists('provinces');
        Schema::dropIfExists('regions');
    }
};
