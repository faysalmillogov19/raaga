<?php

namespace App\Http\Controllers\Compte;

use App\Enums\DocumentAccepte;
use App\Enums\Role as RoleEnum;
use App\Enums\StatutKyc;
use App\Enums\TypeTitulaire;
use App\Http\Controllers\Controller;
use App\Models\Acceptation;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\OtpException;
use App\Services\Auth\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Création d'un compte citoyen en 3 étapes : téléphone → code SMS → identité.
 * L'état du parcours est conservé en session (clé « inscription »).
 */
class InscriptionController extends Controller
{
    use ValideTelephone;

    private const OBJET = 'inscription';

    private const CHAMPS_IDENTITE = ['type_titulaire', 'raison_sociale', 'forme_morale', 'nom', 'prenoms', 'date_naissance', 'pays_residence', 'region'];

    public function __construct(private readonly OtpService $otp) {}

    public function create(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return $request->session()->get('inscription.succes')
                ? view('public.compte.inscription', ['etape' => 'succes'])
                : redirect()->route('espace');
        }

        $session = $request->session()->get('inscription', []);
        $etape = $session['etape'] ?? 'telephone';
        $telephone = $session['telephone'] ?? null;

        return view('public.compte.inscription', [
            'etape' => $etape,
            'telephone' => $telephone,
            'saisie' => $session['saisie'] ?? [],
            'secondesRenvoi' => $etape === 'code' && $telephone ? $this->otp->secondesAvantRenvoi($telephone, self::OBJET) : 0,
            'pays' => config('raaga.inscription.pays'),
            'regions' => config('raaga.inscription.regions'),
        ]);
    }

    public function envoyerCode(Request $request): RedirectResponse
    {
        $telephone = $this->telephoneValide($request);

        if (User::query()->where('telephone', $telephone)->exists()) {
            return back()->withInput()
                ->withErrors(['telephone' => __('public.compte.inscription.existe')])
                ->with('compte.existe', true);
        }

        // Numéro déjà vérifié dans ce parcours (retour arrière) : on reprend directement à l'identité.
        if ($request->session()->get('inscription.verifie') === $telephone) {
            $request->session()->put('inscription.etape', 'identite');

            return redirect()->route('inscription');
        }

        try {
            $this->otp->envoyer($telephone, self::OBJET);
        } catch (OtpException $e) {
            // Un code encore valable a déjà été envoyé : on passe simplement à sa saisie.
            if (! $this->otp->aUnCodeActif($telephone, self::OBJET)) {
                return back()->withInput()->withErrors(['telephone' => $e->getMessage()]);
            }
        }

        $request->session()->put('inscription.telephone', $telephone);
        $request->session()->put('inscription.etape', 'code');

        return redirect()->route('inscription');
    }

    public function verifierCode(Request $request): RedirectResponse
    {
        $telephone = $request->session()->get('inscription.telephone');

        if (! $telephone) {
            return redirect()->route('inscription');
        }

        $code = $this->codeValide($request);

        try {
            $valide = $this->otp->verifier($telephone, self::OBJET, $code);
        } catch (OtpException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        if (! $valide) {
            return back()->withErrors(['code' => __('public.otp.erreurs.code_incorrect')]);
        }

        $request->session()->put('inscription.verifie', $telephone);
        $request->session()->put('inscription.etape', 'identite');

        return redirect()->route('inscription');
    }

    public function renvoyer(Request $request): RedirectResponse
    {
        $telephone = $request->session()->get('inscription.telephone');

        if (! $telephone) {
            return redirect()->route('inscription');
        }

        try {
            $this->otp->envoyer($telephone, self::OBJET);
        } catch (OtpException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back();
    }

    /**
     * Revient à l'étape du téléphone en conservant la saisie (numéro et identité).
     */
    public function retour(Request $request): RedirectResponse
    {
        if ($request->hasAny(self::CHAMPS_IDENTITE)) {
            $request->session()->put('inscription.saisie', $request->only(self::CHAMPS_IDENTITE));
        }

        $request->session()->put('inscription.etape', 'telephone');

        return redirect()->route('inscription');
    }

    public function store(Request $request): RedirectResponse
    {
        $telephone = $request->session()->get('inscription.verifie');

        if (! $telephone) {
            $request->session()->put('inscription.etape', 'telephone');

            return redirect()->route('inscription')
                ->withErrors(['telephone' => __('public.compte.inscription.erreurs.session')]);
        }

        $request->session()->put('inscription.saisie', $request->only(self::CHAMPS_IDENTITE));

        $age = (int) config('raaga.inscription.age_minimum');
        $messages = __('public.compte.inscription.erreurs');

        $titulaires = __('public.titulaires.erreurs');
        $donnees = $request->validate([
            // Personne physique, ou personne morale (raison sociale, forme) tenue par son représentant légal.
            'type_titulaire' => ['required', Rule::enum(TypeTitulaire::class)],
            'raison_sociale' => ['nullable', 'required_if:type_titulaire,MORALE', 'string', 'min:2', 'max:200'],
            'forme_morale' => ['nullable', 'required_if:type_titulaire,MORALE', Rule::in(TypeTitulaire::FORMES)],
            'nom' => ['required', 'string', 'max:100'],
            'prenoms' => ['required', 'string', 'max:150'],
            'date_naissance' => ['required', 'date_format:Y-m-d', 'after:1900-01-01', 'before_or_equal:'.now()->subYears($age)->toDateString()],
            'pays_residence' => ['required', Rule::in(array_keys(config('raaga.inscription.pays')))],
            'region' => ['nullable', 'required_if:pays_residence,BF', Rule::in(config('raaga.inscription.regions'))],
            'conditions' => ['accepted'],
        ], [
            'type_titulaire.*' => $titulaires['type'],
            'raison_sociale.*' => $titulaires['raison_sociale'],
            'forme_morale.*' => $titulaires['forme'],
            'nom.*' => $messages['nom'],
            'prenoms.*' => $messages['prenoms'],
            'date_naissance.before_or_equal' => __('public.compte.inscription.erreurs.majeur', ['age' => $age]),
            'date_naissance.*' => $messages['date_naissance'],
            'pays_residence.*' => $messages['pays'],
            'region.*' => $messages['region'],
            'conditions.*' => $messages['conditions'],
        ]);

        $user = DB::transaction(function () use ($donnees, $telephone, $request) {
            $nom = mb_strtoupper(trim($donnees['nom']), 'UTF-8');
            $prenoms = trim($donnees['prenoms']);

            $user = User::create([
                'name' => $prenoms.' '.$nom,
                'nom' => $nom,
                'prenoms' => $prenoms,
                'telephone' => $telephone,
                'date_naissance' => $donnees['date_naissance'],
                'nationalite' => 'BF',
                'pays_residence' => $donnees['pays_residence'],
                'region' => $donnees['pays_residence'] === 'BF' ? $donnees['region'] : null,
                'role_id' => Role::pour(RoleEnum::Citoyen)->id,
                'statut_kyc' => StatutKyc::NonSoumis,
                'type_titulaire' => $donnees['type_titulaire'],
                'raison_sociale' => $donnees['type_titulaire'] === TypeTitulaire::Morale->value ? trim($donnees['raison_sociale']) : null,
                'forme_morale' => $donnees['type_titulaire'] === TypeTitulaire::Morale->value ? $donnees['forme_morale'] : null,
            ]);

            Acceptation::create([
                'user_id' => $user->id,
                'document' => DocumentAccepte::ConditionsUtilisation,
                'version_document' => config('raaga.inscription.version_conditions'),
                'accepte_le' => now(),
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);

            // Journal d'audit : aucune donnée personnelle, seulement la nature du compte.
            AuditLog::create([
                'acteur_id' => $user->id,
                'action' => 'compte.cree',
                'entite_type' => 'User',
                'entite_id' => (string) $user->id,
                'nouvelle_valeur' => ['role' => RoleEnum::Citoyen->value, 'diaspora' => $donnees['pays_residence'] !== 'BF', 'titulaire' => $donnees['type_titulaire']],
                'ip' => $request->ip(),
                'created_at' => now(),
            ]);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('inscription');
        $request->session()->put('inscription.succes', true);

        return redirect()->route('inscription');
    }
}
