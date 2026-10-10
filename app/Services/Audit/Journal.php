<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Point d'entrée unique pour écrire dans le journal d'audit (ajout seul).
 * Aucune donnée personnelle sensible dans les valeurs : identifiants, rôles, statuts, motifs.
 */
class Journal
{
    /**
     * @param  array<string, mixed>|null  $ancienne
     * @param  array<string, mixed>|null  $nouvelle
     */
    public function ecrire(?User $acteur, string $action, string $entiteType, int|string|null $entiteId, ?array $ancienne = null, ?array $nouvelle = null): AuditLog
    {
        return AuditLog::create([
            'acteur_id' => $acteur?->id,
            'action' => $action,
            'entite_type' => $entiteType,
            'entite_id' => $entiteId === null ? null : (string) $entiteId,
            'ancienne_valeur' => $ancienne,
            'nouvelle_valeur' => $nouvelle,
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
