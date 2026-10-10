<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Services\Audit\Journal;
use App\Services\Backoffice\DoubleAuthentification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Lien d'invitation (ou de nouvel accès) à un espace sécurisé (back-office, espace institutionnel) :
 * définition du mot de passe, puis enrôlement de la double authentification.
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly Journal $journal,
        private readonly DoubleAuthentification $totp,
    ) {}

    public function show(Request $request, string $jeton): View
    {
        $invitation = $this->invitationValide($request, $jeton);

        return view('admin.connexion.invitation', [
            'invitation' => $invitation,
            'jeton' => $jeton,
            'espace' => ConnexionController::espace($request),
        ]);
    }

    public function store(Request $request, string $jeton): RedirectResponse|View
    {
        $invitation = $this->invitationValide($request, $jeton);
        $min = (int) config('raaga.backoffice.mot_de_passe_min');

        $request->validate([
            'password' => ['required', 'confirmed', Password::min($min)->letters()->numbers()],
        ], [], ['password' => __('admin.connexion.mot_de_passe')]);

        $user = $invitation->user;

        DB::transaction(function () use ($invitation, $user, $request) {
            $user->forceFill([
                'password' => Hash::make($request->input('password')),
                'echecs_connexion' => 0,
                'verrouille_jusqua' => null,
            ])->save();

            $invitation->forceFill(['utilisee_le' => now()])->save();
            $this->journal->ecrire($user, 'invitation.acceptee', 'User', $user->id);
        });

        // Toute session ouverte (par exemple un autre compte) est fermée avant de poursuivre.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Étape suivante : la double authentification (enrôlement, ou code si déjà enrôlé).
        $espace = ConnexionController::espace($request);
        $request->session()->put($espace->cleSession().'.en_attente', ['id' => $user->id, 'expire' => now()->addMinutes(10)->timestamp]);

        return redirect()->route($espace->route($user->totpActive() ? 'connexion.totp' : 'connexion.enrolement'))
            ->with('statut', __('admin.invitation.mot_de_passe_enregistre'));
    }

    private function invitationValide(Request $request, string $jeton): Invitation
    {
        $espace = ConnexionController::espace($request);
        $invitation = Invitation::query()->where('jeton_empreinte', Invitation::empreinte($jeton))->with('user')->first();

        if (! $invitation || ! $invitation->estValide() || ! $invitation->user?->actif || ! $espace->autorise($invitation->user)) {
            abort(response()->view('admin.connexion.invitation-invalide', ['espace' => $espace], 410));
        }

        return $invitation;
    }
}
