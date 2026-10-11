<?php

namespace App\Support;

/**
 * Coordonnées du compte séquestre recevant les virements (souscriptions et appels de fonds des actions A).
 * Point de lecture unique : aucune autre partie du code ne lit ces valeurs directement.
 *
 * TODO : remplacer par le paramètre confirmé (RÉGULATEUR → VALIDATEUR) quand le module de paramètres
 * de paiement existera. Lecture provisoire des variables RAAGA_SEQUESTRE_* (config/raaga.php).
 */
final class CompteSequestre
{
    /**
     * @return array{titulaire: ?string, banque: ?string, numero: ?string} une valeur absente vaut null
     */
    public static function actuel(): array
    {
        $compte = (array) config('raaga.compte_sequestre');

        return [
            'titulaire' => ($compte['titulaire'] ?? null) ?: null,
            'banque' => ($compte['banque'] ?? null) ?: null,
            'numero' => ($compte['numero'] ?? null) ?: null,
        ];
    }
}
