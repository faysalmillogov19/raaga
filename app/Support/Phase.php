<?php

namespace App\Support;

use App\Enums\ClasseActions;
use App\Enums\StatutDistribution;
use App\Enums\StatutTranche;
use App\Models\DecisionPhase;
use App\Models\DistributionDividende;
use App\Models\Tranche;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Deux démarches sur le site public : investir (actions B) et obtenir une boutique (toujours ouverte).
 * Deux paramètres, proposés par le SUPER_ADMIN et confirmés par le VALIDATEUR (dernière décision confirmée) :
 * - « présentation des actions visible » : pages d'information, carte « J'investis », FAQ des actions ;
 * - « souscription d'actions ouverte » : ne vaut que si la présentation est visible ET qu'une tranche est ouverte
 *   (statut OUVERTE, atteint seulement après confirmation par le VALIDATEUR).
 * Tant qu'aucune décision n'est confirmée : valeurs initiales de config/raaga.php (fermé par défaut).
 */
final class Phase
{
    public const CACHE = 'phase.decision';

    /** Pages d'information sur les actions, carte « J'investis », FAQ des actions, onglet « Mes actions ». */
    public static function presentationActions(): bool
    {
        return self::decision()['presentation'];
    }

    /** Paramètre « souscription d'actions ouverte » seul (sans la condition de tranche). */
    public static function souscriptionParametre(): bool
    {
        return self::decision()['presentation'] && self::decision()['souscription'];
    }

    /** Souscription réellement possible : paramètre ouvert ET tranche ouverte (donc confirmée). */
    public static function souscriptionOuverte(): bool
    {
        return self::souscriptionParametre() && self::trancheOuverte() !== null;
    }

    public static function trancheOuverte(): ?Tranche
    {
        return Tranche::query()->where('statut', StatutTranche::Ouverte)->latest('date_ouverture')->first();
    }

    /** Tranche présentée au public : la tranche ouverte, à défaut la prochaine tranche confirmée. */
    public static function trancheAffichee(): ?Tranche
    {
        return self::trancheOuverte() ?? Tranche::query()->where('statut', StatutTranche::Confirmee)->orderBy('date_ouverture')->first();
    }

    /**
     * Taux de la retenue IRVM, lu dans la dernière distribution de dividendes des actions B confirmée (« 12,5 % »),
     * ou null si aucune distribution n'est encore paramétrée.
     */
    public static function tauxIrvm(): ?string
    {
        $pb = DistributionDividende::query()->where('classe_actions', ClasseActions::B)
            ->whereIn('statut', [StatutDistribution::Confirme, StatutDistribution::EnPaiement, StatutDistribution::Paye])
            ->latest('confirme_le')->value('taux_irvm_pb');

        return $pb === null ? null : rtrim(rtrim(format_nombre($pb / 100, 2), '0'), ',').' %';
    }

    /** Lien des boutons « Devenir actionnaire » : souscription si elle est ouverte, sinon alerte SMS. */
    public static function lienSouscription(): string
    {
        return self::souscriptionOuverte() ? (auth()->check() ? route('souscrire') : route('inscription')) : route('souscription.bientot');
    }

    /** @return array{presentation: bool, souscription: bool} */
    private static function decision(): array
    {
        try {
            $ligne = Cache::remember(self::CACHE, 60, fn () => DecisionPhase::query()->where('statut', DecisionPhase::CONFIRMEE)
                ->latest('decide_le')->latest('id')->first(['presentation_actions', 'souscription_actions'])?->toArray() ?? false);
        } catch (QueryException) {
            $ligne = false;
        }

        return $ligne === false
            ? ['presentation' => (bool) config('raaga.phase.presentation_actions'), 'souscription' => (bool) config('raaga.phase.souscription_actions')]
            : ['presentation' => (bool) $ligne['presentation_actions'], 'souscription' => (bool) $ligne['souscription_actions']];
    }
}
