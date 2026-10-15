<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne du registre des actions A (investisseurs institutionnels) · ajout seul, chaînée :
 * chaque ligne contient l'empreinte de la précédente. Jamais mélangée au registre des actions B.
 */
#[Table(name: 'lignes_registre_a', timestamps: false)]
#[Fillable([
    'numero_certificat', 'institution_id', 'convention_id', 'appel_fonds_id', 'nb_actions', 'numero_debut',
    'numero_fin', 'date_inscription', 'attestation_chemin', 'attestation_empreinte', 'inscrit_par',
    'empreinte_precedente', 'empreinte', 'created_at',
])]
#[Hidden(['attestation_chemin'])]
class LigneRegistreA extends Model
{
    use AppendOnly;

    protected function casts(): array
    {
        return [
            'nb_actions' => 'integer',
            'numero_debut' => 'integer',
            'numero_fin' => 'integer',
            'date_inscription' => 'date',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Empreinte d'une ligne : ses données et l'empreinte de la ligne précédente.
     *
     * @param  array<string, mixed>  $donnees
     */
    public static function calculerEmpreinte(array $donnees, ?string $precedente): string
    {
        return hash('sha256', implode('|', [
            $donnees['numero_certificat'], $donnees['institution_id'], $donnees['convention_id'], $donnees['appel_fonds_id'] ?? '',
            $donnees['nb_actions'], $donnees['numero_debut'], $donnees['numero_fin'], $donnees['date_inscription'],
            $donnees['attestation_empreinte'], $precedente ?? 'origine',
        ]));
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * @return BelongsTo<Convention, $this>
     */
    public function convention(): BelongsTo
    {
        return $this->belongsTo(Convention::class);
    }
}
