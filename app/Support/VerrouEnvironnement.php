<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Garde-fous de démarrage : les fonctions réservées au développement et à la recette (données de
 * démonstration, paiement simulé) ne peuvent jamais être actives en production.
 * L'application refuse alors de démarrer, avec un message explicite dans les journaux.
 */
final class VerrouEnvironnement
{
    /**
     * @throws RuntimeException en production si une fonction de recette est activée
     */
    public static function verifier(string $environnement, bool $demo, ?string $fournisseurPaiement = null): void
    {
        if ($environnement !== 'production') {
            return;
        }

        if ($demo) {
            self::refuser('RAAGA_DEMO=true est interdit avec APP_ENV=production '
                .'(des données fictives s’afficheraient sur le site). Passez RAAGA_DEMO à false.');
        }

        if ($fournisseurPaiement === 'simule') {
            self::refuser('RAAGA_PAIEMENT_FOURNISSEUR=simule est interdit avec APP_ENV=production '
                .'(aucune somme ne serait réellement encaissée). Configurez un fournisseur de paiement réel.');
        }
    }

    /**
     * Page « SMS de recette » (SMS simulés, codes de confirmation lisibles) : jamais en production,
     * et seulement avec le pilote SMS « log ». Activée par défaut hors production ; RAAGA_SMS_JOURNAL_RECETTE=false la coupe.
     */
    public static function journalSmsRecette(): bool
    {
        return ! app()->environment('production')
            && (config('raaga.sms.pilote') ?? 'log') === 'log'
            && (bool) (config('raaga.sms.journal_recette') ?? true);
    }

    /** Données de démonstration actives (jamais en production : voir verifier()). */
    public static function demonstration(): bool
    {
        return (bool) config('raaga.demo') && ! app()->environment('production');
    }

    /**
     * Refuse l'usage d'une fonction de recette en production, même si la configuration la demande.
     *
     * @throws RuntimeException
     */
    public static function exigerHorsProduction(string $fonction): void
    {
        if (app()->environment('production')) {
            self::refuser("Fonction de recette refusée en production : {$fonction}.");
        }
    }

    /** @throws RuntimeException */
    private static function refuser(string $message): never
    {
        $message = str_starts_with($message, 'Fonction') ? $message : 'Démarrage refusé : '.$message;

        Log::critical($message);

        throw new RuntimeException($message);
    }
}
