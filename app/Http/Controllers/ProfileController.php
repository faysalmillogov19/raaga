<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\Audit\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

/**
 * Profil Breeze, pour les comptes qui se connectent par adresse électronique hors back-office
 * (investisseurs, porteurs de projet…). Les comptes back-office ont /admin/profil et les citoyens
 * /espace/profil. Aucun compte ne peut être supprimé : seul un SUPER_ADMIN peut le désactiver.
 */
class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View|RedirectResponse
    {
        return $this->redirectionDediee($request) ?? view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, Journal $journal): RedirectResponse
    {
        if ($redirection = $this->redirectionDediee($request)) {
            return $redirection;
        }

        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $modifies = array_keys($request->user()->getDirty());
        $request->user()->save();

        if ($modifies) {
            $journal->ecrire($request->user(), 'profil.modifie', 'User', $request->user()->id, null, ['champs' => $modifies]);
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Back-office et citoyens ont leur propre page de profil.
     */
    private function redirectionDediee(Request $request): ?RedirectResponse
    {
        $user = $request->user();

        return match (true) {
            $user->estBackoffice() => redirect()->route('admin.profil'),
            $user->isCitoyen() => redirect()->route('espace.profil'),
            default => null,
        };
    }
}
