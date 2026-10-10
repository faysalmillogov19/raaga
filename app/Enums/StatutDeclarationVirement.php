<?php

namespace App\Enums;

enum StatutDeclarationVirement: string
{
    case Declaree = 'DECLAREE';
    case Rapprochee = 'RAPPROCHEE';
    case Rejetee = 'REJETEE';

    public function label(): string
    {
        return match ($this) {
            self::Declaree => 'À rapprocher',
            self::Rapprochee => 'Rapprochée',
            self::Rejetee => 'Non retrouvée sur le relevé',
        };
    }
}
