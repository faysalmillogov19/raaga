<?php

namespace App\Services\Backoffice;

use App\Enums\Espace;
use App\Enums\Role as RoleEnum;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InvitationBackoffice;
use App\Services\Audit\Journal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gestion des comptes par le SUPER_ADMIN. Règles :
 * - personne ne modifie son propre rôle ni ne se désactive ;
 * - il reste toujours au moins 2 SUPER_ADMIN actifs ;
 * - un changement de rôle, une désactivation ou une réinitialisation ferme les sessions du compte ;
 * - chaque action est inscrite au journal d'audit.
 */
class GestionUtilisateurs
{
    public const SUPER_ADMINS_MINIMUM = 2;

    /** Dernier lien d'accès produit (pour la commande serveur ; jamais affiché dans l'interface). */
    private ?string $dernierLien = null;

    public function __construct(
        private readonly Journal $journal,
        private readonly DoubleAuthentification $totp,
    ) {}

    /**
     * Crée un compte back-office sans mot de passe et lui envoie son lien d'invitation.
     */
    public function inviter(?User $acteur, string $nom, string $email, RoleEnum $role): User
    {
        if (! $role->estBackoffice()) {
            throw new RegleGestionException(__('admin.utilisateurs.regles.role_backoffice'));
        }

        return DB::transaction(function () use ($acteur, $nom, $email, $role) {
            $user = User::create([
                'name' => $nom,
                'email' => mb_strtolower(trim($email)),
                'role_id' => Role::pour($role)->id,
                'actif' => true,
            ]);

            $this->journal->ecrire($acteur, 'utilisateur.invite', 'User', $user->id, null, ['role' => $role->value]);
            $this->envoyerLien($acteur, $user);

            return $user;
        });
    }

    /**
     * Nouveau lien d'accès (invitation non reçue, mot de passe oublié). Les liens précédents sont annulés.
     *
     * @return string le lien (utilisé par la commande serveur ; jamais affiché dans l'interface)
     */
    public function envoyerLien(?User $acteur, User $user): string
    {
        $this->refuserSiSoiMeme($acteur, $user);
        $espace = Espace::pour($user) ?? throw new RegleGestionException(__('admin.utilisateurs.regles.role_backoffice'));

        if (! $user->actif) {
            throw new RegleGestionException(__('admin.utilisateurs.regles.compte_desactive'));
        }

        $jeton = Str::random(64);
        $heures = (int) config('raaga.backoffice.invitation_heures');

        DB::transaction(function () use ($acteur, $user, $jeton, $heures) {
            Invitation::query()->where('user_id', $user->id)->whereNull('utilisee_le')->whereNull('annulee_le')
                ->update(['annulee_le' => now()]);

            Invitation::create([
                'user_id' => $user->id,
                'jeton_empreinte' => Invitation::empreinte($jeton),
                'expire_le' => now()->addHours($heures),
                'cree_par' => $acteur?->id,
            ]);

            $this->journal->ecrire($acteur, 'utilisateur.lien_acces_envoye', 'User', $user->id);
        });

        $lien = route($espace->route('invitation'), $jeton);
        $user->notify(new InvitationBackoffice($lien, $heures, $user->codeRole()->badge(), $espace));

        return $this->dernierLien = $lien;
    }

    public function dernierLien(): ?string
    {
        return $this->dernierLien;
    }

    public function changerRole(User $acteur, User $user, RoleEnum $nouveau): void
    {
        $this->refuserSiSoiMeme($acteur, $user);
        $this->refuserSiHorsBackoffice($user);

        if (! $nouveau->estBackoffice()) {
            throw new RegleGestionException(__('admin.utilisateurs.regles.role_backoffice'));
        }

        $ancien = $user->codeRole();
        if ($ancien === $nouveau) {
            return;
        }

        DB::transaction(function () use ($acteur, $user, $ancien, $nouveau) {
            if ($ancien === RoleEnum::SuperAdmin) {
                $this->garantirSuperAdmins($user);
            }

            $user->forceFill(['role_id' => Role::pour($nouveau)->id])->save();
            $this->fermerSessions($user);
            $this->journal->ecrire($acteur, 'utilisateur.role_modifie', 'User', $user->id, ['role' => $ancien->value], ['role' => $nouveau->value]);
        });
    }

