<?php

namespace App\Support;

use Closure;

/**
 * Mémorisation limitée à une requête de lecture (GET, HEAD) en cours de traitement : un calcul coûteux (file
 * « À valider », bloc « À traiter ») n'est fait qu'une fois pour le menu, les badges et la page. Hors d'une telle
 * requête (décision POST, commande, test qui appelle le code directement), rien n'est mémorisé : la valeur est
 * recalculée à chaque appel et reste juste. Activée à la résolution de la route, vidée une fois la réponse rendue.
 */
final class MemoRequete
{
    /** @var array<string, mixed>|null null : mémorisation inactive */
    private static ?array $valeurs = null;

    public static function demarrer(): void
    {
        self::$valeurs = [];
    }

    public static function arreter(): void
    {
        self::$valeurs = null;
    }

    public static function retenir(string $cle, Closure $calcul): mixed
    {
        if (self::$valeurs === null) {
            return $calcul();
        }
        if (! array_key_exists($cle, self::$valeurs)) {
            self::$valeurs[$cle] = $calcul();
        }

        return self::$valeurs[$cle];
    }
}
