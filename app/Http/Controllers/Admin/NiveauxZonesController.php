<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\StatutTypeBoutique;
use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\Niveau;
use App\Models\ZoneSite;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Niveaux et zones, deux référentiels gérés comme les types de boutiques. GESTIONNAIRE DES SITES ET BOUTIQUES : ajoute
 * (soumis au VALIDATEUR), modifie le libellé, la description, le rang ou l'ordre à tout moment, désactive ou réactive,
 * supprime un élément jamais utilisé et non « par défaut ». Code jamais modifié. VALIDATEUR : valide ou rejette.
 * Autres profils : lecture seule. « {liste} » vaut « niveaux » ou « zones ».
 */
class NiveauxZonesController extends Controller
{
    /** Liste => modèle. */
    private const LISTES = ['niveaux' => Niveau::class, 'zones' => ZoneSite::class];

    public function __construct(private readonly Journal $journal) {}

    public function index(): View
    {
        Gate::authorize(Droit::SitesVoir->value);
        $parNiveau = Boutique::query()->whereNotNull('niveau')->selectRaw('niveau, count(*) as n')->groupBy('niveau')->pluck('n', 'niveau');
        $parBloc = Boutique::query()->whereNotNull('bloc')->selectRaw('bloc, count(*) as n')->groupBy('bloc')->pluck('n', 'bloc');

        return view('admin.zones.index', [
            'niveaux' => Niveau::query()->orderBy('rang')->orderBy('code')->get()
                ->each(fn (Niveau $n) => $n->setAttribute('boutiques_count', (int) ($parNiveau[$n->code] ?? 0))),
            'zones' => ZoneSite::query()->orderBy('ordre')->orderBy('code')->get()
                ->each(fn (ZoneSite $z) => $z->setAttribute('boutiques_count', (int) ($parBloc[$z->code] ?? 0))),
        ]);
    }

    /** Nouvel élément : utilisable seulement après validation. */
    public function store(Request $request, string $liste): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $classe = $this->classe($liste);
        if (is_string($request->input('code'))) {
            $request->merge(['code' => mb_strtoupper(trim($request->input('code')))]);
        }
        $donnees = $request->validate([
            'code' => ['required', 'string', 'regex:'.$classe::FORMAT_CODE, Rule::unique((new $classe)->getTable(), 'code')],
            ...$this->reglesLibres($liste),
        ], [], __('admin.zones.attributs'));

        $element = DB::transaction(function () use ($request, $classe, $donnees) {
            $element = $classe::create([...$donnees, 'actif' => true, 'par_defaut' => false, 'statut' => StatutTypeBoutique::AValider]);
            $element->forceFill(['demande_par' => $request->user()->id])->save();
            $element->creerVersion($request->user(), __('admin.types.motifs.creation'));

            return $element;
        });
        $this->journal->ecrire($request->user(), $classe::typeReferentiel().'.cree', class_basename($classe), $element->id, null, $donnees);