    public function desactiver(User $acteur, User $user, string $motif): void
    {
        $this->refuserSiSoiMeme($acteur, $user);

        if (! $user->actif) {
            return;
        }

        DB::transaction(function () use ($acteur, $user, $motif) {
            if ($user->codeRole() === RoleEnum::SuperAdmin) {
                $this->garantirSuperAdmins($user);
            }

            $user->forceFill(['actif' => false])->save();
            $this->fermerSessions($user);
            Invitation::query()->where('user_id', $user->id)->whereNull('utilisee_le')->whereNull('annulee_le')
                ->update(['annulee_le' => now()]);
            $this->journal->ecrire($acteur, 'utilisateur.desactive', 'User', $user->id, ['actif' => true], ['actif' => false, 'motif' => $motif]);
        });
    }

    public function reactiver(User $acteur, User $user, string $motif): void
    {
        if ($user->actif) {
            return;
        }

        $user->forceFill(['actif' => true])->save();
        $this->journal->ecrire($acteur, 'utilisateur.reactive', 'User', $user->id, ['actif' => false], ['actif' => true, 'motif' => $motif]);
    }

    public function reinitialiserTotp(User $acteur, User $user): void
    {
        $this->refuserSiSoiMeme($acteur, $user);
        $this->refuserSiHorsBackoffice($user);

        DB::transaction(function () use ($acteur, $user) {
            $this->totp->reinitialiser($user);
            $this->fermerSessions($user);
            $this->journal->ecrire($acteur, 'utilisateur.totp_reinitialise', 'User', $user->id);
        });
    }

    public function deverrouiller(User $acteur, User $user): void
    {
        $this->refuserSiHorsBackoffice($user);

        $user->forceFill(['verrouille_jusqua' => null, 'echecs_connexion' => 0])->save();
        $this->journal->ecrire($acteur, 'utilisateur.deverrouille', 'User', $user->id);
    }

    public static function superAdminsActifs(): int
    {
        return User::query()
            ->where('actif', true)
            ->whereHas('role', fn ($q) => $q->where('code', RoleEnum::SuperAdmin->value))
            ->count();
    }

    /**
     * Le retrait de ce SUPER_ADMIN (changement de rôle ou désactivation) doit en laisser au moins 2 actifs.
     * Les lignes sont verrouillées le temps de la transaction pour qu'aucune action simultanée ne contourne la règle.
     */
    private function garantirSuperAdmins(User $retire): void
    {
        $restants = User::query()
            ->where('actif', true)
            ->whereKeyNot($retire->id)
            ->whereHas('role', fn ($q) => $q->where('code', RoleEnum::SuperAdmin->value))
            ->lockForUpdate()
            ->count();

        if ($restants < self::SUPER_ADMINS_MINIMUM) {
            throw new RegleGestionException(__('admin.utilisateurs.regles.minimum_super_admins', ['n' => self::SUPER_ADMINS_MINIMUM]));
        }
    }

    private function refuserSiSoiMeme(?User $acteur, User $user): void
    {
        if ($acteur && $acteur->is($user)) {
            throw new RegleGestionException(__('admin.utilisateurs.regles.soi_meme'));
        }
    }

    private function refuserSiHorsBackoffice(User $user): void
    {
        if (! $user->estBackoffice()) {
            throw new RegleGestionException(__('admin.utilisateurs.regles.role_backoffice'));
        }
    }

    /**
     * Déconnecte le compte partout : sessions en base supprimées, jeton « se souvenir de moi » renouvelé.
     */
    private function fermerSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }
}
