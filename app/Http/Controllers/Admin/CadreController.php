<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Barre du haut du back-office : thème (clair ou Forêt) mémorisé par utilisateur, notifications marquées comme lues,
 * recherche globale limitée à ce que le profil peut voir.
 */
class CadreController extends Controller
{
    public const THEMES = ['clair', 'foret'];

    public function theme(Request $request): JsonResponse|RedirectResponse
    {
        $theme = $request->validate(['theme' => ['required', 'in:'.implode(',', self::THEMES)]])['theme'];
        $request->user()->forceFill(['theme_backoffice' => $theme])->save();

        return $request->expectsJson() ? response()->json(['theme' => $theme]) : back()->with('succes', __('admin.cadre.theme_enregistre'));
    }

    /** Menu dépliant de la barre latérale ouvert ou fermé : mémorisé pour l'utilisateur. */
    public function menu(Request $request): JsonResponse
    {
        $donnees = $request->validate(['menu' => ['required', 'string', 'in:referentiels,imports,a_valider'], 'ouvert' => ['required', 'boolean']]);
        $user = $request->user();
        $user->forceFill(['menus_backoffice' => [...($user->menus_backoffice ?? []), $donnees['menu'] => (bool) $donnees['ouvert']]])->save();

        return response()->json(['ouvert' => (bool) $donnees['ouvert']]);
    }

    public function notificationsLues(Request $request): JsonResponse|RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return $request->expectsJson() ? response()->json(['non_lues' => 0]) : back();
    }

    /** Sites, boutiques et comptes back-office : seulement les rubriques permises au profil. */
    public function recherche(Request $request): View
    {
        $q = mb_substr(trim((string) (is_scalar($request->query('q')) ? $request->query('q') : '')), 0, 100);
        $resultats = [];

        if ($q !== '' && Gate::allows(Droit::SitesVoir->value)) {
            $resultats['sites'] = Site::query()->where(fn ($r) => $r->whereContient('code', $q)->orWhereContient('nom', $q)->orWhereContient('ville', $q))
                ->orderBy('code')->limit(10)->get()
                ->map(fn (Site $s) => ['libelle' => ($s->code ? $s->code.' · ' : '').$s->nom, 'detail' => $s->statut->label(), 'url' => route('admin.sites.show', $s)]);
            $resultats['boutiques'] = Boutique::query()->with('site')->where(fn ($r) => $r->whereContient('code', $q)->orWhereContient('numero', $q))
                ->orderBy('code')->limit(10)->get()
                ->map(fn (Boutique $b) => ['libelle' => $b->reference(), 'detail' => $b->site->nom, 'url' => route('admin.referencement.boutiques.show', $b)]);
        }
        if ($q !== '' && Gate::allows(Droit::UtilisateursGerer->value)) {
            $resultats['utilisateurs'] = User::query()->whereHas('role', fn ($r) => $r->whereIn('code', array_map(fn ($x) => $x->value, Role::backoffice())))
                ->where(fn ($r) => $r->whereContient('name', $q)->orWhereContient('email', $q))->orderBy('name')->limit(10)->get()
                ->map(fn (User $u) => ['libelle' => $u->name, 'detail' => $u->email, 'url' => route('admin.utilisateurs.show', $u)]);
        }

        return view('admin.recherche', ['q' => $q, 'resultats' => array_filter($resultats, fn ($r) => $r->isNotEmpty())]);
    }
}
