<?php

namespace App\Enums;

use App\Models\User;

/**
 * Espaces à connexion renforcée (e-mail + mot de passe + TOTP, verrouillage, session de 8 h,
 * déconnexion après inactivité) : le back-office et l'espace des investisseurs institutionnels.
 * Les mêmes contrôleurs et le même middleware servent les deux espaces.
 */
enum Espace: string
{
    case Backoffice = 'backoffice';
    case Institutionnel = 'institutionnel';

    /** Préfixe des noms de route : « admin.connexion », « institutionnel.connexion »… */
    public function prefixe(): string
    {
        return match ($this) {
            self::Backoffice => 'admin',
            self::Institutionnel => 'institutionnel',
        };
    }

    public function route(string $suffixe): string
    {
        return $this->prefixe().'.'.$suffixe;
    }

    /** Clé de session de l'espace (TOTP validé, début, dernière activité). */
    public function cleSession(): string
    {
        return $this->value;
    }

    public function accueil(): string
    {
        return $this->route('tableau');
    }

    /** Nom affiché sur les écrans de connexion. */
    public function nom(): string
    {
        return __($this === self::Backoffice ? 'admin.nom' : 'institutionnel.nom');
    }

    /** Fichier de traduction des textes de connexion (titre, introduction). */
    public function textes(): string
    {
        return $this === self::Backoffice ? 'admin.connexion' : 'institutionnel.connexion';
    }

    /**
     * Le compte peut-il ouvrir une session dans cet espace ? (Rôle ; institution non suspendue.)
     */
    public function autorise(User $user): bool
    {
        return match ($this) {
            self::Backoffice => $user->estBackoffice(),
            self::Institutionnel => $user->estInstitutionnel() && $user->institution !== null
                && ! $user->institution->estSuspendue(),
        };
    }

    public static function pour(User $user): ?self
    {
        return match (true) {
            $user->estBackoffice() => self::Backoffice,
            $user->estInstitutionnel() => self::Institutionnel,
            default => null,
        };
    }
}
