<?php

namespace App\Services\Sms;

use App\Contracts\SmsProvider;
use App\Support\Telephone;
use Illuminate\Support\Facades\Log;

/**
 * Développement : aucun SMS n'est envoyé. Le message s'affiche dans la console du serveur
 * (php artisan serve) et dans le journal, avec un numéro masqué.
 */
class LogSmsProvider implements SmsProvider
{
    public function envoyer(string $telephone, string $message, ?string $expediteur = null): void
    {
        $ligne = '[SMS de développement] '.($expediteur ? "{$expediteur} → " : '').Telephone::masquer($telephone).' : '.$message;

        error_log($ligne);
        Log::info($ligne);
    }
}
