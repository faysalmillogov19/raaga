<?php

namespace App\Services\Publique;

use App\Enums\StatutBoutique;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutSite;
use App\Models\Boutique;
use App\Models\CampagneBoutiques;
use App\Models\GrilleTarifaire;
use App\Models\MoyenPaiement;
use App\Models\Niveau;
use App\Models\ParametresPaiements;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Services\Boutiques\ReservationsBoutiques;
use Illuminate\Support\Collection;

/**
 * Données des sections du thème « nuit » (accueil et « Obtenir une boutique »), toutes lues en base : boutiques par
 * niveau, tarifs du type d'emplacement le plus courant, exemple du téléphone illustré, campagne (cartes, compte à
 * rebours, jauge), frise du projet. Les montants viennent de la grille tarifaire confirmée (ReservationsBoutiques::tarifer).
 */
class AccueilNuit
{
    /** Couleurs des niveaux, dans l'ordre des rangs (anneau, barres, pastilles du tableau). */
    public const COULEURS_NIVEAUX = ['vert', 'bleu', 'jaune', 'rose', 'cyan', 'orange'];

    /** Campagne présentée : ouverte, sinon la prochaine confirmée, sinon la dernière clôturée (comme « Obtenir une boutique »). */
    public function campagneAffichee(): ?CampagneBoutiques
    {
        return CampagneBoutiques::ouverte()
            ?? CampagneBoutiques::query()->where('statut', StatutCampagneBoutiques::Confirmee)->orderBy('date_ouverture')->first()
            ?? CampagneBoutiques::query()->where('statut', StatutCampagneBoutiques::Cloturee)->latest('cloturee_le')->first();
    }

    /** Site principal : le site confirmé qui compte le plus de boutiques. */
    public function sitePrincipal(): ?Site
    {
        return Site::query()->where('statut', StatutSite::Confirme)
            ->withCount(['boutiques' => fn ($q) => $q->where('statut', '!=', StatutBoutique::Retiree)])
            ->orderByDesc('boutiques_count')->orderBy('id')->first();
    }

    /**
     * Boutiques des sites confirmés, par niveau (rang croissant) : total et libres.
     *
     * @return Collection<int, array{code: string, libelle: string, total: int, libres: int, couleur: string}>
     */
    public function niveaux(): Collection
    {
        $comptes = Boutique::query()->whereHas('site', fn ($q) => $q->where('statut', StatutSite::Confirme))
            ->where('statut', '!=', StatutBoutique::Retiree)
            ->selectRaw("niveau, count(*) as total, sum(case when statut in ('DISPONIBLE', 'EN_CAMPAGNE') then 1 else 0 end) as libres")
            ->groupBy('niveau')->get()->keyBy('niveau');

        return Niveau::query()->whereIn('code', $comptes->keys())->orderBy('rang')->get()->values()
            ->map(fn (Niveau $n, int $i) => ['code' => $n->code, 'libelle' => $n->libelle, 'total' => (int) $comptes[$n->code]->total,
                'libres' => (int) $comptes[$n->code]->libres, 'couleur' => self::COULEURS_NIVEAUX[$i % count(self::COULEURS_NIVEAUX)]]);
    }

    /**
     * Tarifs du type le plus courant du site principal, pour sa surface la plus fréquente, niveau par niveau, d'après la
     * grille confirmée ; null sans site, sans boutique ou sans grille confirmée.
     *
     * @return array{type: string, surface: float, lignes: list<array{code: string, libelle: string, couleur: string, prix: ?int, unite: ?string, loyer: int, mois_caution: int, mois_avance: int, libres: int}>}|null
     */
    public function tarifs(): ?array
    {
        $site = $this->sitePrincipal();
        $grille = $site ? GrilleTarifaire::query()->where('site_id', $site->id)->first()?->confirmee() : null;
        if (! $site || ! $grille) {
            return null;
        }
        $base = Boutique::query()->where('site_id', $site->id)->where('statut', '!=', StatutBoutique::Retiree)->whereNotNull('surface');
        $typeId = (clone $base)->selectRaw('type_boutique_id, count(*) as n')->groupBy('type_boutique_id')->orderByDesc('n')->value('type_boutique_id');
        $surface = $typeId ? (clone $base)->where('type_boutique_id', $typeId)->selectRaw('surface, count(*) as n')->groupBy('surface')->orderByDesc('n')->value('surface') : null;
        if (! $typeId || $surface === null) {
            return null;
        }
        $niveaux = $this->niveaux()->keyBy('code');
        $lignes = [];
        foreach ($niveaux as $code => $niveau) {
            $exemple = (clone $base)->where('type_boutique_id', $typeId)->where('niveau', $code)->where('surface', $surface)->with('site', 'type')->first();
            $tarif = $exemple ? ReservationsBoutiques::tarifer($exemple) : null;
            if (! $tarif) {
                continue;
            }
            $ligne = $grille['lignes'][GrilleTarifaire::cle($code, $typeId)] ?? [];
            $lignes[] = ['code' => $code, 'libelle' => $niveau['libelle'], 'couleur' => $niveau['couleur'],
                'prix' => isset($ligne['loyer_m2']) ? (int) $ligne['loyer_m2'] : null, 'unite' => $ligne['unite_loyer'] ?? null,
                'loyer' => $tarif['loyer_mensuel'], 'mois_caution' => $tarif['mois_caution'], 'mois_avance' => $tarif['mois_avance'], 'libres' => $niveau['libres']];
        }

        return $lignes === [] ? null : ['type' => (string) TypeBoutique::query()->whereKey($typeId)->value('libelle'), 'surface' => (float) $surface, 'lignes' => $lignes];
    }

