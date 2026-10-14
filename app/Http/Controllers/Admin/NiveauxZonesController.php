<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\NiveauBoutique;
use App\Enums\StatutTypeBoutique;
use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\CapaciteSite;
use App\Models\ZoneSite;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Niveaux (fixes, lecture seule) et zones des sites. GESTIONNAIRE DES SITES ET BOUTIQUES : ajoute une zone (confirmée
 * par le VALIDATEUR), la renomme, la désactive ou la réactive, supprime une zone jamais utilisée et non « par défaut ».
 * Le code d'une zone ne change jamais. Autres profils : lecture seule.
 */
class NiveauxZonesController extends Controller
{
    public function __construct(private readonly Journal $journal) {}

    public function index(): View
    {
        Gate::authorize(Droit::SitesVoir->value);
        $capacitesParZone = CapaciteSite::query()->whereNotNull('zone')->selectRaw('zone, count(*) as n')->groupBy('zone')->pluck('n', 'zone');

        return view('admin.zones.index', [
            'niveaux' => collect(NiveauBoutique::cases())->map(fn (NiveauBoutique $n) => [
                'niveau' => $n, 'boutiques' => Boutique::query()->where('niveau', $n)->count(),
            ]),
            'zones' => ZoneSite::query()->orderBy('id')->get()->each(fn (ZoneSite $z) => $z->setAttribute('capacites_count', (int) ($capacitesParZone[$z->code] ?? 0))),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        if (is_string($request->input('code'))) {
            $request->merge(['code' => mb_strtoupper(trim($request->input('code')))]);
        }
        $donnees = $request->validate([
            'code' => ['required', 'string', 'regex:'.ZoneSite::FORMAT_CODE, Rule::unique('zones_sites', 'code')],
            'libelle' => ['required', 'string', 'max:100'],
        ], [], __('admin.zones.attributs'));

        $zone = ZoneSite::create([...$donnees, 'actif' => true, 'par_defaut' => false, 'statut' => StatutTypeBoutique::AValider]);
        $zone->forceFill(['demande_par' => $request->user()->id])->save();
        $this->journal->ecrire($request->user(), 'zone.creee', 'ZoneSite', $zone->id, null, $donnees);

        return redirect()->route('admin.referencement.zones')->with('succes', __('admin.zones.messages.creee'));
    }

    public function update(Request $request, ZoneSite $zone): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $libelle = $request->validate(['libelle' => ['required', 'string', 'max:100']], [], __('admin.zones.attributs'))['libelle'];

        return $this->executer(function () use ($request, $zone, $libelle) {
            $avant = $zone->libelle;
            $zone->forceFill(['libelle' => $libelle])->save();
            $this->journal->ecrire($request->user(), 'zone.renommee', 'ZoneSite', $zone->id, ['libelle' => $avant], ['libelle' => $libelle]);
        }, 'admin.zones.messages.renommee');
    }

    public function activer(Request $request, ZoneSite $zone): RedirectResponse
    {
        return $this->activation($request, $zone, true);
    }

    public function desactiver(Request $request, ZoneSite $zone): RedirectResponse
    {
        return $this->activation($request, $zone, false);
    }

    public function destroy(Request $request, ZoneSite $zone): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->executer(function () use ($request, $zone) {
            if ($zone->par_defaut || $zone->nombreCapacites() > 0) {
                throw new RegleGestionException(__($zone->par_defaut ? 'admin.zones.regles.defaut' : 'admin.zones.regles.utilisee'));
            }
            $this->journal->ecrire($request->user(), 'zone.supprimee', 'ZoneSite', $zone->id, ['code' => $zone->code, 'libelle' => $zone->libelle]);
            $zone->delete();
        }, 'admin.zones.messages.supprimee');
    }

    public function confirmer(Request $request, ZoneSite $zone): RedirectResponse
    {
        Gate::authorize(Droit::SitesConfirmer->value);

        return $this->executer(function () use ($request, $zone) {
            $this->exigerEnAttente($request, $zone);
            $zone->forceFill(['statut' => StatutTypeBoutique::Valide, 'valide_par' => $request->user()->id, 'valide_le' => now(), 'motif_rejet' => null])->save();
            $this->journal->ecrire($request->user(), 'zone.confirmee', 'ZoneSite', $zone->id);
        }, 'admin.zones.messages.confirmee');
    }

    public function rejeter(Request $request, ZoneSite $zone): RedirectResponse
    {
        Gate::authorize(Droit::SitesConfirmer->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];

        return $this->executer(function () use ($request, $zone, $motif) {
            $this->exigerEnAttente($request, $zone);
            $zone->forceFill(['statut' => StatutTypeBoutique::Rejete, 'motif_rejet' => $motif])->save();
            $this->journal->ecrire($request->user(), 'zone.rejetee', 'ZoneSite', $zone->id, null, ['motif' => $motif]);
        }, 'admin.zones.messages.rejetee');
    }

    private function activation(Request $request, ZoneSite $zone, bool $actif): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->executer(function () use ($request, $zone, $actif) {
            $zone->forceFill(['actif' => $actif])->save();
            $this->journal->ecrire($request->user(), $actif ? 'zone.reactivee' : 'zone.desactivee', 'ZoneSite', $zone->id);
        }, $actif ? 'admin.zones.messages.reactivee' : 'admin.zones.messages.desactivee');
    }

    /** @throws RegleGestionException */
    private function exigerEnAttente(Request $request, ZoneSite $zone): void
    {
        if ($zone->statut !== StatutTypeBoutique::AValider) {
            throw new RegleGestionException(__('admin.zones.regles.rien_a_valider'));
        }
        if ($zone->demande_par === $request->user()->id) {
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

        return redirect()->route('admin.referencement.zones')->with('succes', __($message));
    }
}
