<?php

namespace App\Enums;

/** Profil déclaré dans une manifestation d'intérêt RAAGA Invest. */
enum ProfilInvestisseur: string
{
    case Resident = 'RESIDENT';
    case Diaspora = 'DIASPORA';
    case Entreprise = 'ENTREPRISE';
    case Institution = 'INSTITUTION';

    public function label(): string
    {
        return __('public.invest.profils.'.$this->value);
    }
}
