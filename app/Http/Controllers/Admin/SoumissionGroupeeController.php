<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Validation\SoumissionGroupee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Listes de gestion : contrôle préalable, soumission groupée à validation et retrait de la validation.
 * Chaque élément exige le droit de son auteur (GESTIONNAIRE ou RÉGULATEUR selon l'entité) : sinon 403, sans rien traiter.
 */
class SoumissionGroupeeController extends Controller
{
    public function __construct(private readonly SoumissionGroupee $soumission) {}

    public function controler(Request $request): JsonResponse
    {
        return response()->json(['elements' => $this->soumission->controler($request, $this->elements($request))]);
    }

    public function soumettre(Request $request): RedirectResponse
    {
        $elements = $this->elements($request);
        $commentaire = $request->validate(['commentaire' => ['nullable', 'string', 'max:1000']])['commentaire'] ?? null;

        return $this->resultat($this->soumission->soumettre($request, $elements, $commentaire), 'soumis');
    }

    public function retirer(Request $request): RedirectResponse
    {
        return $this->resultat($this->soumission->retirer($request, $this->elements($request)), 'retires');
    }

    /** @return list<array{0: string, 1: int}> */
    private function elements(Request $request): array
    {
        $cles = $request->validate([
            'elements' => ['required', 'array', 'min:1', 'max:200'],
            'elements.*' => ['required', 'string', 'regex:/^[a-z]+:\d+$/'],
        ], ['elements.*' => __('admin.soumission.erreurs.selection')])['elements'];

        $elements = [];
        foreach (array_unique($cles) as $cle) {
            [$entite, $id] = explode(':', $cle);
            abort_unless(SoumissionGroupee::peut($request->user(), $entite), 403);
            $elements[] = [$entite, (int) $id];
        }

        return $elements;
    }

    /** « 3 soumis, 1 refusé : SAR, aucune boutique. » */
    private function resultat(array $r, string $action): RedirectResponse
    {
        // Tout soumis : « n éléments soumis au VALIDATEUR. »
        if ($action === 'soumis' && $r['refus'] === [] && $r['soumis'] !== []) {
            return back()->with('succes', trans_choice('admin.soumission.tous_soumis', count($r['soumis']), ['n' => count($r['soumis'])]).'.')
                ->with('succes_titre', __('public.popups.titres_succes.soumis'));
        }
        $message = trans_choice('admin.soumission.resultat.'.$action, count($r['soumis']), ['n' => count($r['soumis'])]);
        if ($r['refus'] !== []) {
            $message .= ', '.trans_choice('admin.soumission.resultat.refus', count($r['refus']), ['n' => count($r['refus'])]).' : '
                .collect($r['refus'])->map(fn ($x) => $x['libelle'].', '.rtrim(lcfirst($x['raison']), '.'))->implode(' ; ');
        }

        return back()->with($r['soumis'] === [] ? 'erreur' : 'succes', $message.'.');
    }
}
