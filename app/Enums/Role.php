<?php

namespace App\Enums;

enum Role: string
{
    // Rôles d'origine de la plateforme.
    // Profils de support du back-office : aucune décision financière.
    case SuperAdmin = 'superadmin';
    case Admin = 'admin';
    case PorteurProjet = 'porteur_projet';
    case Investisseur = 'investisseur';
    case Visiteur = 'visiteur';

    // Rôles de la souscription populaire (jalon 1).
    case Citoyen = 'citoyen';
    case AgentGuichet = 'agent_guichet';
    // Saisit et soumet tous les paramètres (souscription, dividendes) ; ne confirme ni n’exécute jamais.
    case Regulateur = 'regulateur';
    // Confirme les paramétrages saisis par le régulateur (règle des 4 yeux) et exécute les opérations.
    case Validateur = 'validateur';
    // Tient le référentiel des sites et des boutiques (saisie et soumission) ; le VALIDATEUR confirme.
    case GestionnaireSites = 'gestionnaire_sites';
    // Rapproche et contrôle les paiements, relance, suit les remboursements ; ne valide jamais ses propres corrections.
    case ControleurPaiements = 'controleur_paiements';
    // Sans droit, masqué des listes d'attribution (le KYC revient à l'ADMIN).
    case Conformite = 'conformite';
    case Auditeur = 'auditeur';

    // Investisseurs institutionnels (actions A) : espace /institutionnel, rattachés à une institution.
    // Signe la convention, déclare les virements, gère les utilisateurs de son institution.
    case InstitutionSignataire = 'institution_signataire';
    // Consulte seulement.
    case InstitutionLecteur = 'institution_lecteur';

