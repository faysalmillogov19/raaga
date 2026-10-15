<?php

namespace App\Models;

use App\Enums\StatutSite;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Site concerné par un import : soumis par l'import (date) ou resté en brouillon (raison), puis résultat de la décision. */
#[Table(name: 'import_validation_sites')]
#[Fillable(['import_id', 'site_id', 'soumis_le', 'raison_brouillon', 'resultat'])]
class ImportValidationSite extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['soumis_le' => 'datetime'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ImportValidation::class, 'import_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Toujours soumis par cet import, sans décision : ni décidé séparément, ni soumis de nouveau depuis. */
    public function enAttente(): bool
    {
        return $this->resultat === null && $this->soumis_le !== null && $this->site->statut === StatutSite::Soumis
            && $this->site->soumis_le?->equalTo($this->soumis_le);
    }

    /** Validé ou rejeté hors de l'import (depuis « À valider › Sites ») : retiré de l'import. */
    public function traiteSeparement(): bool
    {
        return $this->resultat === null && $this->soumis_le !== null && ! $this->enAttente();
    }
}
