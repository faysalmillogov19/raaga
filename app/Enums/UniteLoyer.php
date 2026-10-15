<?php

namespace App\Enums;

/**
 * Mode de calcul du loyer d'une unité du référentiel « Unités » (valeur = code de l'unité, clé étrangère des lignes de
 * grille tarifaire vers unites.code) : au m² (loyer × surface), à l'unité ou redevance (montant par local).
 */
enum UniteLoyer: string
{
    case M2 = 'M2';
    case Unite = 'UNITE';
    case Redevance = 'REDEVANCE';

    public function label(): string
    {
        return __('admin.types.unites.'.mb_strtolower($this->value));
    }

    /** Le loyer se multiplie par la surface seulement pour une unité au m². */
    public function parSurface(): bool
    {
        return $this === self::M2;
    }

    /** Valeur lue dans une version ou un instantané antérieurs (« m2 » en minuscules) comme dans la base (« M2 »). */
    public static function depuis(?string $valeur): ?self
    {
        return self::tryFrom(mb_strtoupper(trim((string) $valeur)));
    }
}
