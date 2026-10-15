<?php

namespace App\Support;

use App\Enums\CompteDestination;

/**
 * Coordonnées des comptes qui reçoivent les virements et versements, un par destination : fonds d'investissement
 * (souscriptions d'actions, appels de fonds des actions A) et fonds de location (caution, avance, loyers). Point de
 * lecture unique : aucune autre partie du code ne lit ces valeurs directement. Lues dans le .env (config/raaga.php).
 */
final class CompteSequestre
{
    /**
     * @return array{titulaire: ?string, banque: ?string, numero: ?string} une valeur absente vaut null
     */
    public static function pour(CompteDestination $compte): array
    {
        $valeurs = (array) config($compte === CompteDestination::Location ? 'raaga.compte_location' : 'raaga.compte_sequestre');

        return [
            'titulaire' => ($valeurs['titulaire'] ?? null) ?: null,
            'banque' => ($valeurs['banque'] ?? null) ?: null,
            'numero' => ($valeurs['numero'] ?? null) ?: null,
        ];
    }

    /** Compte des fonds d'investissement (appels de fonds des actions A, souscriptions). */
    public static function actuel(): array
    {
        return self::pour(CompteDestination::Investissement);
    }
}
