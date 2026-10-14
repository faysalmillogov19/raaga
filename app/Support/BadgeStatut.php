<?php

namespace App\Support;

use BackedEnum;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Badge de statut du back-office : le statut est toujours écrit en toutes lettres, la couleur aide seulement.
 * Correspondance libellé → variante (neutre, info, ok, avertissement, erreur), à partir du libellé affiché.
 */
final class BadgeStatut
{
    /** Libellés normalisés (minuscules, sans accents) par variante ; le masculin et le féminin sont couverts. */
    private const VARIANTES = [
        'neutre' => ['brouillon', 'desactive', 'desactivee', 'en construction', 'annule', 'annulee', 'non renseigne', 'inactif', 'retiree', 'retire'],
        'info' => ['soumis', 'soumise', 'attribue', 'attribuee', 'apercu', 'par defaut', 'en campagne', 'a valider', 'en attente', 'soumis a confirmation'],
        'ok' => ['confirme', 'confirmee', 'disponible', 'livre', 'livree', 'occupe', 'occupee', 'valide', 'validee', 'actif', 'active', 'ouverte', 'complete'],
        'avertissement' => ['en revision', 'reserve', 'reservee', 'en travaux', 'a completer', 'verrouille', 'invitation en attente'],
        'erreur' => ['rejete', 'rejetee', 'refuse', 'refusee', 'ferme', 'fermee', 'suspendu', 'suspendue', 'echec', 'ecart'],
    ];

    public static function texte(BackedEnum|string|null $statut): string
    {
        return $statut instanceof BackedEnum ? (method_exists($statut, 'label') ? $statut->label() : (string) $statut->value) : (string) $statut;
    }

    public static function variante(BackedEnum|string|null $statut): string
    {
        $cle = Str::of(self::texte($statut))->ascii()->lower()->replaceMatches('/[^a-z ]+/', ' ')->squish()->toString();
        foreach (self::VARIANTES as $variante => $libelles) {
            if (in_array($cle, $libelles, true)) {
                return $variante;
            }
        }

        return 'neutre';
    }

    /** HTML du badge, pour les colonnes de tableaux construites en PHP. */
    public static function html(BackedEnum|string|null $statut, ?string $variante = null): HtmlString
    {
        $variante ??= self::variante($statut);

        return new HtmlString('<span class="adm-badge adm-badge-'.e($variante).'">'.e(self::texte($statut)).'</span>');
    }
}
