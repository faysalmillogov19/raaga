<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\Role as RoleEnum;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Backoffice\GestionUtilisateurs;
use App\Services\Backoffice\RegleGestionException;
use App\Support\Telephone;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Onglet « Utilisateurs back-office » (SUPER_ADMIN) : comptes des 4 profils du back-office.
 * Les comptes grand public (citoyens, agents de guichet) relèvent de l'onglet « Assistance ».
 * Chaque action vérifie le droit côté serveur, en plus du middleware de la route.
 */
class UtilisateursController extends Controller
{
    private const TRIS = ['nom' => 'name', 'inscription' => 'created_at', 'connexion' => 'derniere_connexion_le'];

    public function __construct(private readonly GestionUtilisateurs $gestion) {}

    public function index(Request $request): View
    {
        Gate::authorize(Droit::UtilisateursGerer->value);

        $roles = RoleEnum::backoffice();

        $requete = User::query()
            ->whereHas('role', fn (Builder $q) => $q->whereIn('code', array_map(fn ($r) => $r->value, $roles)))
            ->when($request->filled('q'), function (Builder $q) use ($request) {
                $terme = trim((string) $request->query('q'));
                $chiffres = Telephone::chiffresLocaux($terme);
                $q->where(function (Builder $q) use ($terme, $chiffres) {
                    $q->where('name', 'like', "%{$terme}%")->orWhere('email', 'like', "%{$terme}%");
                    if (strlen($chiffres) >= 4) {
                        $q->orWhere('telephone', 'like', "%{$chiffres}%");
                    }
                });
            })
            ->when($request->filled('role') && RoleEnum::tryFrom((string) $request->query('role')), fn (Builder $q) => $q->whereHas('role', fn (Builder $r) => $r->where('code', $request->query('role'))))
            ->when($request->query('statut') === 'actif', fn (Builder $q) => $q->where('actif', true))
            ->when($request->query('statut') === 'desactive', fn (Builder $q) => $q->where('actif', false));

        $tri = array_key_exists((string) $request->query('tri'), self::TRIS) ? $request->query('tri') : 'nom';
        $sens = $request->query('sens') === 'desc' ? 'desc' : 'asc';

        return view('admin.utilisateurs.index', [
            'roles' => $roles,
            'utilisateurs' => $requete->orderBy(self::TRIS[$tri], $sens)->orderBy('id')->paginate(25)->withQueryString(),
            'tri' => $tri,
            'sens' => $sens,
            'invitationsEnAttente' => DB::table('invitations')->whereNull('utilisee_le')->whereNull('annulee_le')
                ->where('expire_le', '>', now())->pluck('user_id')->all(),
        ]);
    }