        return $this->retour('admin.zones.messages.creee');
    }

    /** Libellé, description, rang ou ordre : à tout moment, sans validation. */
    public function update(Request $request, string $liste, int $id): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $element = $this->element($liste, $id);
        $donnees = $request->validate($this->reglesLibres($liste), [], __('admin.zones.attributs'));

        return $this->executer(function () use ($request, $element, $donnees) {
            $avant = $element->only(array_keys($donnees));
            $element->fill($donnees);
            if (! $element->isDirty()) {
                return;
            }
            $element->save();
            $element->creerVersion($request->user(), __('admin.types.motifs.modification'));
            $this->journal->ecrire($request->user(), $element::typeReferentiel().'.modifie', class_basename($element), $element->id, $avant, $element->only(array_keys($donnees)));
        }, 'admin.zones.messages.renommee');
    }

    public function activer(Request $request, string $liste, int $id): RedirectResponse
    {
        return $this->activation($request, $this->element($liste, $id), true);
    }

    public function desactiver(Request $request, string $liste, int $id): RedirectResponse
    {
        return $this->activation($request, $this->element($liste, $id), false);
    }

    /** Suppression : jamais pour un élément « par défaut » ni pour un élément utilisé. */
    public function destroy(Request $request, string $liste, int $id): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $element = $this->element($liste, $id);

        return $this->executer(function () use ($request, $element) {
            if ($element->par_defaut || $element->nombreUtilisations() > 0) {
                throw new RegleGestionException(__($element->par_defaut ? 'admin.zones.regles.defaut' : 'admin.zones.regles.utilisee'));
            }
            $this->journal->ecrire($request->user(), $element::typeReferentiel().'.supprime', class_basename($element), $element->id, ['code' => $element->code, 'libelle' => $element->libelle]);
            $element->delete();
        }, 'admin.zones.messages.supprimee');
    }

    public function confirmer(Request $request, string $liste, int $id): RedirectResponse
    {
        Gate::authorize(Droit::SitesConfirmer->value);
        $element = $this->element($liste, $id);

        return $this->executer(function () use ($request, $element) {
            $this->exigerEnAttente($request, $element);
            $element->forceFill(['statut' => StatutTypeBoutique::Valide, 'valide_par' => $request->user()->id, 'valide_le' => now(), 'motif_rejet' => null])->save();
            $element->creerVersion($request->user(), __('admin.types.motifs.confirmation'));
            $this->journal->ecrire($request->user(), $element::typeReferentiel().'.confirme', class_basename($element), $element->id);
        }, 'admin.zones.messages.confirmee');
    }

    public function rejeter(Request $request, string $liste, int $id): RedirectResponse
    {
        Gate::authorize(Droit::SitesConfirmer->value);
        $element = $this->element($liste, $id);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];

        return $this->executer(function () use ($request, $element, $motif) {
            $this->exigerEnAttente($request, $element);
            $element->forceFill(['statut' => StatutTypeBoutique::Rejete, 'motif_rejet' => $motif])->save();
            $element->creerVersion($request->user(), __('admin.types.motifs.rejet', ['motif' => $motif]));
            $this->journal->ecrire($request->user(), $element::typeReferentiel().'.rejete', class_basename($element), $element->id, null, ['motif' => $motif]);
        }, 'admin.zones.messages.rejetee');
    }

    private function activation(Request $request, Model $element, bool $actif): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->executer(function () use ($request, $element, $actif) {
            $element->forceFill(['actif' => $actif])->save();
            $element->creerVersion($request->user(), __($actif ? 'admin.types.motifs.reactivation' : 'admin.types.motifs.desactivation'));
            $this->journal->ecrire($request->user(), $element::typeReferentiel().($actif ? '.reactive' : '.desactive'), class_basename($element), $element->id);
        }, $actif ? 'admin.zones.messages.reactivee' : 'admin.zones.messages.desactivee');
    }

    /** @return array<string, list<mixed>> */
    private function reglesLibres(string $liste): array
    {
        return $liste === 'niveaux'
            ? ['libelle' => ['required', 'string', 'max:80'], 'rang' => ['required', 'integer', 'min:-9', 'max:99']]
            : ['libelle' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:300'], 'ordre' => ['nullable', 'integer', 'min:0', 'max:9999']];
    }

    /** @return class-string<Niveau|ZoneSite> */
    private function classe(string $liste): string
    {
        return self::LISTES[$liste] ?? abort(404);
    }

    private function element(string $liste, int $id): Niveau|ZoneSite
    {
        return $this->classe($liste)::query()->findOrFail($id);
    }

    /** @throws RegleGestionException */
    private function exigerEnAttente(Request $request, Niveau|ZoneSite $element): void
    {
        if ($element->statut !== StatutTypeBoutique::AValider) {
            throw new RegleGestionException(__('admin.zones.regles.rien_a_valider'));
        }
        if ($element->demande_par === $request->user()->id) {
            throw new RegleGestionException(__('admin.parametrage.regles.quatre_yeux'));
        }
    }

    private function executer(Closure $action, string $message): RedirectResponse
    {
        try {
            DB::transaction($action);
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.referencement.zones')->with('erreur', $e->getMessage());
        }

        return $this->retour($message);
    }

    private function retour(string $message): RedirectResponse
    {
        return redirect()->route('admin.referencement.zones')->with('succes', __($message));
    }
}
