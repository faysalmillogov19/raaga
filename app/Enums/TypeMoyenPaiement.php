<?php

namespace App\Enums;

/**
 * Nature d'un moyen de paiement : elle décide de ce que le payeur saisit et des instructions affichées.
 * Mobile money : numéro de téléphone demandé ; passerelle : paiement en ligne sur la plateforme de l'opérateur
 * (Faso Arzeka) ; virement : coordonnées du compte séquestre ; espèces : versement au guichet.
 */
enum TypeMoyenPaiement: string
{
    case MobileMoney = 'MOBILE_MONEY';
    case Passerelle = 'PASSERELLE';
    case Virement = 'VIREMENT';
    case Especes = 'ESPECES';

    public function label(): string
    {
        return __('admin.zones.types_paiement.'.$this->value);
    }
}