    public function show(User $utilisateur): View
    {
        Gate::authorize(Droit::UtilisateursGerer->value);
        $this->verifierBackoffice($utilisateur);

        return view('admin.utilisateurs.show', [
            'utilisateur' => $utilisateur,
            'rolesBackoffice' => RoleEnum::backoffice(),
            'invitationEnAttente' => DB::table('invitations')->where('user_id', $utilisateur->id)->whereNull('utilisee_le')
                ->whereNull('annulee_le')->where('expire_le', '>', now())->exists(),
            'connexions' => DB::table('connexions_backoffice')->where('user_id', $utilisateur->id)->orderByDesc('id')->limit(10)->get(),
            'historique' => AuditLog::query()
                ->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('entite_type', 'User')->where('entite_id', (string) $utilisateur->id))
                    ->orWhere('acteur_id', $utilisateur->id))
                ->with('acteur')
                ->orderByDesc('id')
                ->limit(20)
                ->get(),
            'estSoiMeme' => request()->user()->is($utilisateur),
        ]);
    }

    public function create(): View
    {
        Gate::authorize(Droit::UtilisateursGerer->value);

        return view('admin.utilisateurs.inviter', ['roles' => RoleEnum::backoffice()]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::UtilisateursGerer->value);

        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(array_map(fn ($r) => $r->value, RoleEnum::backoffice()))],
        ], [
            'email.unique' => __('admin.utilisateurs.erreurs.email_existe'),
        ], [
            'nom' => __('admin.utilisateurs.champs.nom'),
            'email' => __('admin.utilisateurs.champs.email'),
            'role' => __('admin.utilisateurs.champs.role'),
        ]);

        $user = $this->gestion->inviter($request->user(), $donnees['nom'], $donnees['email'], RoleEnum::from($donnees['role']));

        return redirect()->route('admin.utilisateurs.show', $user)
            ->with('succes', __('admin.utilisateurs.messages.invite', ['email' => $user->email]));
    }

    public function modifierRole(Request $request, User $utilisateur): RedirectResponse
    {
        Gate::authorize(Droit::UtilisateursGerer->value);
        $this->verifierBackoffice($utilisateur);

        $donnees = $request->validate(['role' => ['required', Rule::in(array_map(fn ($r) => $r->value, RoleEnum::backoffice()))]]);

        return $this->executer(fn () => $this->gestion->changerRole($request->user(), $utilisateur, RoleEnum::from($donnees['role'])),
            $utilisateur, 'admin.utilisateurs.messages.role');
    }

    public function desactiver(Request $request, User $utilisateur): RedirectResponse
    {
        Gate::authorize(Droit::UtilisateursGerer->value);
        $this->verifierBackoffice($utilisateur);

        $motif = $this->motif($request);

        return $this->executer(fn () => $this->gestion->desactiver($request->user(), $utilisateur, $motif),
            $utilisateur, 'admin.utilisateurs.messages.desactive');
    }

    public function reactiver(Request $request, User $utilisateur): RedirectResponse
    {
        Gate::authorize(Droit::UtilisateursGerer->value);
        $this->verifierBackoffice($utilisateur);

        $motif = $this->motif($request);

        return $this->executer(fn () => $this->gestion->reactiver($request->user(), $utilisateur, $motif),
            $utilisateur, 'admin.utilisateurs.messages.reactive');
    }

    public function reinitialiserTotp(Request $request, User $utilisateur): RedirectResponse
    {
        Gate::authorize(Droit::UtilisateursGerer->value);
        $this->verifierBackoffice($utilisateur);

        return $this->executer(fn () => $this->gestion->reinitialiserTotp($request->user(), $utilisateur),
            $utilisateur, 'admin.utilisateurs.messages.totp');
    }

    public function deverrouiller(Request $request, User $utilisateur): RedirectResponse
    {
        Gate::authorize(Droit::UtilisateursGerer->value);
        $this->verifierBackoffice($utilisateur);

        return $this->executer(fn () => $this->gestion->deverrouiller($request->user(), $utilisateur),
            $utilisateur, 'admin.utilisateurs.messages.deverrouille');
    }

    public function envoyerLien(Request $request, User $utilisateur): RedirectResponse
    {
        Gate::authorize(Droit::UtilisateursGerer->value);
        $this->verifierBackoffice($utilisateur);

        return $this->executer(fn () => $this->gestion->envoyerLien($request->user(), $utilisateur),
            $utilisateur, 'admin.utilisateurs.messages.lien');
    }

    /** Un compte grand public se gère depuis l'onglet « Assistance », jamais d'ici. */
    private function verifierBackoffice(User $utilisateur): void
    {
        abort_unless($utilisateur->estBackoffice(), 404);
    }

    private function executer(Closure $action, User $utilisateur, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.utilisateurs.show', $utilisateur)->with('erreur', $e->getMessage());
        }

        return redirect()->route('admin.utilisateurs.show', $utilisateur)->with('succes', __($message));
    }

    private function motif(Request $request): string
    {
        return $request->validate(
            ['motif' => ['required', 'string', 'min:5', 'max:500']],
            ['motif.*' => __('admin.utilisateurs.erreurs.motif')],
        )['motif'];
    }
}
