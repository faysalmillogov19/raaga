<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\Role as RoleEnum;
use App\Enums\StatutKyc;
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
use Illuminate\View\View;

/**
 * Onglet « Assistance » (ADMIN, SUPER_ADMIN) : comptes grand public, c'est-à-dire les citoyens
 * souscripteurs et les agents de guichet. Lecture de la fiche, sans aucune pièce d'identité ;
 * actions de support avec motif obligatoire, inscrites au journal d'audit.
 */
class AssistanceController extends Controller
{
    private const TRIS = ['nom' => 'name', 'inscription' => 'created_at'];

    /** Type de compte (filtre) → rôle. */
    private const TYPES = ['citoyen' => RoleEnum::Citoyen, 'agent' => RoleEnum::AgentGuichet];

    public function __construct(private readonly GestionUtilisateurs $gestion) {}

    public function index(Request $request): View
    {
        Gate::authorize(Droit::AssistanceVoir->value);

        $type = self::TYPES[(string) $request->query('type')] ?? null;
        $roles = $type ? [$type] : RoleEnum::grandPublic();

        $requete = self::grandPublic($roles)
            ->when($request->filled('q'), function (Builder $q) use ($request) {
                $terme = trim((string) $request->query('q'));
                $chiffres = Telephone::chiffresLocaux($terme);
                $q->where(function (Builder $q) use ($terme, $chiffres) {
                    $q->whereContient('name', $terme)->orWhereContient('nom', $terme)->orWhereContient('prenoms', $terme);
                    if (strlen($chiffres) >= 4) {
                        $q->orWhereContient('telephone', $chiffres);
                    }
                });
            })
            ->when($request->query('statut') === 'actif', fn (Builder $q) => $q->where('actif', true))
            ->when($request->query('statut') === 'desactive', fn (Builder $q) => $q->where('actif', false))
            ->when(StatutKyc::tryFrom((string) $request->query('kyc')), fn (Builder $q) => $q->where('statut_kyc', $request->query('kyc')));

        $tri = array_key_exists((string) $request->query('tri'), self::TRIS) ? $request->query('tri') : 'inscription';
        $sens = $request->query('sens') === 'asc' ? 'asc' : 'desc';

        return view('admin.assistance.index', [
            'utilisateurs' => $requete->orderBy(self::TRIS[$tri], $sens)->orderBy('id')->paginate(25)->withQueryString(),
            'total' => self::grandPublic(RoleEnum::grandPublic())->count(),
            'tri' => $tri,
            'sens' => $sens,
        ]);
    }

    public function show(User $utilisateur): View
    {
        Gate::authorize(Droit::AssistanceVoir->value);
        $this->verifierGrandPublic($utilisateur);

        return view('admin.assistance.show', [
            'utilisateur' => $utilisateur,
            'souscriptions' => $utilisateur->souscriptions()
                ->selectRaw('COUNT(*) AS nombre, COALESCE(SUM(montant), 0) AS montant')->first(),
            'acceptations' => DB::table('acceptations')->where('user_id', $utilisateur->id)->orderByDesc('accepte_le')->get(),
            'historique' => AuditLog::query()
                ->where('entite_type', 'User')->where('entite_id', (string) $utilisateur->id)
                ->with('acteur')->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function desactiver(Request $request, User $utilisateur): RedirectResponse
    {
        Gate::authorize(Droit::AssistanceAgir->value);
        $this->verifierGrandPublic($utilisateur);

        $motif = $this->motif($request);

        return $this->executer(fn () => $this->gestion->desactiver($request->user(), $utilisateur, $motif),
            $utilisateur, 'admin.utilisateurs.messages.desactive');
    }

    public function reactiver(Request $request, User $utilisateur): RedirectResponse
    {
        Gate::authorize(Droit::AssistanceAgir->value);
        $this->verifierGrandPublic($utilisateur);

        $motif = $this->motif($request);

        return $this->executer(fn () => $this->gestion->reactiver($request->user(), $utilisateur, $motif),
            $utilisateur, 'admin.utilisateurs.messages.reactive');
    }

    /**
     * @param  list<RoleEnum>  $roles
     * @return Builder<User>
     */
    private static function grandPublic(array $roles): Builder
    {
        return User::query()->whereHas('role', fn (Builder $q) => $q->whereIn('code', array_map(fn (RoleEnum $r) => $r->value, $roles)));
    }

    /** L'assistance ne voit que les comptes grand public : jamais un compte back-office. */
    private function verifierGrandPublic(User $utilisateur): void
    {
        abort_unless(in_array($utilisateur->codeRole(), RoleEnum::grandPublic(), true), 404);
    }

    private function executer(Closure $action, User $utilisateur, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.assistance.show', $utilisateur)->with('erreur', $e->getMessage());
        }

        return redirect()->route('admin.assistance.show', $utilisateur)->with('succes', __($message));
    }

    private function motif(Request $request): string
    {
        return $request->validate(
            ['motif' => ['required', 'string', 'min:5', 'max:500']],
            ['motif.*' => __('admin.utilisateurs.erreurs.motif')],
        )['motif'];
    }
}
