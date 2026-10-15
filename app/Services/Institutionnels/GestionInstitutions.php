<?php

namespace App\Services\Institutionnels;

use App\Enums\Role as RoleEnum;
use App\Enums\StatutInstitution;
use App\Models\Institution;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Backoffice\GestionUtilisateurs;
use App\Services\Backoffice\RegleGestionException;
use Illuminate\Support\Facades\DB;

/**
 * Institutions et leurs utilisateurs. Une institution est créée par un SUPER_ADMIN ou un ADMIN,
 * qui invite son premier signataire (pas d'inscription libre) ; le signataire gère ensuite les
 * utilisateurs de son institution. Il reste toujours au moins un signataire actif.
 */
class GestionInstitutions
{
    public function __construct(
        private readonly Journal $journal,
        private readonly GestionUtilisateurs $utilisateurs,
    ) {}

    /**
     * @param  array<string, mixed>  $donnees  identité de l'institution
     */
    public function creer(User $acteur, array $donnees, string $nomSignataire, string $emailSignataire): Institution
    {
        return DB::transaction(function () use ($acteur, $donnees, $nomSignataire, $emailSignataire) {
            $institution = Institution::create([...$donnees, 'statut' => StatutInstitution::Invitee, 'cree_par' => $acteur->id]);
            $this->journal->ecrire($acteur, 'institution.creee', 'Institution', $institution->id, null, ['denomination' => $institution->denomination]);

            $this->inviter($acteur, $institution, $nomSignataire, $emailSignataire, RoleEnum::InstitutionSignataire);

            return $institution;
        });
    }

    /**
     * Invite un utilisateur de l'institution (lien valable 48 h, mot de passe puis TOTP).
     */
    public function inviter(User $acteur, Institution $institution, string $nom, string $email, RoleEnum $role): User
    {
        if (! $role->estInstitutionnel()) {
            throw new RegleGestionException(__('institutionnel.regles.role'));
        }
        $this->refuserSiAutreInstitution($acteur, $institution);

        $email = mb_strtolower(trim($email));
        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw new RegleGestionException(__('institutionnel.regles.email_existe'));
        }

        return DB::transaction(function () use ($acteur, $institution, $nom, $email, $role) {
            $user = User::create([
                'name' => $nom,
                'email' => $email,
                'role_id' => Role::pour($role)->id,
                'institution_id' => $institution->id,
                'actif' => true,
            ]);

            $this->journal->ecrire($acteur, 'institution.utilisateur_invite', 'User', $user->id, null, ['institution' => $institution->id, 'role' => $role->value]);
            $this->utilisateurs->envoyerLien($acteur, $user);

            return $user;
        });
    }

    public function desactiverUtilisateur(User $acteur, User $user, string $motif): void
    {
        $this->refuserSiAutreInstitution($acteur, $user->institution);
        if ($acteur->is($user)) {
            throw new RegleGestionException(__('admin.utilisateurs.regles.soi_meme'));
        }

        DB::transaction(function () use ($acteur, $user, $motif) {
            if ($user->codeRole() === RoleEnum::InstitutionSignataire
                && $user->institution->signatairesActifs()->whereKeyNot($user->id)->compterVerrouille() === 0) {
                throw new RegleGestionException(__('institutionnel.regles.dernier_signataire'));
            }

            $this->utilisateurs->desactiver($acteur, $user, $motif);
        });
    }

    public function reactiverUtilisateur(User $acteur, User $user, string $motif): void
    {
        $this->refuserSiAutreInstitution($acteur, $user->institution);
        $this->utilisateurs->reactiver($acteur, $user, $motif);
    }

    public function suspendre(User $acteur, Institution $institution, string $motif): void
    {
        $institution->forceFill(['statut' => StatutInstitution::Suspendue, 'motif_suspension' => $motif])->save();
        DB::table('sessions')->whereIn('user_id', $institution->utilisateurs()->select('id'))->delete();
        $this->journal->ecrire($acteur, 'institution.suspendue', 'Institution', $institution->id, null, ['motif' => $motif]);
    }

    /**
     * Fin de suspension : l'institution reprend le statut que justifie son dossier et sa convention.
     */
    public function reactiver(User $acteur, Institution $institution, string $motif): void
    {
        $statut = match (true) {
            $institution->conventions()->whereNotNull('signee_le')->exists() => StatutInstitution::Active,
            $institution->dossierComplet() => StatutInstitution::Verifiee,
            $institution->documents()->exists() => StatutInstitution::DossierEnCours,
            default => StatutInstitution::Invitee,
        };

        $institution->forceFill(['statut' => $statut, 'motif_suspension' => null])->save();
        $this->journal->ecrire($acteur, 'institution.reactivee', 'Institution', $institution->id, null, ['motif' => $motif, 'statut' => $statut->value]);
    }

    /**
     * @param  array{interlocuteur_nom: ?string, interlocuteur_email: ?string, interlocuteur_telephone: ?string}  $interlocuteur
     */
    public function definirInterlocuteur(User $acteur, Institution $institution, array $interlocuteur): void
    {
        $avant = $institution->only(array_keys($interlocuteur));
        $institution->forceFill($interlocuteur)->save();
        $this->journal->ecrire($acteur, 'institution.interlocuteur', 'Institution', $institution->id, $avant, $interlocuteur);
    }

    /** Un compte institutionnel n'agit que sur sa propre institution (le back-office, sur toutes). */
    private function refuserSiAutreInstitution(User $acteur, ?Institution $institution): void
    {
        if ($acteur->estInstitutionnel() && $acteur->institution_id !== $institution?->id) {
            abort(403);
        }
    }
}
