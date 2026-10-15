<?php

namespace App\Services\Validation;

use App\Contracts\Parametrable;
use App\Enums\Droit;
use App\Enums\StatutTypeBoutique;
use App\Http\Controllers\Admin\CampagnesBoutiquesController;
use App\Http\Controllers\Admin\GrillesTarifairesController;
use App\Http\Controllers\Admin\NiveauxZonesController;
use App\Http\Controllers\Admin\SiteFinancesController;
use App\Http\Controllers\Admin\SitesController;
use App\Http\Controllers\Admin\TypesBoutiqueController;
use App\Models\CampagneBoutiques;
use App\Models\Niveau;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\Unite;
use App\Models\User;
use App\Models\ZoneSite;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Parametrage\WorkflowParametrage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Soumission groupée à validation depuis les listes de gestion, et retrait de la validation.
 * Aucune règle nouvelle : chaque élément passe par l'action « Soumettre » de sa fiche (mêmes contrôles, versions,
 * journal) ; le contrôle préalable rejoue cette action dans une transaction annulée. Clé d'un élément : « entité:id »,
 * la même que dans « À valider » (grille et données financières : identifiant du site).
 */
class SoumissionGroupee
{
    /** Entité => droit de l'auteur (GESTIONNAIRE ou RÉGULATEUR ; jamais le SUPER_ADMIN). */
    public const DROITS = [
        'site' => Droit::SitesGerer, 'type' => Droit::SitesGerer, 'niveaux' => Droit::SitesGerer, 'zones' => Droit::SitesGerer,
        'unites' => Droit::UnitesGerer, 'grille' => Droit::BoutiquesSaisir, 'finances' => Droit::BoutiquesSaisir, 'campagne' => Droit::BoutiquesSaisir,
    ];

    private const REFERENTIELS = ['type' => TypeBoutique::class, 'niveaux' => Niveau::class, 'zones' => ZoneSite::class, 'unites' => Unite::class];

    public function __construct(private readonly WorkflowParametrage $workflow, private readonly Journal $journal) {}

    public static function peut(?User $user, string $entite): bool
    {
        return $user !== null && isset(self::DROITS[$entite]) && $user->can(self::DROITS[$entite]->value);
    }

    /** Ligne de la liste (site pour « site », « grille » et « finances »). */
    public static function ligne(string $entite, int $id): Model
    {
        return match ($entite) {
            'site', 'grille', 'finances' => Site::query()->findOrFail($id),
            'campagne' => CampagneBoutiques::query()->findOrFail($id),
            default => self::REFERENTIELS[$entite]::query()->findOrFail($id),
        };
    }

    /** Objet soumis à validation pour une ligne de la liste, ou null (site sans grille…). */
    public static function objet(string $entite, Model $ligne): ?Model
    {
        return match ($entite) {
            'grille' => $ligne->grilleTarifaire,
            'finances' => $ligne->finances,
            default => $ligne,
        };
    }

    /** « soumettre » (brouillon ou rejeté), « retirer » (soumis par soi, pas encore traité) ou null. */
    public static function etat(string $entite, Model $ligne, User $user): ?string
    {
        $objet = self::objet($entite, $ligne);
        if ($objet instanceof Parametrable) {
            return match (true) {
                $objet->estModifiable() => 'soumettre',
                $objet->estSoumis() && (int) $objet->soumis_par === $user->id => 'retirer',
                default => null,
            };
        }
        if ($objet !== null && isset(self::REFERENTIELS[$entite])) {
            return match (true) {
                in_array($objet->statut, [StatutTypeBoutique::Brouillon, StatutTypeBoutique::Rejete], true) => 'soumettre',
                $objet->statut === StatutTypeBoutique::AValider && (int) $objet->demande_par === $user->id => 'retirer',
                default => null,
            };
        }

        return null;
    }

    /** Requête des lignes d'une entité (site pour « site », « grille » et « finances »). */
    public static function requete(string $entite): Builder
    {
        return match ($entite) {
            'site' => Site::query(),
            'grille' => Site::query()->with('grilleTarifaire'),
            'finances' => Site::query()->with('finances'),
            'campagne' => CampagneBoutiques::query(),
            default => self::REFERENTIELS[$entite]::query(),
        };
    }

