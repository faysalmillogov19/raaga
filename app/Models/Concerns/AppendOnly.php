<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Modèle en ajout seul : toute modification ou suppression via Eloquent lève une exception.
 * Doublé en base par les triggers posés avec App\Support\AppendOnlyTable.
 */
trait AppendOnly
{
    protected static function bootAppendOnly(): void
    {
        static::updating(function ($model) {
            throw new LogicException(class_basename($model).' est en ajout seul : modification interdite.');
        });

        static::deleting(function ($model) {
            throw new LogicException(class_basename($model).' est en ajout seul : suppression interdite.');
        });
    }
}
