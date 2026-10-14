<?php

namespace App\Support\Plateforme;

use App\Support\Telephone;
use Carbon\Carbon;

/**
 * Valeur lisible d'un paramètre de la plateforme (lecture seule, historique). Vide → « — ».
 */
final class Affichage
{
    public static function valeur(string $section, string $champ, mixed $valeur): string
    {
        $vide = '—';
        $l = fn (string $cle) => __('admin.plateforme.'.$cle);

        return match (Catalogue::type($section, $champ)) {
            'tel_bf' => $valeur ? Telephone::formater($valeur) : $vide,
            'entier' => $valeur === null ? $vide : ($champ === 'nombreBoutiques' ? format_nombre($valeur) : format_fcfa($valeur)),
            'image' => $valeur ? basename($valeur) : $vide,
            'carrousel' => $valeur ? trans_choice('admin.plateforme.carrousel.resume', count($valeur), ['n' => count($valeur)])
                .' ('.trans_choice('admin.plateforme.carrousel.resume_actives', $n = collect($valeur)->where('active', true)->count(), ['n' => $n]).') : '.self::joindre(array_map(fn ($d) => ($d['active'] ? '' : '(inactive) ').($d['titre'] ?? $d['alt']), $valeur), ' ; ') : $vide,
            'horaires' => format_horaires($valeur) ?? $vide,
            'gps' => ($valeur['latitude'] ?? null) !== null ? $valeur['latitude'].', '.$valeur['longitude'] : $vide,
            'contact' => self::joindre([$valeur['libelle'] ?? null, $valeur['nom'] ?? null, $valeur['telephone'] ?? null, $valeur['email'] ?? null])
                .(($valeur['afficher'] ?? false) ? '' : ' ('.mb_strtolower($l('sous_champs.afficher')).' : '.mb_strtolower($l('non')).')'),
            'personne' => self::joindre([$valeur['nom'] ?? null, $valeur['fonction'] ?? null]),
            'hebergeur' => self::joindre([$valeur['nom'] ?? null, $valeur['adresse'] ?? null, $valeur['contact'] ?? null]),
            'reseaux' => self::joindre(array_map(fn ($r) => $l('reseaux.'.$r['reseau']).($r['afficher'] ? '' : ' (masqué)'), $valeur ?? []), ', '),
            'liens' => self::joindre(array_map(fn ($r) => $r['libelle'], $valeur ?? []), ', '),
            'financement' => self::joindre(array_map(fn ($r) => $r['libelle'].' : '.($r['approximatif'] ? '≈ ' : '').format_fcfa($r['montant']), $valeur ?? []), ' ; '),
            'mois_annee' => format_mois_annee($valeur) ?? $vide,
            'bandeau' => ($valeur['texte'] ?? null)
                ? $l('types_bandeau.'.$valeur['type']).' — '.$valeur['texte'].self::periode($valeur['debut'] ?? null, $valeur['fin'] ?? null)
                : $vide,
            'maintenance' => ($valeur['actif'] ?? false) ? $l('oui').' — '.$valeur['message'] : $l('non'),
            default => $valeur === null || $valeur === '' ? $vide : (string) $valeur,
        };
    }

    private static function joindre(array $parties, string $separateur = ' · '): string
    {
        $parties = array_filter($parties, fn ($p) => $p !== null && $p !== '');

        return $parties === [] ? '—' : implode($separateur, $parties);
    }

    private static function periode(?string $debut, ?string $fin): string
    {
        $f = fn ($d) => Carbon::parse($d)->format('d/m/Y H:i');

        return match (true) {
            $debut && $fin => ' (du '.$f($debut).' au '.$f($fin).')',
            (bool) $debut => ' (à partir du '.$f($debut).')',
            (bool) $fin => ' (jusqu’au '.$f($fin).')',
            default => '',
        };
    }
}
