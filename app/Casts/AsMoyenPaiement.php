<?php

namespace App\Casts;

use App\Models\MoyenPaiement;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Colonne qui enregistre le code d'un moyen de paiement (paiements.canal, distributions.canal_defaut) : lue comme le
 * moyen du référentiel ; un code qui n'y figure plus reste lisible (libellé = code). Écrite à partir d'un moyen ou d'un code.
 */
class AsMoyenPaiement implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?MoyenPaiement
    {
        if ($value === null) {
            return null;
        }

        return MoyenPaiement::parCode((string) $value) ?? new MoyenPaiement(['code' => (string) $value, 'libelle' => (string) $value]);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value instanceof MoyenPaiement ? $value->code : ($value === null ? null : (string) $value);
    }
}
