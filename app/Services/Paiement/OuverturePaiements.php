<?php

namespace App\Services\Paiement;

use App\Contracts\PaymentProvider;
use App\Enums\CompteDestination;
use App\Models\MoyenPaiement;
use App\Support\CompteSequestre;
use Throwable;

/**
 * Paiements ouverts ou fermés, moyen par moyen, pour un compte de destination. En production :
 *  - paiements manuels (virement, versement, espèces) : ouverts dès que les coordonnées du compte concerné sont
 *    renseignées (RAAGA_SEQUESTRE_* pour l'investissement, RAAGA_COMPTE_LOCATION_* pour la location), même sans opérateur ;
 *  - paiements par opérateur (mobile money, carte, Faso Arzeka) : il faut en plus un opérateur réel (jamais le simulateur).
 * Hors production (recette), tout est ouvert. Une campagne ne se propose ni ne s'ouvre que si au moins un de ses moyens
 * de paiement de la caution est réellement ouvert.
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

    /** Raison de la fermeture d'un moyen précis vers ce compte, ou null s'il est ouvert. */
    public static function raisonFermetureCanal(MoyenPaiement $canal, CompteDestination $compte): ?string
    {
        if (! app()->environment('production')) {
            return null;
        }

        return match (true) {
            ! self::compteRenseigne($compte) => __('public.paiement.fermes.compte', ['compte' => mb_strtolower($compte->label())]),
            ! $canal->estManuel() && ! self::operateurReel() => __('public.paiement.fermes.operateur'),
            default => null,
        };
    }

    public static function canalOuvert(MoyenPaiement $canal, CompteDestination $compte): bool
    {
        return self::raisonFermetureCanal($canal, $compte) === null;
    }

    /** Paiements par opérateur ouverts vers ce compte (opérateur réel et coordonnées, en production). */
    public static function operateurOuvert(CompteDestination $compte): bool
    {
        return ! app()->environment('production') || (self::compteRenseigne($compte) && self::operateurReel());
    }

    /**
     * Raison de la fermeture quand AUCUN des moyens n'est ouvert (celle du premier moyen), ou null si au moins un l'est.
     *
     * @param  iterable<MoyenPaiement>  $canaux
     */
    public static function raisonFermeture(CompteDestination $compte, iterable $canaux): ?string
    {
        if (! app()->environment('production')) {
            return null;
        }
        $raisons = collect($canaux)->map(fn (MoyenPaiement $c) => self::raisonFermetureCanal($c, $compte));

        return match (true) {
            $raisons->contains(null) => null,
            $raisons->isEmpty() => __('public.paiement.fermes.aucun_moyen'),
            default => $raisons->first(),
        };
    }
}
