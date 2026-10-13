<?php

namespace App\Enums;

/** Phase d'avancement d'un site (libellés identiques à l'onglet « Listes » du fichier d'import). */
enum PhaseSite: string
{
    case Etudes = 'ETUDES';
    case Aps = 'APS';
    case Apd = 'APD';
    case Travaux = 'TRAVAUX';
    case Reception = 'RECEPTION';
    case Exploitation = 'EXPLOITATION';

    public function label(): string
    {
        return __('admin.sites.phases.'.$this->value);
    }

    /** Phase à partir de son code ou de son libellé (sans tenir compte de la casse). */
    public static function depuis(string $valeur): ?self
    {
        $valeur = mb_strtolower(trim($valeur));
        foreach (self::cases() as $phase) {
            if ($valeur === mb_strtolower($phase->value) || $valeur === mb_strtolower($phase->label())) {
                return $phase;
            }
        }

        return null;
    }
}
