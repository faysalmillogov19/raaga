<?php

namespace App\Enums;

/** Unité du loyer d'un type de local : au m², par local (unité), ou redevance forfaitaire. */
enum UniteLoyer: string
{
    case M2 = 'm2';
    case Unite = 'unite';
    case Redevance = 'redevance';

    public function label(): string
    {
        return __('admin.types.unites.'.$this->value);
    }

    /** Le loyer se multiplie par la surface seulement pour une unité au m². */
    public function parSurface(): bool
    {
        return $this === self::M2;
    }
}
