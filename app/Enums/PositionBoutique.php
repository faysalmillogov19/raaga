<?php

namespace App\Enums;

/** Position d'une boutique sur le site (facultative). */
enum PositionBoutique: string
{
    case Angle = 'ANGLE';
    case Facade = 'FACADE';
    case Allee = 'ALLEE';

    public function label(): string
    {
        return __('admin.sites.positions.'.$this->value);
    }
}
