<?php

namespace App\Enums;

/**
 * Compte qui reçoit un paiement : jamais de mélange entre les fonds de location (caution, avance, loyers des boutiques)
 * et les fonds d'investissement (souscriptions d'actions). Chaque paiement enregistre le compte qui l'a reçu ; une
 * contrainte en base refuse toute incohérence. Coordonnées et clés des opérateurs : dans le .env (config/raaga.php).
 */
enum CompteDestination: string
{
    case Location = 'LOCATION';
    case Investissement = 'INVESTISSEMENT';

    public function label(): string
    {
        return __('public.paiement.comptes.'.$this->value);
    }
}