    /**
     * Libellé lisible du rôle.
     */
    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super administrateur',
            self::Admin => 'Administrateur',
            self::PorteurProjet => 'Porteur de projet',
            self::Investisseur => 'Investisseur',
            self::Visiteur => 'Visiteur',
            self::Citoyen => 'Citoyen',
            self::AgentGuichet => 'Agent de guichet',
            self::Regulateur => 'Régulateur',
            self::Validateur => 'Validateur',
            self::GestionnaireSites => 'Gestionnaire des sites et boutiques',
            self::ControleurPaiements => 'Contrôleur des paiements',
            self::Conformite => 'Conformité',
            self::Auditeur => 'Auditeur',
            self::InstitutionSignataire => 'Signataire (institution)',
            self::InstitutionLecteur => 'Lecteur (institution)',
        };
    }

    /**
     * Description des droits du rôle (reprise dans la table roles).
     */
    public function description(): ?string
    {
        return match ($this) {
            self::SuperAdmin => 'Support et assistance, comptes back-office, approbations, sécurité. Lecture seule sur les paramètres et le journal d’audit ; aucune décision financière.',
            self::Admin => 'Support et assistance : aide aux utilisateurs, demandes, contenus du site, préparation des textes légaux ; vérification d’identité (KYC) des citoyens, sans aucun montant. Aucune décision financière.',
            self::Citoyen => "S'inscrire, déposer ses pièces, souscrire, payer, consulter son espace.",
            self::AgentGuichet => 'À venir : encaisser au guichet (le CONTRÔLEUR DES PAIEMENTS vérifie). Espace /guichet encore vide ; masqué des listes d’attribution.',
            self::Regulateur => 'Saisit et soumet les paramètres de souscription des tranches et de distribution des dividendes, lance les simulations, exporte et émet des observations. Ne confirme ni n’exécute jamais.',
            self::Validateur => 'Confirme ou rejette les paramétrages, ouvre, prolonge et clôture les tranches, exécute l’allocation et la mise en paiement des dividendes ; confirme les corrections de paiement, les remboursements et les annulations pour identité douteuse. Ne saisit jamais un paramètre.',
            self::GestionnaireSites => 'Crée et met à jour les sites du marché et leurs boutiques (numéro, bloc, surface, type), puis les soumet au VALIDATEUR. Ne fixe ni cautions ni campagnes.',
            self::ControleurPaiements => 'Rapproche les paiements avec les souscriptions et les cautions, marque les paiements manuels (reçu, incomplet, rejeté), relance et suit les remboursements. Ne touche ni aux grilles, ni aux montants, ni aux moyens de paiement ; ne valide jamais ses propres corrections.',
            self::Conformite => 'Sans droit (le KYC revient à l’ADMIN) ; masqué des listes d’attribution.',
            self::Auditeur => "Lecture seule sur tout, y compris le journal d'audit.",
            self::InstitutionSignataire => 'Signe la convention de souscription des actions A, déclare les virements des appels de fonds et gère les utilisateurs de son institution.',
            self::InstitutionLecteur => 'Consulte les informations de son institution (convention, appels de fonds, actions A, dividendes).',
            self::PorteurProjet, self::Investisseur, self::Visiteur => null,
        };
    }

    /**
     * Segment d'URL de l'espace du rôle (ex. /guichet/dashboard).
     */
    public function prefix(): string
    {
        return match ($this) {
            self::SuperAdmin => 'superadmin',
            // « /admin » est réservé au back-office.
            self::Admin => 'administrateur',
            self::PorteurProjet => 'porteur',
            self::Investisseur => 'investisseur',
            self::Visiteur => 'visiteur',
            self::Citoyen => 'citoyen',
            self::AgentGuichet => 'guichet',
            self::Regulateur => 'regulation',
            self::Validateur => 'validation',
            self::GestionnaireSites => 'sites',
            self::ControleurPaiements => 'paiements',
            self::Conformite => 'conformite',
            self::Auditeur => 'audit',
            self::InstitutionSignataire, self::InstitutionLecteur => 'institutionnel',
        };
    }

    /**
     * Nom de la route du tableau de bord propre au rôle.
     */
    public function dashboardRoute(): string
    {
        return match (true) {
            $this->estBackoffice() => 'admin.tableau',
            $this->estInstitutionnel() => 'institutionnel.tableau',
            default => $this->prefix().'.dashboard',
        };
    }

    /**
     * Profils du back-office (/admin) : connexion par e-mail + mot de passe + TOTP.
     *
     * @return list<self>
     */
    public static function backoffice(): array
    {
        return [self::SuperAdmin, self::Admin, self::Validateur, self::Regulateur, self::GestionnaireSites, self::ControleurPaiements];
    }

    public function estBackoffice(): bool
    {
        return in_array($this, self::backoffice(), true);
    }

    /**
     * Profils des investisseurs institutionnels (espace /institutionnel, connexion avec TOTP).
     *
     * @return list<self>
     */
    public static function institutionnels(): array
    {
        return [self::InstitutionSignataire, self::InstitutionLecteur];
    }

    public function estInstitutionnel(): bool
    {
        return in_array($this, self::institutionnels(), true);
    }

    /** Espace à connexion renforcée (back-office ou institutionnel). */
    public function estEspaceSecurise(): bool
    {
        return $this->estBackoffice() || $this->estInstitutionnel();
    }

    /**
     * Libellé court du badge de rôle dans le back-office.
     */
    public function badge(): string
    {
        return match ($this) {
            self::SuperAdmin => 'SUPER_ADMIN',
            self::Admin => 'ADMIN',
            self::Validateur => 'VALIDATEUR',
            self::Regulateur => 'RÉGULATEUR',
            self::GestionnaireSites => 'GESTIONNAIRE SITES',
            self::ControleurPaiements => 'CONTRÔLEUR PAIEMENTS',
            default => mb_strtoupper($this->label()),
        };
    }

    /**
     * Classe CSS du badge de rôle (couleur propre à chaque profil back-office).
     */
    public function badgeClasse(): string
    {
        return match ($this) {
            self::SuperAdmin => 'bo-badge-superadmin',
            self::Admin => 'bo-badge-admin',
            self::Validateur => 'bo-badge-validateur',
            self::Regulateur => 'bo-badge-regulateur',
            self::GestionnaireSites => 'bo-badge-sites',
            self::ControleurPaiements => 'bo-badge-paiements',
            default => '',
        };
    }

    /**
     * Comptes grand public : les citoyens souscripteurs et les agents de guichet.
     *
     * @return list<self>
     */
    public static function grandPublic(): array
    {
        return [self::Citoyen, self::AgentGuichet];
    }

    /**
     * Profils masqués des listes d'attribution (sans écran ni droit pour l'instant) : ni invitation, ni changement de
     * profil, ni inscription. Les comptes qui les portent déjà sont conservés tels quels.
     *
     * @return list<self>
     */
    public static function masques(): array
    {
        return [self::Conformite, self::AgentGuichet];
    }

    /**
     * Profils back-office proposés à l'invitation et au changement de profil.
     *
     * @return list<self>
     */
    public static function attribuablesBackoffice(): array
    {
        return array_values(array_filter(self::backoffice(), fn (self $r) => ! in_array($r, self::masques(), true)));
    }

    /**
     * Rôles qu'un utilisateur peut choisir lui-même à l'inscription.
     *
     * @return list<self>
     */
    public static function selfRegisterable(): array
    {
        return [self::Citoyen, self::PorteurProjet, self::Investisseur, self::Visiteur];
    }
}
