<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\StatutTypeBoutique;
use App\Http\Controllers\Admin\Concerns\EnregistreEtSoumet;
use App\Http\Controllers\Controller;
use App\Models\HistoriqueCode;
use App\Models\TypeBoutique;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Validation\NotificationDecision;
use App\Support\BadgeStatut;
use App\Support\Tableau\Colonne;
use App\Support\Tableau\Filtre;
use App\Support\Tableau\ResultatTableau;
use App\Support\Tableau\Tableau;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Types de boutiques. GESTIONNAIRE DES SITES ET BOUTIQUES : ajoute un type (soumis au VALIDATEUR), modifie libellé,
 * description et ordre à tout moment, demande un changement d'« attribuable par tirage » (soumis au VALIDATEUR), désactive ou réactive, supprime un type jamais
 * utilisé et non « par défaut ». VALIDATEUR : confirme ou rejette. Autres profils : lecture seule.
 */
class TypesBoutiqueController extends Controller
{
    use EnregistreEtSoumet;

    public function __construct(private readonly Journal $journal, private readonly NotificationDecision $notifications) {}

    public function index(Request $request): Response|StreamedResponse|View
    {
        Gate::authorize(Droit::SitesVoir->value);
        $t = __('admin.types');
        $c = $t['colonnes'];
        $ouiNon = [1 => $t['oui'], 0 => $t['non']];

        return Tableau::pour(TypeBoutique::query(), 'types')
            ->colonnes([
                Colonne::make('code', $c['code'])->triable()->recherchable()->classe('font-mono font-semibold'),
                Colonne::make('libelle', $c['libelle'])->triable()->recherchable(['libelle', 'description'])
                    ->valeur(fn (TypeBoutique $x) => new HtmlString('<a href="'.e(route('admin.sites.types.show', $x)).'" class="font-bold text-vert-fonce underline underline-offset-2">'.e($x->libelle).'</a>'
                        .($x->par_defaut ? ' '.BadgeStatut::html($t['par_defaut'], 'info')->toHtml() : '')
                        .($x->description ? '<br><span class="texte-carte">'.e($x->description).'</span>' : '')))
                    ->texte(fn (TypeBoutique $x) => $x->libelle),
                Colonne::make('attribuable', $c['attribuable'])->triable()
                    ->valeur(fn (TypeBoutique $x) => new HtmlString(e($x->attribuable ? $t['oui'] : $t['non']).($x->attribuable_demande !== null
                        ? '<br>'.BadgeStatut::html($x->attribuable_demande ? $t['demande_oui'] : $t['demande_non'], 'avertissement')->toHtml() : '')))
                    ->texte(fn (TypeBoutique $x) => $x->attribuable ? $t['oui'] : $t['non']),
                Colonne::make('ordre', $c['ordre'])->numerique()->triable(),
                Colonne::make('statut', $c['statut'])->triable()
                    ->valeur(fn (TypeBoutique $x) => new HtmlString(BadgeStatut::html($x->statut)->toHtml().($x->actif ? '' : ' '.BadgeStatut::html($t['desactive'])->toHtml())))
                    ->texte(fn (TypeBoutique $x) => $x->statut->label().($x->actif ? '' : ' · '.$t['desactive'])),
                Colonne::make('utilise_par', $c['utilise_par'])->numerique()->triable('boutiques_count')->avec(fn ($q) => $q->withCount('boutiques'))
                    ->valeur(fn (TypeBoutique $x) => trans_choice('admin.types.boutiques', $x->boutiques_count, ['n' => format_nombre($x->boutiques_count)]))
                    ->texte(fn (TypeBoutique $x) => $x->boutiques_count),
                Colonne::make('actions', $c['actions'])->vue('admin.types._actions', 'type')->exportable(false),
            ])
            ->filtres([
                Filtre::make('statut', $c['statut'], collect(StatutTypeBoutique::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all(), 'statut'),
                Filtre::make('actif', $t['filtre_actif'], [1 => $t['actif'], 0 => $t['desactive']], 'actif'),
                Filtre::make('attribuable', $c['attribuable'], $ouiNon, 'attribuable'),
            ])
            ->selection('type', $request->user())
            ->triParDefaut('ordre')
            ->export('Raaga_types-boutiques')
            ->repondre($request, fn (ResultatTableau $resultat) => view('admin.types.index', ['tableau' => $resultat]));
    }

    public function show(TypeBoutique $type): View
    {
        Gate::authorize(Droit::SitesVoir->value);

        return view('admin.types.show', [
            'type' => $type->loadCount('boutiques'),
            'versions' => $type->versions()->with('auteur')->get(),
            'utilise' => $type->estUtilise(),
        ]);
    }

    /** Nouveau type : utilisable seulement après validation. */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        if (is_string($request->input('code'))) {
            $request->merge(['code' => mb_strtoupper(trim($request->input('code')))]);
        }
        $donnees = $request->validate([
            'code' => ['required', 'string', 'regex:'.TypeBoutique::FORMAT_CODE, Rule::unique('types_boutiques', 'code'),
                // Le code d'un type supprimé n'est jamais réutilisé.
                fn (string $a, mixed $v, Closure $echec) => HistoriqueCode::query()->where('objet_type', HistoriqueCode::TYPE)->where('ancien_code', $v)->exists()
                    ? $echec(__('admin.types.regles.code_reutilise')) : null],
            ...$this->reglesLibres(),
            'attribuable' => ['required', 'boolean'],
        ], [], __('admin.types.attributs'));

        $brouillon = $request->input('apres') === 'brouillon';
        $type = DB::transaction(function () use ($request, $donnees, $brouillon) {
            $type = TypeBoutique::create([...$donnees, 'ordre' => $donnees['ordre'] ?? (int) TypeBoutique::max('ordre') + 1,
                'actif' => true, 'par_defaut' => false, 'statut' => $brouillon ? StatutTypeBoutique::Brouillon : StatutTypeBoutique::AValider]);
            $type->forceFill(['demande_par' => $request->user()->id, 'demande_le' => now()])->save();
            $type->creerVersion($request->user(), __($brouillon ? 'admin.types.motifs.creation_brouillon' : 'admin.types.motifs.creation'));

            return $type;
        });
        $this->journal->ecrire($request->user(), 'type_boutique.cree', 'TypeBoutique', $type->id, null, ['code' => $type->code, 'libelle' => $type->libelle]);

        return redirect()->route('admin.sites.types')->with('succes', __($brouillon ? 'admin.types.messages.cree_brouillon' : 'admin.types.messages.cree'));
    }

