<?php

namespace App\Services\Backoffice;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Actions critiques (ouvrir, clôturer, allouer, mettre en paiement) : saisie du mot « CONFIRMER »
 * et d'un nouveau code TOTP, vérifiés côté serveur.
 */
class ConfirmationRenforcee
{
    public const MOT = 'CONFIRMER';

    public function __construct(private readonly DoubleAuthentification $totp) {}

    /**
     * @throws RegleGestionException
     */
    public function verifier(Request $request, User $user): void
    {
        if (trim((string) $request->input('confirmation')) !== self::MOT) {
            throw new RegleGestionException(__('admin.confirmation_renforcee.erreurs.mot', ['mot' => self::MOT]));
        }

        if ($user->totpActive()) {
            if (! $this->totp->verifier($user, (string) $request->input('code_totp'))) {
                throw new RegleGestionException(__('admin.confirmation_renforcee.erreurs.code'));
            }

            return;
        }

        // Sans TOTP enrôlé : toléré uniquement avec la dispense de développement (local).
        if (! (app()->environment('local') && config('raaga.backoffice.totp_facultatif_en_local'))) {
            throw new RegleGestionException(__('admin.confirmation_renforcee.erreurs.totp_requis'));
        }
    }

    public static function totpExige(User $user): bool
    {
        return $user->totpActive();
    }
}