    /**
     * « Mes brouillons » : brouillons et rejetés de chaque entité que l'utilisateur soumet, regroupés par entité.
     *
     * @return array<string, Collection<int, Model>>
     */
    public static function brouillons(User $user): array
    {
        $groupes = [];
        foreach (array_keys(self::DROITS) as $entite) {
            if (self::peut($user, $entite)) {
                $lignes = self::aSoumettre($entite, self::requete($entite))->orderBy(isset(self::REFERENTIELS[$entite]) ? 'code' : 'id')->get();
                if ($lignes->isNotEmpty()) {
                    $groupes[$entite] = $lignes;
                }
            }
        }

        return $groupes;
    }

    public static function nombreBrouillons(User $user): int
    {
        return collect(array_keys(self::DROITS))->filter(fn ($e) => self::peut($user, $e))->sum(fn ($e) => self::aSoumettre($e, self::requete($e))->count());
    }

    /** Fiche de l'élément. */
    public static function url(string $entite, Model $ligne): string
    {
        return match ($entite) {
            'site' => route('admin.sites.show', $ligne),
            'grille' => route('admin.boutiques.grilles.show', $ligne),
            'finances' => route('admin.sites.finances', $ligne),
            'campagne' => route('admin.boutiques.show', $ligne),
            'type' => route('admin.sites.types.show', $ligne),
            default => route('admin.referencement.zones.show', [$entite, $ligne->getKey()]),
        };
    }

    /** Lignes à soumettre (brouillons et rejetés) : filtre rapide « À soumettre ». */
    public static function aSoumettre(string $entite, Builder $requete): Builder
    {
        return match ($entite) {
            'site' => $requete->where('statut', 'BROUILLON'),
            'grille' => $requete->whereHas('grilleTarifaire', fn ($q) => $q->where('statut', 'BROUILLON')),
            'finances' => $requete->whereHas('finances', fn ($q) => $q->where('statut', 'BROUILLON')),
            'campagne' => $requete->where('statut', 'BROUILLON'),
            default => $requete->whereIn('statut', [StatutTypeBoutique::Brouillon->value, StatutTypeBoutique::Rejete->value]),
        };
    }

    public static function libelle(string $entite, Model $ligne): string
    {
        return match ($entite) {
            'site', 'grille', 'finances' => $ligne->code ?: $ligne->nom,
            'campagne' => $ligne->nom,
            default => $ligne->code ?: $ligne->libelle,
        };
    }

    /**
     * Contrôle préalable de chaque élément : l'action « Soumettre » est rejouée puis annulée.
     *
     * @param  list<array{0: string, 1: int}>  $elements
     * @return list<array{cle: string, libelle: string, pret: bool, raison: ?string}>
     */
    public function controler(Request $request, array $elements): array
    {
        return array_map(function (array $e) use ($request) {
            [$entite, $id] = $e;
            $ligne = self::ligne($entite, $id);
            DB::beginTransaction();
            try {
                $raison = $this->soumettreUn($request, $entite, $ligne);
            } finally {
                DB::rollBack();
            }

            return ['cle' => $entite.':'.$id, 'libelle' => self::libelle($entite, $ligne), 'pret' => $raison === null, 'raison' => $raison];
        }, $elements);
    }

    /**
     * Soumission élément par élément : un refus n'annule pas les autres.
     *
     * @param  list<array{0: string, 1: int}>  $elements
     * @return array{soumis: list<string>, refus: list<array{libelle: string, raison: string}>}
     */
    public function soumettre(Request $request, array $elements, ?string $commentaire): array
    {
        $resultat = ['soumis' => [], 'refus' => []];
        foreach ($elements as [$entite, $id]) {
            $ligne = self::ligne($entite, $id);
            $raison = $this->soumettreUn($request, $entite, $ligne);
            if ($raison !== null) {
                $resultat['refus'][] = ['libelle' => self::libelle($entite, $ligne), 'raison' => $raison];

                continue;
            }
            $resultat['soumis'][] = self::libelle($entite, $ligne);
            if ($commentaire !== null && $commentaire !== '') {
                $this->journal->ecrire($request->user(), 'soumission.commentaire', $entite, $id, null, ['commentaire' => $commentaire]);
            }
        }

        return $resultat;
    }

