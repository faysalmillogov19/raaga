<?php

namespace App\Services\Boutiques;

use App\Models\User;
use App\Services\Audit\Journal;
use Carbon\CarbonInterface;

/**
 * Identifiants du commerçant propres à Raaga (02/11/2026) : identifiant fiscal Raaga (RG-F-000123) et numéro de registre
 * Raaga (RG-R-2026-000123), attribués automatiquement à la première attribution d'une boutique, puis conservés (autres
 * boutiques, boutique retirée). Ce ne sont ni l'IFU ni le RCCM officiels, déclarés à part par une personne morale.
 * Les deux reprennent le numéro du compte, comme le numéro d'actionnaire : uniques sans compteur. L'année du numéro de
 * registre est celle de la première attribution. Imprimés au verso de la carte de commerçant.
 */
class IdentifiantsCommercant
{
    public function __construct(private readonly Journal $journal) {}

    public static function identifiantFiscal(User $user): string
    {
        return sprintf('RG-F-%06d', $user->id);
    }

    public static function numeroRegistre(User $user, CarbonInterface $le): string
    {
        return sprintf('RG-R-%d-%06d', $le->year, $user->id);
    }

    /** Attribue les deux identifiants au commerçant qui n'en a pas encore, et le trace au journal d'audit. */
    public function attribuer(User $user, ?User $acteur, CarbonInterface $le): bool
    {
        if (filled($user->identifiant_fiscal_raaga)) {
            return false;
        }
        $user->forceFill([
            'identifiant_fiscal_raaga' => self::identifiantFiscal($user),
            'numero_registre_raaga' => self::numeroRegistre($user, $le),
            'identifiants_raaga_le' => now(),
        ])->save();
        $this->journal->ecrire($acteur, 'commercant.identifiants_attribues', 'User', $user->id, null, [
            'identifiant_fiscal_raaga' => $user->identifiant_fiscal_raaga,
            'numero_registre_raaga' => $user->numero_registre_raaga,
        ]);

        return true;
    }
}
