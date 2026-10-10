<?php

namespace App\Enums;

/**
 * Classes d'actions de Raaga SA : A (investisseurs institutionnels, registre A) et B (citoyens, registre B).
 */
enum ClasseActions: string
{
    case A = 'A';
    case B = 'B';

    public function label(): string
    {
        return match ($this) {
            self::A => 'Actions A (investisseurs institutionnels)',
            self::B => 'Actions B (souscription populaire)',
        };
    }
}
