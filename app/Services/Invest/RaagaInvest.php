<?php

namespace App\Services\Invest;

use App\Enums\StatutSouscription;
use App\Models\ManifestationInteret;
use App\Models\ParametresInvestissement;
use App\Models\Souscription;
use App\Support\Phase;

/**
 * Calculs RAAGA Invest, tous issus de la base et des paramètres confirmés (rien n'est écrit en dur) : simulateur
 * (nombre d'actions × valeur indicative = montant, part de l'objectif de collecte, indicative), jauge de collecte
 * (peut dépasser 100 %), totaux des manifestations d'intérêt. Jamais de « part du capital ».
 */
class RaagaInvest
{
    /** @return array{objectif_collecte: int, valeur_action: int, minimum_actions: int, plafond_global: ?int}|null */
    public static function parametres(): ?array
    {
        return ParametresInvestissement::confirmes();
    }

    /**
     * Conditions affichées au public, d'une seule source :
     *  - souscription ouverte (paramètre ouvert et tranche ouverte) : prix et minimum figés de la tranche (« Prix de l'action ») ;
     *  - sinon : valeur indicative et nombre minimum d'actions des paramètres confirmés (« Valeur indicative de l'action ») ;
     *  - sans paramètres confirmés : null (aucune valeur affichée).
     *
     * @return array{ouverte: bool, prix: int, minimum: int}|null
     */
    public static function conditions(): ?array
    {
        if (Phase::souscriptionOuverte() && ($tranche = Phase::trancheOuverte())) {
            return ['ouverte' => true, 'prix' => (int) $tranche->valeur_unitaire, 'minimum' => max(1, (int) $tranche->min_actions_par_souscription)];
        }
        $p = self::parametres();

        return $p === null ? null : ['ouverte' => false, 'prix' => $p['valeur_action'], 'minimum' => $p['minimum_actions']];
    }

    /**
     * Simulation : montant et part de l'objectif de collecte (indicative), ou null sans paramètres confirmés.
     *
     * @return array{nombre: int, valeur_action: int, montant: int, part_objectif: float}|null
     */
    public static function simuler(int $nombre): ?array
    {
        $p = self::parametres();
        if ($p === null || $nombre < 1) {
            return null;
        }
        $montant = $nombre * $p['valeur_action'];

        return ['nombre' => $nombre, 'valeur_action' => $p['valeur_action'], 'montant' => $montant, 'part_objectif' => $montant * 100 / $p['objectif_collecte']];
    }

    /** Montant réellement collecté : souscriptions payées (0 tant que la souscription est fermée). */
    public static function collecte(): int
    {
        return (int) Souscription::query()->whereIn('statut', [StatutSouscription::Payee, StatutSouscription::Allouee])->sum('montant');
    }

    /**
     * Jauge de l'accueil : collecté, objectif, pourcentage de l'objectif (peut dépasser 100), largeur de la barre (≤ 100).
     *
     * @return array{collecte: int, objectif: int, pourcentage: float, largeur: float, libelle: string}|null
     */
    public static function jauge(): ?array
    {
        $p = self::parametres();
        if ($p === null) {
            return null;
        }
        $collecte = self::collecte();
        $pourcentage = $collecte * 100 / $p['objectif_collecte'];

        $arrondi = floor($pourcentage * 10) / 10;

        return ['collecte' => $collecte, 'objectif' => $p['objectif_collecte'], 'pourcentage' => $pourcentage, 'largeur' => min(100.0, $pourcentage),
            // « 112 % », « 7,5 % » : jamais plafonné à 100.
            'libelle' => format_nombre($arrondi, fmod($arrondi, 1.0) === 0.0 ? 0 : 1)."\u{00A0}%"];
    }

    /** @return array{nombre: int, intentions: int, collecte: int} tableau de bord du back-office */
    public static function indicateurs(): array
    {
        return [
            'nombre' => ManifestationInteret::query()->count(),
            'intentions' => (int) ManifestationInteret::query()->sum('montant'),
            'collecte' => self::collecte(),
        ];
    }
}