    /**
     * Exemple du téléphone illustré (accueil) : une boutique disponible du type le plus courant et ses montants, deux moyens
     * de paiement mobiles actifs. Null sans boutique tarifée.
     *
     * @return array{code: string, loyer: int, total: int, moyens: list<string>}|null
     */
    public function exemple(?CampagneBoutiques $campagne): ?array
    {
        $site = $this->sitePrincipal();
        if (! $site) {
            return null;
        }
        $candidates = Boutique::query()->where('site_id', $site->id)->whereIn('statut', [StatutBoutique::Disponible, StatutBoutique::EnCampagne])
            ->whereNotNull('surface')->with('site', 'type')->orderBy('niveau')->orderBy('bloc')->orderBy('numero')->limit(200)->get();
        $boutique = $candidates->first(fn (Boutique $b) => ReservationsBoutiques::tarifer($b) !== null);
        $tarif = $boutique ? ReservationsBoutiques::tarifer($boutique) : null;
        if (! $tarif) {
            return null;
        }
        $moyens = ($campagne?->moyensCaution() ?? MoyenPaiement::proposables())
            ->filter(fn (MoyenPaiement $m) => $m->estMobile())->take(2)->map(fn (MoyenPaiement $m) => $m->libelle)->values()->all();

        return ['code' => $boutique->code, 'loyer' => $tarif['loyer_mensuel'], 'total' => $tarif['caution'] + $tarif['avance'], 'moyens' => $moyens];
    }

    /**
     * Campagne : état public, boutiques (libres, réservées, total), limite, délais, échéance du compte à rebours.
     *
     * @return array<string, mixed>
     */
    public function campagne(CampagneBoutiques $campagne): array
    {
        $compteurs = $campagne->compteurs();
        $total = array_sum($compteurs);
        $limites = collect($campagne->typesConcernes())->map(fn ($t) => $campagne->limitePour($t->id))->unique()->values();
        $etat = $campagne->etatPublic();
        $niveaux = Boutique::query()->whereIn('site_id', (array) $campagne->sites)->whereIn('type_boutique_id', (array) $campagne->types)
            ->distinct()->count('niveau');

        return [
            'etat' => $etat,
            'libres' => $compteurs['disponible'],
            'reservees' => $compteurs['pre_reservee'] + $compteurs['reservee'] + $compteurs['attribuee'],
            'total' => $total,
            'niveaux' => $niveaux,
            'sites' => count((array) $campagne->sites),
            'limite_uniforme' => $limites->count() === 1 ? $limites->first() : null,
            'delai_caution' => $campagne->libelleDelaiPaiement(),
            'delai_avance' => ParametresPaiements::libelleEnVigueur('avance'),
            // Compte à rebours : ouverture si elle est à venir, clôture si la campagne est ouverte ; rien une fois terminée.
            'cible' => match (true) {
                $etat === 'bientot' && $campagne->date_ouverture?->isFuture() => $campagne->date_ouverture,
                $etat === 'ouverte' => $campagne->date_cloture,
                default => null,
            },
        ];
    }

    /**
     * Frise du projet : études (terminées si une grille est confirmée), réservations (dates de la campagne), construction
     * (aucun paramètre : « À venir »), ouverture du marché (paramètre projet.dateOuverturePrevisionnelle, sinon « À venir »).
     *
     * @return list<array{date: string, titre: string, detail: string, couleur: string}>
     */
    public function frise(?CampagneBoutiques $campagne): array
    {
        $t = __('public.nuit.accueil');
        $c = $t['calendrier'];
        $grilleConfirmee = GrilleTarifaire::query()->get()->contains(fn (GrilleTarifaire $g) => $g->confirmee() !== null);
        $etat = $campagne?->etatPublic();
        $format = fn ($date) => $date->format('d/m/Y');

        return [
            ['date' => $grilleConfirmee ? $t['termine'] : $t['en_cours'], 'titre' => $c['etudes']['titre'], 'detail' => $c['etudes']['detail'], 'couleur' => 'vert'],
            ['date' => $campagne ? $format($campagne->date_ouverture) : $t['a_venir'], 'titre' => $c['reservations']['titre'], 'couleur' => 'bleu',
                'detail' => match ($etat) {
                    'ouverte' => __('public.nuit.accueil.calendrier.reservations.ouvertes', ['date' => $format($campagne->date_cloture)]),
                    'bientot' => __('public.nuit.accueil.calendrier.reservations.prevues', ['date' => $format($campagne->date_ouverture)]),
                    'terminee' => __('public.nuit.accueil.calendrier.reservations.terminees', ['date' => $format($campagne->date_cloture)]),
                    default => $c['reservations']['a_venir'],
                }],
            ['date' => $t['a_venir'], 'titre' => $c['construction']['titre'], 'detail' => $c['construction']['detail'], 'couleur' => 'orange'],
            ['date' => format_mois_annee(plateforme('projet.dateOuverturePrevisionnelle')) ?? $t['a_venir'], 'titre' => $c['ouverture']['titre'],
                'detail' => $c['ouverture']['detail'], 'couleur' => 'rose'],
        ];
    }

    /** Plage de mois de la grille (« 12 mois », « 12 à 60 mois ») ; null sans grille. @param  array{0: int, 1: int}|null  $plage */
    public static function mois(?array $plage): ?string
    {
        if (! $plage) {
            return null;
        }

        return $plage[0] === $plage[1]
            ? trans_choice('public.accueil_raaga.mois', $plage[0], ['n' => $plage[0]])
            : __('public.accueil_raaga.mois_plage', ['min' => $plage[0], 'max' => $plage[1]]);
    }
}
