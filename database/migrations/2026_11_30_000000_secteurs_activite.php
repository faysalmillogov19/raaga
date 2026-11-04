<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secteurs d'activité des commerçants (03/11/2026), regroupés en familles (Alimentation, Services…), remplis par les
 * seeders de référentiel. Activité principale du commerçant, demandée à la candidature à une boutique ; « Autre
 * activité » avec une précision libre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('familles_secteurs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('libelle', 120);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('secteurs_activite', function (Blueprint $table) {
            $table->id();
            $table->foreignId('famille_secteur_id')->constrained('familles_secteurs')->restrictOnDelete();
            $table->string('code', 40)->unique();
            $table->string('libelle', 160);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('secteur_activite_id')->nullable()->after('forme_morale')->constrained('secteurs_activite')->nullOnDelete();
            $table->string('secteur_activite_autre', 120)->nullable()->after('secteur_activite_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('secteur_activite_id');
            $table->dropColumn('secteur_activite_autre');
        });
        Schema::dropIfExists('secteurs_activite');
        Schema::dropIfExists('familles_secteurs');
    }
};
