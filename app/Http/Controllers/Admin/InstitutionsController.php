<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\Role as RoleEnum;
use App\Enums\StatutDocumentKyc;
use App\Enums\TypeDocumentKyb;
use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\User;
use App\Services\Backoffice\GestionUtilisateurs;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Institutionnels\GestionInstitutions;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Onglet « Institutions » (SUPER_ADMIN, ADMIN) : création d'une institution et invitation de son
 * premier signataire, suspension ; interlocuteur dédié (SUPER_ADMIN). Aucune donnée financière.
 */
class InstitutionsController extends Controller
{
    public function __construct(private readonly GestionInstitutions $gestion) {}

    public function index(): View
    {
        Gate::authorize(Droit::InstitutionsGerer->value);

        return view('admin.institutions.index', [
            'institutions' => Institution::query()->withCount('utilisateurs')
                ->with(['documents', 'conventions'])->orderBy('denomination')->paginate(25),
            'totalPieces' => count(TypeDocumentKyb::cases()),
        ]);
    }

    public function create(): View
    {
        Gate::authorize(Droit::InstitutionsGerer->value);

        return view('admin.institutions.create');
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::InstitutionsGerer->value);

        $donnees = $request->validate([
            ...self::reglesIdentite(),
            'signataire_nom' => ['required', 'string', 'max:150'],
            'signataire_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
        ], ['signataire_email.unique' => __('institutionnel.regles.email_existe')], __('institutionnel.bo.institutions.champs'));

        $institution = $this->gestion->creer($request->user(), collect($donnees)->except(['signataire_nom', 'signataire_email'])->all(),
            $donnees['signataire_nom'], $donnees['signataire_email']);

        return redirect()->route('admin.institutions.show', $institution)
            ->with('succes', __('institutionnel.bo.institutions.creee', ['email' => $donnees['signataire_email']]));
    }

    public function show(Institution $institution): View
    {
        Gate::authorize(Droit::InstitutionsGerer->value);

        return view('admin.institutions.show', [
            'institution' => $institution,
            'utilisateurs' => $institution->utilisateurs()->orderBy('name')->get(),
            'pieces' => $institution->piecesEnVigueur(),
            'conventions' => $institution->conventions()->get(),
        ]);
    }

    public function inviter(Request $request, Institution $institution): RedirectResponse
    {
        Gate::authorize(Droit::InstitutionsGerer->value);

        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(array_column(RoleEnum::institutionnels(), 'value'))],
        ], [], ['nom' => __('institutionnel.organisation.nom'), 'email' => __('institutionnel.organisation.email')]);

        return $this->executer(fn () => $this->gestion->inviter($request->user(), $institution, $donnees['nom'], $donnees['email'], RoleEnum::from($donnees['role'])),
            $institution, __('institutionnel.organisation.invite', ['email' => $donnees['email']]));
    }

    public function renvoyerLien(Request $request, Institution $institution, User $utilisateur): RedirectResponse
    {
        Gate::authorize(Droit::InstitutionsGerer->value);
        abort_unless($utilisateur->institution_id === $institution->id, 404);

        return $this->executer(fn () => app(GestionUtilisateurs::class)->envoyerLien($request->user(), $utilisateur),
            $institution, __('institutionnel.bo.institutions.lien_envoye'));
    }

    public function interlocuteur(Request $request, Institution $institution): RedirectResponse
    {
        Gate::authorize(Droit::InstitutionsInterlocuteur->value);

        $donnees = $request->validate([
            'interlocuteur_nom' => ['nullable', 'string', 'max:120'],
            'interlocuteur_email' => ['nullable', 'email', 'max:255'],
            'interlocuteur_telephone' => ['nullable', 'string', 'max:30'],
        ], [], __('institutionnel.bo.institutions.champs'));

        $this->gestion->definirInterlocuteur($request->user(), $institution, $donnees);

        return redirect()->route('admin.institutions.show', $institution)->with('succes', __('institutionnel.bo.institutions.interlocuteur_enregistre'));
    }

    public function suspendre(Request $request, Institution $institution): RedirectResponse
    {
        Gate::authorize(Droit::InstitutionsGerer->value);
        $motif = $this->motif($request);

        return $this->executer(fn () => $this->gestion->suspendre($request->user(), $institution, $motif), $institution, __('institutionnel.bo.institutions.suspendue'));
    }

    public function reactiver(Request $request, Institution $institution): RedirectResponse
    {
        Gate::authorize(Droit::InstitutionsGerer->value);
        $motif = $this->motif($request);

        return $this->executer(fn () => $this->gestion->reactiver($request->user(), $institution, $motif), $institution, __('institutionnel.bo.institutions.reactivee'));
    }

    /**
     * @return array<string, list<string>>
     */
    public static function reglesIdentite(): array
    {
        return [
            'denomination' => ['required', 'string', 'max:200'],
            'sigle' => ['nullable', 'string', 'max:40'],
            'forme_juridique' => ['nullable', 'string', 'max:80'],
            'rccm' => ['nullable', 'string', 'max:60'],
            'ifu' => ['nullable', 'string', 'max:40'],
            'adresse' => ['nullable', 'string', 'max:300'],
            'secteur' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** Nombre de pièces validées (affichage de l'avancement du dossier). */
    public static function piecesValidees(Institution $institution): int
    {
        return $institution->documents->unique(fn ($d) => $d->type->value)
            ->where('statut', StatutDocumentKyc::Valide)->count();
    }

    private function executer(Closure $action, Institution $institution, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.institutions.show', $institution)->with('erreur', $e->getMessage());
        }

        return redirect()->route('admin.institutions.show', $institution)->with('succes', $message);
    }

    private function motif(Request $request): string
    {
        return $request->validate(['motif' => ['required', 'string', 'min:5', 'max:500']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];
    }
}
