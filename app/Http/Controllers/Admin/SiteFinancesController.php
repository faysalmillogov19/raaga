<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\StatutGrilleTarifaire;
use App\Http\Controllers\Admin\Concerns\EnregistreEtSoumet;
use App\Http\Controllers\Controller;
use App\Models\ParametrageDecision;
use App\Models\Site;
use App\Models\SiteFinance;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Parametrage\WorkflowParametrage;
use App\Support\FicheSite;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Données financières d'un site (coût total, part attendue des occupants, source du complément) : RÉGULATEUR saisit et
 * soumet, VALIDATEUR confirme ou rejette (workflow commun, versions figées). Le GESTIONNAIRE DES SITES ET BOUTIQUES n'y a
 * jamais accès (403) : les droits « boutiques » (campagnes et grilles) gouvernent cet écran.
 */
class SiteFinancesController extends Controller
{
    use EnregistreEtSoumet;

    public function __construct(private readonly WorkflowParametrage $workflow, private readonly Journal $journal) {}

    public function show(Site $site): View
    {
        Gate::authorize(Droit::BoutiquesVoir->value);
        $finances = $site->finances;
        $versions = $finances?->versions()->with('auteur')->get() ?? collect();
        $precedente = $versions->count() > 1 ? $versions->get($versions->count() - 2) : null;

        return view('admin.sites.finances', [
            'site' => $site,
            'finances' => $finances,
            'versions' => $versions->reverse()->values(),
            'decisions' => $finances ? ParametrageDecision::query()->where('objet_type', $finances->typeParametrage())->where('objet_id', $finances->id)
                ->with('decideur')->orderByDesc('id')->get() : collect(),
            'differentiel' => $finances?->estSoumis() && $versions->isNotEmpty()
                ? WorkflowParametrage::differentiel($precedente?->parametres ?? [], $versions->last()->parametres) : [],
            'premiereVersion' => $precedente === null,
        ]);
    }

    public function update(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);
        $donnees = $request->validate([
            'cout_total' => ['nullable', 'integer', 'min:0', 'max:999999999999999'],
            'part_occupants' => ['nullable', 'integer', 'min:0', 'max:999999999999999',
                fn (string $a, mixed $v, Closure $echec) => FicheSite::partValide($v, $request->input('cout_total')) ?: $echec(__('admin.sites.erreurs.part_cout'))],
            'source_complement' => ['nullable', 'string', 'max:200'],
        ], [], __('admin.sites.champs'));

        return $this->puisSoumettre($request, $this->executer($site, function () use ($request, $site, $donnees) {
            $finances = $site->finances ?? new SiteFinance(['site_id' => $site->id, 'statut' => StatutGrilleTarifaire::Brouillon]);
            if ($finances->exists && ! $finances->estModifiable()) {
                throw new RegleGestionException(__('admin.parametrage.regles.non_modifiable'));
            }
            $avant = $finances->only(SiteFinance::PARAMETRES);
            $finances->fill($donnees)->save();
            $this->journal->ecrire($request->user(), 'site_finance.modifiee', 'SiteFinance', $finances->id, $avant, $donnees);
        }, 'admin.sites.finances.enregistrees'), 'finances', $site, route('admin.sites.finances', $site), 'modifier-finances');
    }

    public function soumettre(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);

        return $this->executer($site, fn () => $this->workflow->soumettre($this->finances($site), $request->user()), 'admin.parametrage.messages.soumis');
    }

    public function confirmer(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesConfirmer->value);

        return $this->executer($site, fn () => $this->workflow->confirmer($this->finances($site), $request->user()), 'admin.parametrage.messages.confirme');
    }

    public function rejeter(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesConfirmer->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];

        return $this->executer($site, fn () => $this->workflow->rejeter($this->finances($site), $request->user(), $motif), 'admin.parametrage.messages.rejete');
    }

    /** Données confirmées remises en brouillon pour être corrigées, puis soumises de nouveau. */
    public function reviser(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);

        return $this->executer($site, function () use ($request, $site) {
            $finances = $this->finances($site);
            if ($finances->statut !== StatutGrilleTarifaire::Confirme) {
                throw new RegleGestionException(__('admin.grilles.regles.revision_confirmee'));
            }
            $finances->forceFill(['statut' => StatutGrilleTarifaire::Brouillon])->save();
            $this->journal->ecrire($request->user(), 'site_finance.revision', 'SiteFinance', $finances->id, ['statut' => 'CONFIRME'], ['statut' => 'BROUILLON']);
        }, 'admin.grilles.messages.revision');
    }

    /** @throws RegleGestionException */
    private function finances(Site $site): SiteFinance
    {
        return $site->finances ?? throw new RegleGestionException(__('admin.sites.finances.vides'));
    }

    private function executer(Site $site, Closure $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.sites.finances', $site)->with('erreur', $e->getMessage());
        }

        return redirect()->route('admin.sites.finances', $site)->with('succes', __($message));
    }
}
