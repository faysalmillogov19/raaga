<?php

use App\Services\Plateforme\ParametresPlateforme;
use App\Support\Plateforme\Catalogue;
use Carbon\Carbon;

/*
 * Formats d'affichage français. Les montants sont des entiers FCFA, sans décimale.
 * Séparateur de milliers : espace insécable (le montant ne se coupe jamais en fin de ligne).
 */

if (! function_exists('asset_versionne')) {
    /**
     * Adresse d'un fichier statique de public/ suivie de « ?v= » et de sa date de modification : le navigateur
     * recharge le fichier dès qu'il change (pas d'étape de compilation, pas de manifeste).
     */
    function asset_versionne(string $chemin): string
    {
        $fichier = public_path($chemin);

        return asset($chemin).(is_file($fichier) ? '?v='.filemtime($fichier) : '');
    }
}

if (! function_exists('format_nombre')) {
    function format_nombre(int|float $n, int $decimales = 0): string
    {
        return number_format($n, $decimales, ',', "\u{00A0}");
    }
}

if (! function_exists('format_fcfa')) {
    /**
     * 250000 → « 250 000 FCFA »
     */
    function format_fcfa(int $montant): string
    {
        return format_nombre($montant)."\u{00A0}FCFA";
    }
}

if (! function_exists('pourcentage_lisible')) {
    /**
     * Part arrondie à l'unité ; une part non nulle mais inférieure à 1 % s'affiche « < 1 % ».
     */
    function pourcentage_lisible(int $partie, int $total): string
    {
        if ($total <= 0 || $partie <= 0) {
            return "0\u{00A0}%";
        }

        $pct = (int) round($partie * 100 / $total);

        return $pct === 0 ? "<\u{00A0}1\u{00A0}%" : "{$pct}\u{00A0}%";
    }
}

if (! function_exists('format_md')) {
    /**
     * Montant en milliards, sans devise : 6450000000 → « 6,45 Md ».
     * Sans nombre de décimales imposé, les zéros inutiles sont retirés : 2500000000 → « 2,5 Md ».
     */
    function format_md(int $montant, ?int $decimales = null): string
    {
        $valeur = format_nombre($montant / 1_000_000_000, $decimales ?? 3);

        if ($decimales === null && str_contains($valeur, ',')) {
            $valeur = rtrim(rtrim($valeur, '0'), ',');
        }

        return $valeur."\u{00A0}Md";
    }
}

if (! function_exists('format_md_fcfa')) {
    /**
     * 16245000000 → « 16,245 Md FCFA » (milliards).
     */
    function format_md_fcfa(int $montant, ?int $decimales = 2): string
    {
        return format_md($montant, $decimales)."\u{00A0}FCFA";
    }
}

if (! function_exists('format_pourcentage')) {
    /**
     * Pourcentage à une décimale, arrondi vers le bas (on n'affiche jamais 100,0 % avant d'y être) :
     * 0.42822 → « 42,8 % ».
     */
    function format_pourcentage(int $partie, int $total): string
    {
        $pct = $total > 0 ? floor($partie * 1000 / $total) / 10 : 0;

        return format_nombre($pct, 1)."\u{00A0}%";
    }
}

if (! function_exists('plateforme')) {
    /**
     * Paramètres de la plateforme (identité, contacts, mentions…) : plateforme('contacts.numeroVert').
     * Sans argument : le service. Une valeur vide vaut null et n'est jamais affichée.
     */
    function plateforme(?string $chemin = null, mixed $defaut = null): mixed
    {
        $service = app(ParametresPlateforme::class);

        return $chemin === null ? $service : $service->get($chemin, $defaut);
    }
}

if (! function_exists('getParametresPlateforme')) {
    /**
     * Toutes les sections des paramètres de la plateforme (mises en cache).
     *
     * @return array<string, array<string, mixed>>
     */
    function getParametresPlateforme(): array
    {
        return app(ParametresPlateforme::class)->tout();
    }
}

if (! function_exists('format_horaires')) {
    /**
     * ['jours' => ['lun', …, 'sam'], 'debut' => '07:30', 'fin' => '18:00'] → « lun.–sam. 07:30–18:00 ».
     * Null si rien n'est renseigné.
     */
    function format_horaires(?array $horaires): ?string
    {
        $jours = $horaires['jours'] ?? [];
        if ($jours === [] && empty($horaires['debut'])) {
            return null;
        }

        $tous = Catalogue::JOURS;
        $indices = array_map(fn ($j) => array_search($j, $tous, true), $jours);
        sort($indices);
        $consecutifs = $indices !== [] && end($indices) - $indices[0] === count($indices) - 1;
        $abr = fn (int $i) => $tous[$i].'.';

        $texteJours = match (true) {
            $indices === [] => '',
            count($indices) === 1 => $abr($indices[0]),
            $consecutifs => $abr($indices[0]).'–'.$abr(end($indices)),
            default => implode(', ', array_map($abr, $indices)),
        };

        $heures = ! empty($horaires['debut']) && ! empty($horaires['fin']) ? $horaires['debut'].'–'.$horaires['fin'] : '';

        return trim($texteJours.' '.$heures);
    }
}

if (! function_exists('format_mois_annee')) {
    /**
     * ['mois' => 6, 'annee' => 2028] → « juin 2028 » ; ['annee' => 2028] → « 2028 » ; vide → null.
     */
    function format_mois_annee(?array $date): ?string
    {
        if (empty($date['annee'])) {
            return null;
        }

        return empty($date['mois'])
            ? (string) $date['annee']
            : Carbon::create((int) $date['annee'], (int) $date['mois'], 1)->translatedFormat('F Y');
    }
}
