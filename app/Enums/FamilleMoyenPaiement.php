<?php

namespace App\Enums;

/**
 * Famille d'un moyen de paiement : elle décide de ce que le payeur saisit et du type d'instructions affichées.
 * Plateforme publique (Faso Arzeka) : paiement en ligne chez l'opérateur ; banque : virement ou versement sur le
 * compte séquestre ; mobile money : numéro de téléphone demandé ; guichet : espèces versées à un guichet Raaga.
 */
enum FamilleMoyenPaiement: string
{
    case PlateformePublique = 'PLATEFORME_PUBLIQUE';
    case Banque = 'BANQUE';
    case MobileMoney = 'MOBILE_MONEY';
    case Guichet = 'GUICHET';

    public function label(): string
    {
        return __('admin.moyens_paiement.familles.'.$this->value);
    }
}
