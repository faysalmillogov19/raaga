<?php

namespace App\Services\Validation;

use App\Contracts\Parametrable;
use App\Models\CampagneBoutiques;
use App\Models\Convention;
use App\Models\DistributionDividende;
use App\Models\GrilleTarifaire;
use App\Models\ParametresInvestissement;
use App\Models\Site;
use App\Models\SiteFinance;
use App\Models\Tranche;
use App\Models\User;
use App\Notifications\DecisionRendue;

/**
 * Notification de l'auteur après une décision du VALIDATEUR (back-office et e-mail, motif en cas de rejet), quel que
 * soit l'écran d'où vient la décision : fiche de l'élément ou file « À valider ». Appelée une seule fois, par l'action
 * de validation de l'élément, une fois la décision enregistrée.
 */
final class NotificationDecision
{
    /** Décision sur un import entier : les sites confirmés ou rejetés un à un ne notifient pas (une seule notification). */
    private static bool $suspendue = false;

    /** Exécute l'action sans notification par élément ; la notification de l'ensemble est envoyée par l'appelant. */
    public static function sansNotification(\Closure $action): mixed
    {
        $avant = self::$suspendue;
        self::$suspendue = true;
        try {
            return $action();
        } finally {
            self::$suspendue = $avant;
        }
    }

    public static function suspendue(): bool
    {
        return self::$suspendue;
    }

    /** @param  string  $decision  confirmer | rejeter */
    public function envoyer(?int $auteurId, string $element, string $libelle, string $decision, ?string $motif, string $url): void
    {
        $auteur = $auteurId ? User::query()->find($auteurId) : null;
        $auteur?->notify(new DecisionRendue($element, $libelle, $decision, $motif, $url));
    }

    /** Paramétrage du circuit commun : l'auteur est celui de la version soumise. */
    public function pourParametrage(Parametrable $objet, string $decision, ?string $motif): void
    {
        $auteur = $objet->versions()->reorder('numero', 'desc')->value('auteur_id');
        $nomSite = fn (?Site $s) => $s ? ($s->code ? $s->code.' · ' : '').$s->nom : '';
        [$element, $libelle, $url] = match (true) {
            $objet instanceof Site => ['site', $nomSite($objet), route('admin.sites.show', $objet)],
            $objet instanceof GrilleTarifaire => ['grille', $nomSite($objet->site), route('admin.boutiques.grilles.show', $objet->site_id)],
            $objet instanceof SiteFinance => ['finances', $nomSite($objet->site), route('admin.sites.finances', $objet->site_id)],
            $objet instanceof ParametresInvestissement => ['investissement', __('admin.investissement.titre'), route('admin.investissement')],
            $objet instanceof CampagneBoutiques => ['campagne', $objet->nom, route('admin.boutiques.show', $objet)],
            $objet instanceof Tranche => ['tranche', $objet->nom, route('admin.tranches.show', $objet)],
            $objet instanceof DistributionDividende => ['distribution', __('admin.dividendes.exercice', ['annee' => $objet->exercice]), route('admin.dividendes.show', $objet)],
            $objet instanceof Convention => ['convention', $objet->reference, route('admin.conventions.show', $objet)],
            default => [$objet->typeParametrage(), (string) $objet->getKey(), route('admin.tableau')],
        };

        $this->envoyer($auteur, __('admin.a_valider.elements.'.$element), $libelle, $decision, $motif, $url);
    }
}
