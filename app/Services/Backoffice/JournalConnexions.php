<?php

namespace App\Services\Backoffice;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Journal des connexions back-office (succès et échecs) et verrouillage après échecs répétés.
 */
class JournalConnexions
{
    public function succes(Request $request, User $user, string $etape): void
    {
        $this->ecrire($request, $user, (string) $user->email, $etape, true, null);

        $user->forceFill([
            'echecs_connexion' => 0,
            'verrouille_jusqua' => null,
            'derniere_connexion_le' => now(),
        ])->save();

        AuditLog::create([
            'acteur_id' => $user->id,
            'action' => 'backoffice.connexion',
            'entite_type' => 'User',
            'entite_id' => (string) $user->id,
            'ip' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Enregistre un échec ; au-delà du seuil, le compte est verrouillé.
     */
    public function echec(Request $request, ?User $user, string $email, string $etape, string $motif): void
    {
        $this->ecrire($request, $user, $email, $etape, false, $motif);

        if ($user === null) {
            return;
        }

        DB::transaction(function () use ($user) {
            $user->refresh();
            $echecs = $user->echecs_connexion + 1;
            $donnees = ['echecs_connexion' => $echecs];

            if ($echecs >= config('raaga.backoffice.echecs_max')) {
                $donnees['verrouille_jusqua'] = now()->addMinutes(config('raaga.backoffice.verrouillage_minutes'));
                $donnees['echecs_connexion'] = 0;
            }

            $user->forceFill($donnees)->save();
        });
    }

    private function ecrire(Request $request, ?User $user, string $email, string $etape, bool $succes, ?string $motif): void
    {
        DB::table('connexions_backoffice')->insert([
            'user_id' => $user?->id,
            'email' => mb_substr(mb_strtolower(trim($email)), 0, 255),
            'etape' => $etape,
            'succes' => $succes,
            'motif' => $motif,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
