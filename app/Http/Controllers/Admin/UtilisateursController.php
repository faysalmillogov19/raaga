<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\Role as RoleEnum;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Backoffice\GestionUtilisateurs;
use App\Services\Backoffice\RegleGestionException;
use App\Support\Tableau\Colonne;
use App\Support\Tableau\Filtre;
use App\Support\Tableau\ResultatTableau;
use App\Support\Tableau\Tableau;
use App\Support\Telephone;
use Closure;
use Illuminate\Database\Eloquent\Builder;
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
 * Onglet « Utilisateurs back-office » (SUPER_ADMIN) : comptes des 4 profils du back-office.
 * Les comptes grand public (citoyens, agents de guichet) relèvent de l'onglet « Assistance ».
 * Chaque action vérifie le droit côté serveur, en plus du middleware de la route.
 */
class UtilisateursController extends Controller
{
    public function __construct(private readonly GestionUtilisateurs $gestion) {}

    public function index(Request $request): Response|StreamedResponse|View
    {
        Gate::authorize(Droit::UtilisateursGerer->value);
        $u = __('admin.utilisateurs');
        $c = $u['colonnes'];
        $roles = RoleEnum::backoffice();
        $invitations = null; // Invitations en attente, lues une seule fois.
        $statut = function (User $x) use (&$invitations) {
            $invitations ??= DB::table('invitations')->whereNull('utilisee_le')->whereNull('annulee_le')->where('expire_le', '>', now())->pluck('user_id')->all();

            return new HtmlString(view('admin.utilisateurs._statut', ['u' => $x, 'invitation' => in_array($x->id, $invitations, true)])->render());
        };

        return Tableau::pour(User::query()->with('role')->whereHas('role', fn (Builder $q) => $q->whereIn('code', array_map(fn ($r) => $r->value, $roles))), 'utilisateurs')
            ->colonnes([
                Colonne::make('nom', $c['nom'])->triable('name')
                    ->recherchable(function (Builder $q, string $terme) {
                        $q->whereContient('name', $terme)->orWhereContient('email', $terme);
                        if (strlen($chiffres = Telephone::chiffresLocaux($terme)) >= 4) {
                            $q->orWhereContient('telephone', $chiffres);
                        }
                    })
                    ->valeur(fn (User $x) => new HtmlString('<a href="'.e(route('admin.utilisateurs.show', $x)).'" class="font-bold text-vert-fonce underline underline-offset-2">'.e($x->name).'</a>'))
                    ->texte(fn (User $x) => $x->name),
                Colonne::make('email', $c['email'])->triable(),
                Colonne::make('role', $c['role'])
                    ->valeur(fn (User $x) => new HtmlString('<span class="bo-badge '.e($x->codeRole()->badgeClasse()).'">'.e($x->codeRole()->badge()).'</span>'))
                    ->texte(fn (User $x) => $x->codeRole()->label()),
                Colonne::make('statut', $c['statut'])->valeur($statut),
                Colonne::make('totp', $c['totp'])->valeur(fn (User $x) => $x->totpActive() ? $u['totp_active'] : $u['totp_inactive']),
                Colonne::make('connexion', $c['connexion'])->triable('derniere_connexion_le')->classe('whitespace-nowrap')
                    ->valeur(fn (User $x) => $x->derniere_connexion_le?->format('d/m/Y H:i') ?? $u['jamais']),
            ])
            ->filtres([
                Filtre::make('role', $c['role'], collect($roles)->mapWithKeys(fn ($r) => [$r->value => $r->label()])->all(),
                    fn (Builder $q, string $v) => $q->whereHas('role', fn (Builder $r) => $r->where('code', $v))),
                Filtre::make('statut', $c['statut'], ['actif' => $u['statuts']['actif'], 'desactive' => $u['statuts']['desactive']],
                    fn (Builder $q, string $v) => $q->where('actif', $v === 'actif')),
            ])
            ->triParDefaut('nom')
            ->export('Raaga_utilisateurs-backoffice')
            ->repondre($request, fn (ResultatTableau $t) => view('admin.utilisateurs.index', ['tableau' => $t, 'roles' => $roles]));
    }

    public function show(User $utilisateur): View
    {
        Gate::authorize(Droit::UtilisateursGerer->value);
        $this->verifierBackoffice($utilisateur);

        return view('admin.utilisateurs.show', [
            'utilisateur' => $utilisateur,
            'rolesBackoffice' => RoleEnum::attribuablesBackoffice(),
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

    /** Ancienne adresse de l'écran d'invitation : la liste, avec la fenêtre d'invitation ouverte. */
    public function create(): RedirectResponse
    {
        Gate::authorize(Droit::UtilisateursGerer->value);

        return redirect()->route('admin.utilisateurs', ['inviter' => 1]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::UtilisateursGerer->value);

        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(array_map(fn ($r) => $r->value, RoleEnum::attribuablesBackoffice()))],
        ], [
            'email.unique' => __('admin.utilisateurs.erreurs.email_existe'),
        ], [
            'nom' => __('admin.utilisateurs.champs.nom'),
            'email' => __('admin.utilisateurs.champs.email'),
            'role' => __('admin.utilisateurs.champs.role'),
        ]);

        $user = $this->gestion->inviter($request->user(), $donnees['nom'], $donnees['email'], RoleEnum::from($donnees['role']));

        return redirect()->route('admin.utilisateurs.show', $user)
            ->avecSucces('admin.utilisateurs.messages.invite', ['email' => $user->email]);
    }

    public function modifierRole(Request $request, User $utilisateur): RedirectResponse
    {
        Gate::authorize(Droit::UtilisateursGerer->value);
        $this->verifierBackoffice($utilisateur);

        $donnees = $request->validate(['role' => ['required', Rule::in(array_map(fn ($r) => $r->value, RoleEnum::attribuablesBackoffice()))]]);

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

        return redirect()->route('admin.utilisateurs.show', $utilisateur)->avecSucces($message);
    }

    private function motif(Request $request): string
    {
        return $request->validate(
            ['motif' => ['required', 'string', 'min:5', 'max:500']],
            ['motif.*' => __('admin.utilisateurs.erreurs.motif')],
        )['motif'];
    }
}
