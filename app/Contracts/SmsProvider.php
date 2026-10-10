<?php

namespace App\Contracts;

/**
 * Envoi de SMS (à appeler via App\Services\Sms\EnvoiSms, qui fixe l'expéditeur et les variables). Jalon 1 : aucun envoi réel (LogSmsProvider). Les opérateurs viendront au jalon 2.
 */
interface SmsProvider
{
    /**
     * @param  string  $telephone  Numéro complet, ex. « +22670123456 ».
     * @param  string|null  $expediteur  Nom d'expéditeur (11 caractères alphanumériques au plus).
     */
    public function envoyer(string $telephone, string $message, ?string $expediteur = null): void;
}
