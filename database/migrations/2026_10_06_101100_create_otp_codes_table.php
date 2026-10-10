<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Codes OTP de connexion par téléphone (stockés hachés).
     */
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('telephone', 20)->index();
            $table->string('code_hash');
            $table->dateTime('expire_le');
            $table->unsignedTinyInteger('tentatives')->default(0);
            $table->timestamp('utilise_le')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
