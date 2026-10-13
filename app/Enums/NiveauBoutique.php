<?php

namespace App\Enums;

/** Niveau d'une boutique (code court utilisé dans le code boutique). */
enum NiveauBoutique: string
{
    case SousSol = 'SS';
    case RezDeChaussee = 'RC';
    case R1 = 'R1';
    case R2 = 'R2';
    case R3 = 'R3';

    public function label(): string
    {
        return __('admin.sites.niveaux.'.$this->value);
    }
}
