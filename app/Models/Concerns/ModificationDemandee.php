<?php

namespace App\Models\Concerns;

use App\Models\User;

/**
 * Modification d'un élément « par défaut » (libellé, description, rang, désactivation…) : jamais appliquée directement.
 * Elle est enregistrée en attente (modification_demandee), soumise au VALIDATEUR, puis appliquée à la validation ou
 * abandonnée au rejet. L'élément reste utilisable tel quel pendant ce temps.
 */
trait ModificationDemandee
{
    public function aModificationEnAttente(): bool
    {
        return $this->modification_demandee !== null;
    }

    /** @param  array<string, mixed>  $valeurs  champs modifiés => nouvelle valeur */
    public function demanderModification(array $valeurs, User $auteur): void
    {
        $this->forceFill(['modification_demandee' => $valeurs, 'demande_par' => $auteur->id, 'motif_rejet' => null])->save();
    }

    /** Validation : la modification demandée est appliquée. @return array<string, mixed> valeurs appliquées */
    public function appliquerModification(): array
    {
        $valeurs = (array) $this->modification_demandee;
        $this->forceFill([...$valeurs, 'modification_demandee' => null, 'motif_rejet' => null])->save();

        return $valeurs;
    }

    /** Rejet : la modification demandée est abandonnée, l'élément reste tel quel. */
    public function abandonnerModification(string $motif): void
    {
        $this->forceFill(['modification_demandee' => null, 'motif_rejet' => $motif])->save();
    }
}
