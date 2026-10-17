<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * « Mon profil » d'un compte back-office : nom, adresse électronique, mot de passe.
 * Le rôle n'est jamais modifiable par la personne elle-même.
 */
class ProfilController extends Controller
{
    public function __construct(private readonly Journal $journal) {}

    public function show(Request $request): View
    {
        return view('admin.profil', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $donnees = $request->validateWithBag('informations', [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'mot_de_passe_actuel' => ['required', 'current_password'],
        ], [], [
            'name' => __('admin.profil.nom'),
            'email' => __('admin.profil.email'),
            'mot_de_passe_actuel' => __('admin.profil.mot_de_passe_actuel'),
        ]);

        $user->fill(['name' => $donnees['name'], 'email' => mb_strtolower(trim($donnees['email']))]);
        $modifies = array_keys($user->getDirty());

        if ($modifies) {
            $user->save();
            $this->journal->ecrire($user, 'profil.modifie', 'User', $user->id, null, ['champs' => $modifies]);
        }

        return redirect()->route('admin.profil')->avecSucces('admin.profil.enregistre');
    }

    public function motDePasse(Request $request): RedirectResponse
    {
        $user = $request->user();
        $min = (int) config('raaga.backoffice.mot_de_passe_min');

        $request->validateWithBag('motDePasse', [
            'mot_de_passe_actuel' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:mot_de_passe_actuel', Password::min($min)->letters()->numbers()],
        ], [], [
            'mot_de_passe_actuel' => __('admin.profil.mot_de_passe_actuel'),
            'password' => __('admin.profil.nouveau_mot_de_passe'),
        ]);

        $user->forceFill(['password' => Hash::make($request->input('password'))])->save();

        // Les autres sessions ouvertes avec l'ancien mot de passe sont fermées.
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();

        $this->journal->ecrire($user, 'profil.mot_de_passe_modifie', 'User', $user->id);

        return redirect()->route('admin.profil')->avecSucces('admin.profil.mot_de_passe_enregistre');
    }
}
