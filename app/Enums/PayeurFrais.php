<?php

namespace App\Enums;

enum PayeurFrais: string
{
    case Raaga = 'RAAGA';
    case Souscripteur = 'SOUSCRIPTEUR';

    public function label(): string
    {
        return match ($this) {
            self::Raaga => 'Raaga',
            self::Souscripteur => 'Souscripteur',
        };
    }
}
