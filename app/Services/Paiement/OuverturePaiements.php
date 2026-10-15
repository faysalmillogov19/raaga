<?php

namespace App\Services\Paiement;

use App\Contracts\PaymentProvider;
use App\Enums\CompteDestination;
use App\Support\CompteSequestre;
use Throwable;

/**
 * Paiements ouverts ou fermés pour un compte de destination. En production : il faut un opérateur de paiement réel
 * (jamais le simulateur) et les coordonnées du compte concerné (RAAGA_SEQUESTRE_* pour l'investissement,
 * RAAGA_COMPTE_LOCATION_* pour la location). Hors production (recette), le simulateur suffit et les coordonnées
 * peuvent manquer. Paiements fermés : aucune campagne ne se propose ni ne s'ouvre, aucun citoyen ne paie.
 */
class OuverturePaiements
{
    /** Opérateur réel configuré et chargé (jamais le simulateur). */
    public static function operateurReel(): bool
    {
        if (in_array(config('raaga.paiement.fournisseur'), [null, '', 'simule'], true)) {
            return false;
        }
        try {
            return ! app(PaymentProvider::class)->estSimule();
        } catch (Throwable) {
            return false;
        }
    }

    /** Coordonnées du compte renseignées (titulaire, banque, numéro). */
    public static function compteRenseigne(CompteDestination $compte): bool
    {
        return ! in_array(null, CompteSequestre::pour($compte), true);
    }

    /** Raison de la fermeture des paiements vers ce compte, ou null s'ils sont ouverts. */
    public static function raisonFermeture(CompteDestination $compte): ?string
    {
        if (! app()->environment('production')) {
            return null;
        }

        return match (true) {
            ! self::operateurReel() => __('public.paiement.fermes.operateur'),
            ! self::compteRenseigne($compte) => __('public.paiement.fermes.compte', ['compte' => mb_strtolower($compte->label())]),
            default => null,
        };
    }

    public static function ouverts(CompteDestination $compte): bool
    {
        return self::raisonFermeture($compte) === null;
    }
}
