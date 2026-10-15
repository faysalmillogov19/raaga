<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\ImportValidation;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Imports\ValidationImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Détail d'un import (résumé par site, niveau et type, écarts de capacités, avertissements, fichier, sites) et
 * décision du VALIDATEUR sur l'import entier : « Valider l'import » ou « Rejeter l'import » (motif obligatoire).
 */
class ImportsValidationController extends Controller
{
    public function __construct(private readonly ValidationImport $validation) {}

    public function show(ImportValidation $import): View
    {
        Gate::authorize(Droit::ImportsVoir->value);

        return view('admin.imports-validation.show', ['import' => $import->load(['sites.site', 'auteur', 'decideur'])]);
    }

    public function fichier(ImportValidation $import): StreamedResponse
    {
        Gate::authorize(Droit::ImportsVoir->value);
        $disque = Storage::disk(config('raaga.'.ImportsController::ACTIONS[ImportsController::ENTITES[$import->entite]].'.disque'));
        abort_unless($import->chemin && $disque->exists($import->chemin), 404);

        return $disque->download($import->chemin, $import->fichier);
    }

    public function confirmer(Request $request, ImportValidation $import): RedirectResponse
    {
        Gate::authorize(Droit::SitesConfirmer->value);

        return $this->decider($request, $import, 'confirmer', null);
    }

    public function rejeter(Request $request, ImportValidation $import): RedirectResponse
    {
        Gate::authorize(Droit::SitesConfirmer->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];

        return $this->decider($request, $import, 'rejeter', $motif);
    }

    private function decider(Request $request, ImportValidation $import, string $decision, ?string $motif): RedirectResponse
    {
        $retour = redirect()->route('admin.imports-validation.show', $import);
        try {
            $message = $this->validation->decider($request, $import, $decision, $motif);
        } catch (RegleGestionException $e) {
            return $retour->with('erreur', $e->getMessage());
        }

        return $retour->with('succes', $message);
    }
}
