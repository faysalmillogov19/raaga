<?php

namespace App\Http\Controllers\Compte;

use App\Enums\StatutKyc;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Auth\OtpException;
use App\Services\Auth\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * « Mon profil » des comptes grand public : informations personnelles et téléphone.
 *
 * - Modifier le nom, les prénoms ou la date de naissance après une vérification d'identité
 *   remet le KYC à « non soumis » : les pièces doivent correspondre à l'identité déclarée.
 * - Le téléphone sert à se connecter : le nouveau numéro est confirmé par un code SMS.
 * - Le rôle n'est jamais modifiable par la personne elle-même.
 */
class ProfilController extends Controller
{
    use ValideTelephone;

    private const OBJET_TELEPHONE = 'telephone';

    private const CHAMPS_IDENTITE = ['nom', 'prenoms', 'date_naissance'];

    public function __construct(
        private readonly Journal $journal,
        private readonly OtpService $otp,
    ) {}

    public function show(Request $request): View
    {
        $nouveau = $request->session()->get('profil.nouveau_telephone');

        return view('public.compte.profil', [
            'user' => $request->user(),
            'pays' => config('raaga.inscription.pays'),
            'regions' => config('raaga.inscription.regions'),
            'nouveauTelephone' => $nouveau,
            'secondesRenvoi' => $nouveau ? $this->otp->secondesAvantRenvoi($nouveau, self::OBJET_TELEPHONE) : 0,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $age = (int) config('raaga.inscription.age_minimum');
        $messages = __('public.compte.inscription.erreurs');

        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'prenoms' => ['required', 'string', 'max:150'],
            'date_naissance' => ['required', 'date_format:Y-m-d', 'after:1900-01-01', 'before_or_equal:'.now()->subYears($age)->toDateString()],
            'pays_residence' => ['required', Rule::in(array_keys(config('raaga.inscription.pays')))],
            'region' => ['nullable', 'required_if:pays_residence,BF', Rule::in(config('raaga.inscription.regions'))],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ], [
            'nom.*' => $messages['nom'],
            'prenoms.*' => $messages['prenoms'],
            'date_naissance.before_or_equal' => __('public.compte.inscription.erreurs.majeur', ['age' => $age]),
            'date_naissance.*' => $messages['date_naissance'],
            'pays_residence.*' => $messages['pays'],
            'region.*' => $messages['region'],
            'email.unique' => __('public.compte.profil.erreurs.email_existe'),
            'email.*' => __('public.compte.profil.erreurs.email'),
        ]);

        $nom = mb_strtoupper(trim($donnees['nom']), 'UTF-8');
        $prenoms = trim($donnees['prenoms']);

        $user->fill([
            'nom' => $nom,
            'prenoms' => $prenoms,
            'name' => $prenoms.' '.$nom,
            'date_naissance' => $donnees['date_naissance'],
            'pays_residence' => $donnees['pays_residence'],
            'region' => $donnees['pays_residence'] === 'BF' ? $donnees['region'] : null,
            'email' => $donnees['email'] ? mb_strtolower(trim($donnees['email'])) : null,
        ]);

        $modifies = array_values(array_diff(array_keys($user->getDirty()), ['name']));

        if ($modifies === []) {
            return redirect()->route('espace.profil')->with('succes', __('public.compte.profil.aucun_changement'));
        }

        // L'identité vérifiée ne correspond plus : nouvelle vérification nécessaire.
        $identiteModifiee = array_intersect($modifies, self::CHAMPS_IDENTITE) !== [];
        $kycRemis = $identiteModifiee && in_array($user->statut_kyc, [StatutKyc::Valide, StatutKyc::EnAttente], true);

        if ($kycRemis) {
            $user->statut_kyc = StatutKyc::NonSoumis;
            $user->niveau_kyc = 0;
        }

        $user->save();

        $this->journal->ecrire($user, 'profil.modifie', 'User', $user->id, null, [
            'champs' => $modifies,
            'kyc_a_refaire' => $kycRemis,
        ]);

        return redirect()->route('espace.profil')->with(
            'succes',
            $kycRemis ? __('public.compte.profil.enregistre_kyc') : __('public.compte.profil.enregistre'),
        );
    }

    public function envoyerCodeTelephone(Request $request): RedirectResponse
    {
        $telephone = $this->telephoneValide($request);
        $user = $request->user();

        if ($telephone === $user->telephone) {
            return back()->withInput()->withErrors(['telephone' => __('public.compte.profil.erreurs.meme_numero')]);
        }

        if (User::query()->where('telephone', $telephone)->exists()) {
            return back()->withInput()->withErrors(['telephone' => __('public.compte.profil.erreurs.numero_pris')]);
        }

        try {
            $this->otp->envoyer($telephone, self::OBJET_TELEPHONE);
        } catch (OtpException $e) {
            if (! $this->otp->aUnCodeActif($telephone, self::OBJET_TELEPHONE)) {
                return back()->withInput()->withErrors(['telephone' => $e->getMessage()]);
            }
        }

        $request->session()->put('profil.nouveau_telephone', $telephone);

        return redirect()->to(route('espace.profil').'#telephone');
    }

    public function confirmerTelephone(Request $request): RedirectResponse
    {
        $telephone = $request->session()->get('profil.nouveau_telephone');

        if (! $telephone) {
            return redirect()->route('espace.profil');
        }

        $code = $this->codeValide($request);

        try {
            $valide = $this->otp->verifier($telephone, self::OBJET_TELEPHONE, $code);
        } catch (OtpException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        if (! $valide) {
            return back()->withErrors(['code' => __('public.otp.erreurs.code_incorrect')]);
        }

        // Vérification d'unicité au dernier moment (le numéro a pu être pris entre-temps).
        if (User::query()->where('telephone', $telephone)->exists()) {
            $request->session()->forget('profil.nouveau_telephone');

            return redirect()->route('espace.profil')->withErrors(['telephone' => __('public.compte.profil.erreurs.numero_pris')]);
        }

        $user = $request->user();
        $user->forceFill(['telephone' => $telephone])->save();
        $request->session()->forget('profil.nouveau_telephone');

        $this->journal->ecrire($user, 'profil.telephone_modifie', 'User', $user->id);

        return redirect()->route('espace.profil')->with('succes', __('public.compte.profil.telephone_enregistre'));
    }

    public function renvoyerCodeTelephone(Request $request): RedirectResponse
    {
        $telephone = $request->session()->get('profil.nouveau_telephone');

        if (! $telephone) {
            return redirect()->route('espace.profil');
        }

        try {
            $this->otp->envoyer($telephone, self::OBJET_TELEPHONE);
        } catch (OtpException $e) {
            return redirect()->to(route('espace.profil').'#telephone')->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()->to(route('espace.profil').'#telephone');
    }

    public function annulerTelephone(Request $request): RedirectResponse
    {
        $request->session()->forget('profil.nouveau_telephone');

        return redirect()->route('espace.profil');
    }
}
