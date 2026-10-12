<?php

namespace App\Support;

use App\Contracts\Payable;
use App\Enums\StatutPaiement;
use App\Enums\StatutSouscription;
use App\Models\Paiement;
use App\Models\Souscription;

/** Reçu de souscription : numéro unique dérivé du paiement confirmé (aucun numéro stocké en double). */
final class Recu
{
    /** Statuts pour lesquels le reçu reste disponible (après paiement, y compris une fois les actions allouées). */
    public const STATUTS = [StatutSouscription::Payee, StatutSouscription::Allouee];

    /** « REC-2026-000042 » : année de confirmation + identifiant du paiement (unique). */
    public static function numero(Paiement $paiement): string
    {
        return 'REC-'.$paiement->date_confirmation->format('Y').'-'.str_pad((string) $paiement->id, 6, '0', STR_PAD_LEFT);
    }

    /** Paiement confirmé d'une souscription ou d'une caution de candidature. */
    public static function paiement(Payable $objet): ?Paiement
    {
        return $objet->paiements()->where('statut', StatutPaiement::Confirme)->latest('id')->first();
    }

    public static function disponible(Souscription $souscription): bool
    {
        return in_array($souscription->statut, self::STATUTS, true);
    }
}
