<?php

namespace App\Enums;

enum StatutCandidatureBoutique: string
{
    case Reservee = 'RESERVEE';
    case Payee = 'PAYEE';
    case Retenue = 'RETENUE';
    case NonRetenue = 'NON_RETENUE';
    case Expiree = 'EXPIREE';

    public function label(): string
    {
        return match ($this) {
            self::Reservee => 'Caution à payer',
            self::Payee => 'Caution payée, en attente du tirage',
            self::Retenue => 'Retenue au tirage au sort',
            self::NonRetenue => 'Non retenue',
            self::Expiree => 'Expirée (caution non payée)',
        };
    }

    /** Statuts qui occupent la place du citoyen dans la campagne (une seule candidature active). */
    public static function actifs(): array
    {
        return [self::Reservee, self::Payee, self::Retenue, self::NonRetenue];
    }
}
