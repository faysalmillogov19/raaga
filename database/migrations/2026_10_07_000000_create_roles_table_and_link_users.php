<?php

use App\Enums\Role as RoleEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les rôles deviennent une table de référence : users.role (texte) est remplacé
     * par users.role_id, clé étrangère vers roles.id.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            // Code stable utilisé par le code (valeur de App\Enums\Role).
            $table->string('code', 40)->unique();
            $table->string('libelle');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        $maintenant = now();

        DB::table('roles')->insert(array_map(fn (RoleEnum $role) => [
            'code' => $role->value,
            'libelle' => $role->label(),
            'description' => $role->description(),
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ], RoleEnum::cases()));

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('email')->constrained('roles')->restrictOnDelete();
        });

        // Reprise des rôles déjà attribués ; les valeurs inconnues retombent sur « visiteur ».
        DB::statement('UPDATE users SET role_id = (SELECT roles.id FROM roles WHERE roles.code = users.role)');
        DB::table('users')->whereNull('role_id')->update([
            'role_id' => DB::table('roles')->where('code', RoleEnum::Visiteur->value)->value('id'),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default(RoleEnum::Visiteur->value)->after('email')->index();
        });

        DB::statement('UPDATE users SET role = (SELECT roles.code FROM roles WHERE roles.id = users.role_id)');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });

        Schema::dropIfExists('roles');
    }
};