    /** Libellé, description et ordre : modifiables à tout moment. L'unité du loyer relève de la grille tarifaire. */
    public function update(Request $request, TypeBoutique $type): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $donnees = $request->validate([
            ...$this->reglesLibres($type),
        ], [], __('admin.types.attributs'));

        return $this->puisSoumettre($request, $this->executer($type, function () use ($request, $type, $donnees) {
            $avant = $type->only(['libelle', 'description', 'ordre']);
            if ($type->par_defaut) {
                $type->fill($donnees);
                if ($type->isDirty()) {
                    $this->demander($request, $type, $type->getDirty());
                }

                return;
            }
            $type->fill($donnees)->save();
            $type->creerVersion($request->user(), __('admin.types.motifs.modification'));
            $this->journal->ecrire($request->user(), 'type_boutique.modifie', 'TypeBoutique', $type->id, $avant, $type->only(array_keys($avant)));
        }, $type->par_defaut ? 'admin.types.messages.demande' : 'admin.types.messages.modifie'), 'type', $type, route('admin.sites.types.show', $type), 'modifier-type');
    }

    /** Demande de changement d'« attribuable par tirage » : effective après validation. */
    public function demanderAttribuable(Request $request, TypeBoutique $type): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $attribuable = $request->validate(['attribuable' => ['required', 'boolean']], [], __('admin.types.attributs'))['attribuable'];

        return $this->executer($type, function () use ($request, $type, $attribuable) {
            if ((bool) $attribuable === $type->attribuable) {
                throw new RegleGestionException(__('admin.types.regles.attribuable_inchange'));
            }
            $type->forceFill(['attribuable_demande' => (bool) $attribuable, 'demande_par' => $request->user()->id, 'demande_le' => now(), 'motif_rejet' => null])->save();
            $type->creerVersion($request->user(), __('admin.types.motifs.attribuable_demande'));
            $this->journal->ecrire($request->user(), 'type_boutique.attribuable_demande', 'TypeBoutique', $type->id, ['attribuable' => $type->attribuable], ['attribuable' => (bool) $attribuable]);
        }, 'admin.types.messages.demande');
    }

    public function activer(Request $request, TypeBoutique $type): RedirectResponse
    {
        return $this->changerActivation($request, $type, true);
    }

    public function desactiver(Request $request, TypeBoutique $type): RedirectResponse
    {
        return $this->changerActivation($request, $type, false);
    }

    /** Suppression : jamais pour un type « par défaut » ni pour un type utilisé ; son code reste réservé. */
    public function destroy(Request $request, TypeBoutique $type): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        if ($type->par_defaut || $type->estUtilise()) {
            return redirect()->route('admin.sites.types')->with('erreur', __($type->par_defaut ? 'admin.types.regles.defaut' : 'admin.types.regles.utilise'));
        }
        DB::transaction(function () use ($request, $type) {
            if ($type->code !== null) {
                HistoriqueCode::create(['objet_type' => HistoriqueCode::TYPE, 'objet_id' => $type->id, 'ancien_code' => $type->code,
                    'nouveau_code' => '', 'auteur_id' => $request->user()->id, 'created_at' => now()]);
            }
            $this->journal->ecrire($request->user(), 'type_boutique.supprime', 'TypeBoutique', $type->id, ['code' => $type->code, 'libelle' => $type->libelle]);
            $type->delete();
        });

        return redirect()->route('admin.sites.types')->with('succes', __('admin.types.messages.supprime'));
    }

    // ------------------------------------------------------------------ VALIDATEUR

    /** Confirme le nouveau type, ou le changement d'« attribuable par tirage » demandé. */
    public function confirmer(Request $request, TypeBoutique $type): RedirectResponse
    {
        Gate::authorize(Droit::SitesConfirmer->value);

        return $this->executer($type, function () use ($request, $type) {
            $this->exigerAutreAuteur($request, $type);
            if ($type->statut === StatutTypeBoutique::AValider) {
                $type->forceFill(['statut' => StatutTypeBoutique::Valide, 'valide_par' => $request->user()->id, 'valide_le' => now(), 'motif_rejet' => null])->save();
                $motif = 'admin.types.motifs.confirmation';
            } elseif ($type->aModificationEnAttente()) {
                $type->appliquerModification();
                $type->forceFill(['valide_par' => $request->user()->id, 'valide_le' => now()])->save();
                $motif = 'admin.types.motifs.modification_confirmee';
            } elseif ($type->attribuable_demande !== null) {
                $type->forceFill(['attribuable' => $type->attribuable_demande, 'attribuable_demande' => null, 'valide_par' => $request->user()->id, 'valide_le' => now()])->save();
                $motif = 'admin.types.motifs.attribuable_confirme';
            } else {
                throw new RegleGestionException(__('admin.types.regles.rien_a_valider'));
            }
            $type->creerVersion($request->user(), __($motif));
            $this->journal->ecrire($request->user(), 'type_boutique.confirme', 'TypeBoutique', $type->id, null, ['statut' => $type->statut->value, 'attribuable' => $type->attribuable]);
            $this->notifier($type, 'confirmer', null);
        }, 'admin.types.messages.confirme');
    }

    public function rejeter(Request $request, TypeBoutique $type): RedirectResponse
    {
        Gate::authorize(Droit::SitesConfirmer->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];

        return $this->executer($type, function () use ($request, $type, $motif) {
            $this->exigerAutreAuteur($request, $type);
            if ($type->statut === StatutTypeBoutique::AValider) {
                $type->forceFill(['statut' => StatutTypeBoutique::Rejete, 'motif_rejet' => $motif])->save();
            } elseif ($type->aModificationEnAttente()) {
                $type->abandonnerModification($motif);
            } elseif ($type->attribuable_demande !== null) {
                $type->forceFill(['attribuable_demande' => null, 'motif_rejet' => $motif])->save();
            } else {
                throw new RegleGestionException(__('admin.types.regles.rien_a_valider'));
            }
            $type->creerVersion($request->user(), __('admin.types.motifs.rejet', ['motif' => $motif]));
            $this->journal->ecrire($request->user(), 'type_boutique.rejete', 'TypeBoutique', $type->id, null, ['motif' => $motif]);
            $this->notifier($type, 'rejeter', $motif);
        }, 'admin.types.messages.rejete');
    }

    /** Type rejeté ou retiré de la validation (brouillon) : le GESTIONNAIRE le soumet de nouveau. */
    public function soumettre(Request $request, TypeBoutique $type): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->executer($type, function () use ($request, $type) {
            if (! in_array($type->statut, [StatutTypeBoutique::Rejete, StatutTypeBoutique::Brouillon], true)) {
                throw new RegleGestionException(__($type->statut === StatutTypeBoutique::AValider ? 'admin.soumission.erreurs.deja_soumis' : 'admin.types.regles.rien_a_soumettre'));
            }
            $type->forceFill(['statut' => StatutTypeBoutique::AValider, 'demande_par' => $request->user()->id, 'demande_le' => now()])->save();
            $type->creerVersion($request->user(), __('admin.types.motifs.soumission'));
        }, 'admin.types.messages.soumis');
    }

    // ------------------------------------------------------------------ Outils

    /** Auteur de la demande prévenu, une fois la décision enregistrée. */
    private function notifier(TypeBoutique $type, string $decision, ?string $motif): void
    {
        $auteur = $type->demande_par;
        DB::afterCommit(fn () => $this->notifications->envoyer($auteur, __('admin.a_valider.elements.type'), ($type->code ? $type->code.' · ' : '').$type->libelle,
            $decision, $motif, route('admin.sites.types.show', $type)));
    }

    private function changerActivation(Request $request, TypeBoutique $type, bool $actif): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->executer($type, function () use ($request, $type, $actif) {
            if ($type->par_defaut) {
                $this->demander($request, $type, ['actif' => $actif]);

                return;
            }
            $type->forceFill(['actif' => $actif])->save();
            $type->creerVersion($request->user(), __($actif ? 'admin.types.motifs.reactivation' : 'admin.types.motifs.desactivation'));
            $this->journal->ecrire($request->user(), $actif ? 'type_boutique.reactive' : 'type_boutique.desactive', 'TypeBoutique', $type->id);
        }, $type->par_defaut ? 'admin.types.messages.demande' : ($actif ? 'admin.types.messages.reactive' : 'admin.types.messages.desactive'));
    }

    /**
     * Type « par défaut » : la modification n'est pas appliquée, elle est soumise au VALIDATEUR (« À valider »).
     *
     * @param  array<string, mixed>  $valeurs
     *
     * @throws RegleGestionException
     */
    private function demander(Request $request, TypeBoutique $type, array $valeurs): void
    {
        if ($type->aModificationEnAttente()) {
            throw new RegleGestionException(__('admin.zones.regles.modification_en_attente'));
        }
        $type->refresh();
        $type->demanderModification($valeurs, $request->user());
        $type->forceFill(['demande_le' => now()])->save();
        $this->journal->ecrire($request->user(), 'type_boutique.modification_demandee', 'TypeBoutique', $type->id, $type->only(array_keys($valeurs)), $valeurs);
    }

    /** @return array<string, list<mixed>> */
    private function reglesLibres(?TypeBoutique $type = null): array
    {
        return [
            'libelle' => ['required', 'string', 'max:80', Rule::unique('types_boutiques', 'libelle')->ignore($type?->id)],
            'description' => ['nullable', 'string', 'max:300'],
            'ordre' => [$type ? 'required' : 'nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /** Quatre yeux : la personne qui a demandé ne décide pas. @throws RegleGestionException */
    private function exigerAutreAuteur(Request $request, TypeBoutique $type): void
    {
        if ($type->demande_par === $request->user()->id) {
            throw new RegleGestionException(__('admin.parametrage.regles.quatre_yeux'));
        }
    }

    private function executer(TypeBoutique $type, Closure $action, string $message): RedirectResponse
    {
        try {
            DB::transaction($action);
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.sites.types.show', $type)->with('erreur', $e->getMessage());
        }

        return redirect()->route('admin.sites.types.show', $type)->with('succes', __($message));
    }
}