    /**
     * Retrait de la validation de ses propres éléments soumis, non encore traités : retour au brouillon.
     *
     * @param  list<array{0: string, 1: int}>  $elements
     * @return array{soumis: list<string>, refus: list<array{libelle: string, raison: string}>}
     */
    public function retirer(Request $request, array $elements): array
    {
        $resultat = ['soumis' => [], 'refus' => []];
        foreach ($elements as [$entite, $id]) {
            $ligne = self::ligne($entite, $id);
            try {
                DB::transaction(fn () => $this->retirerUn($request->user(), $entite, $id));
                $resultat['soumis'][] = self::libelle($entite, $ligne);
            } catch (RegleGestionException $e) {
                $resultat['refus'][] = ['libelle' => self::libelle($entite, $ligne), 'raison' => $e->getMessage()];
            }
        }

        return $resultat;
    }

    /** Champ du formulaire concerné par la raison d'un refus (sinon « soumission », affiché au-dessus des boutons). */
    public static function champ(string $raison): string
    {
        return match ($raison) {
            __('admin.sites.regles.sans_code_site') => 'code',
            default => 'soumission',
        };
    }

    /** Action « Soumettre » de la fiche, appelée telle quelle ; renvoie la raison du refus ou null. */
    public function soumettreUn(Request $request, string $entite, Model $ligne): ?string
    {
        $etat = self::etat($entite, $ligne, $request->user());
        $objet = self::objet($entite, $ligne);
        if ($etat !== 'soumettre') {
            $soumis = $objet instanceof Parametrable ? $objet->estSoumis() : $objet?->statut === StatutTypeBoutique::AValider;

            return __($soumis ? 'admin.soumission.erreurs.deja_soumis' : ($objet === null ? 'admin.soumission.erreurs.rien' : 'admin.soumission.erreurs.non_soumissible'));
        }

        $session = $request->session();
        $session->forget(['succes', 'erreur']);
        $sousRequete = Request::create($request->url(), 'POST');
        $sousRequete->setUserResolver(fn () => $request->user());
        $sousRequete->setLaravelSession($session);

        try {
            match ($entite) {
                'site' => app(SitesController::class)->soumettre($sousRequete, $ligne),
                'grille' => app(GrillesTarifairesController::class)->soumettre($sousRequete, $ligne),
                'finances' => app(SiteFinancesController::class)->soumettre($sousRequete, $ligne),
                'campagne' => app(CampagnesBoutiquesController::class)->soumettre($sousRequete, $ligne),
                'type' => app(TypesBoutiqueController::class)->soumettre($sousRequete, $ligne),
                default => app(NiveauxZonesController::class)->soumettre($sousRequete, $entite, $ligne->getKey()),
            };
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        } catch (Throwable $e) {
            report($e);

            return __('admin.soumission.erreurs.element');
        }
        $erreur = $session->get('erreur');
        $session->forget(['succes', 'erreur']);

        return $erreur;
    }

    /** @throws RegleGestionException */
    private function retirerUn(User $user, string $entite, int $id): void
    {
        if (! isset(self::REFERENTIELS[$entite])) {
            $objet = self::objet($entite, self::ligne($entite, $id));
            $objet = $objet?->newQuery()->lockForUpdate()->find($objet->getKey());
            if (! $objet instanceof Parametrable) {
                throw new RegleGestionException(__('admin.soumission.erreurs.non_soumis'));
            }
            $this->workflow->retirer($objet, $user);

            return;
        }

        $element = self::REFERENTIELS[$entite]::query()->lockForUpdate()->findOrFail($id);
        if ($element->statut !== StatutTypeBoutique::AValider) {
            throw new RegleGestionException(__('admin.soumission.erreurs.non_soumis'));
        }
        if ((int) $element->demande_par !== $user->id) {
            throw new RegleGestionException(__('admin.soumission.erreurs.pas_auteur'));
        }
        $element->forceFill(['statut' => StatutTypeBoutique::Brouillon])->save();
        $element->creerVersion($user, __('admin.soumission.motif_retrait'));
        $this->journal->ecrire($user, ($entite === 'type' ? 'type_boutique' : $element::typeReferentiel()).'.retire', class_basename($element), $element->id,
            ['statut' => StatutTypeBoutique::AValider->value], ['statut' => StatutTypeBoutique::Brouillon->value]);
    }
}
