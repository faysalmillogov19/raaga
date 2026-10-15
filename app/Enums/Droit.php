<?php

namespace App\Enums;

/**
 * Droits du back-office. Chaque droit est une Gate Laravel du même nom, vérifiée côté serveur
 * (middleware « can:… » sur les routes et contrôle explicite dans chaque action).
 *
 * Répartition : le RÉGULATEUR saisit et soumet les paramètres, le VALIDATEUR les confirme et
 * exécute les opérations. SUPER_ADMIN et ADMIN sont des profils de support et d'assistance :
 * aucune décision financière, lecture seule sur les paramètres et les opérations.
 */
enum Droit: string
{
    case TableauDeBordVoir = 'tableau_de_bord.voir';

    // Support et assistance (ADMIN, SUPER_ADMIN).
    case AssistanceVoir = 'assistance.voir';
    case AssistanceAgir = 'assistance.agir';
    case DemandesTraiter = 'demandes.traiter';
    case ContenusGerer = 'contenus.gerer';
    case TextesLegauxRediger = 'textes_legaux.rediger';
    case TextesLegauxPublier = 'textes_legaux.publier';
    case ApprobationsTraiter = 'approbations.traiter';
    case SecuriteGerer = 'securite.gerer';
    case ParametresVoir = 'parametres.voir';

    // Investisseurs institutionnels (actions A).
    case InstitutionsGerer = 'institutions.gerer';
    case InstitutionsInterlocuteur = 'institutions.interlocuteur';
    case KybVoir = 'kyb.voir';
    case KybVerifier = 'kyb.verifier';
    case ConventionsVoir = 'conventions.voir';
    case ConventionsSaisir = 'conventions.saisir';
    case ConventionsConfirmer = 'conventions.confirmer';
    case AppelsFondsVoir = 'appels_fonds.voir';
    case AppelsFondsRapprocher = 'appels_fonds.rapprocher';
    case RegistreAVoir = 'registre_a.voir';
    case RegistreAExporter = 'registre_a.exporter';

    // Paramètres de la plateforme (informations publiques).
    case PlateformeVoir = 'plateforme.voir';
    case PlateformeModifier = 'plateforme.modifier';
    case PlateformeModifierTout = 'plateforme.modifier_tout';

    case UtilisateursGerer = 'utilisateurs.gerer';
    case ConnexionsVoir = 'connexions.voir';
    // Page de contrôle visuel de la charte du back-office (SUPER_ADMIN).
    case CharteVoir = 'charte.voir';

    // Décisions de phase du site public (présentation des actions, souscription) : SUPER_ADMIN propose, VALIDATEUR confirme.
    case PhaseVoir = 'phase.voir';
    case PhaseProposer = 'phase.proposer';
    case PhaseConfirmer = 'phase.confirmer';

    case ReferentielsVoir = 'referentiels.voir';
    case ReferentielsGerer = 'referentiels.gerer';

    case TranchesVoir = 'tranches.voir';
    case TranchesSaisir = 'tranches.saisir';
    case TranchesConfirmer = 'tranches.confirmer';
    case TranchesPiloter = 'tranches.piloter';

    // Référentiel des sites du marché et de leurs boutiques (GESTIONNAIRE DES SITES ET BOUTIQUES saisit, VALIDATEUR confirme).
    case SitesVoir = 'sites.voir';
    case SitesGerer = 'sites.gerer';
    case SitesConfirmer = 'sites.confirmer';

    // Référencement : grille tarifaire en lecture (la saisie et la confirmation suivent les droits des campagnes),
    // journal des imports (sites et boutiques).
    case GrilleVoir = 'grille.voir';
    case ImportsVoir = 'imports.voir';

    // Attribution des boutiques (indépendante des actions) : campagnes, cautions, tirage au sort.
    case BoutiquesVoir = 'boutiques.voir';
    case BoutiquesSaisir = 'boutiques.saisir';
    case BoutiquesConfirmer = 'boutiques.confirmer';
    case BoutiquesPiloter = 'boutiques.piloter';

    case DividendesVoir = 'dividendes.voir';
    case DividendesSaisir = 'dividendes.saisir';
    case DividendesConfirmer = 'dividendes.confirmer';
    case DividendesPayer = 'dividendes.payer';

    case SimulationsVoir = 'simulations.voir';
    case SimulationsLancer = 'simulations.lancer';
    case AllocationExecuter = 'allocation.executer';

    case KycVoir = 'kyc.voir';
    case KycVoirPieces = 'kyc.voir_pieces';
    case KycTraiter = 'kyc.traiter';

    case ConformiteVoir = 'conformite.voir';
    case ConformiteTraiter = 'conformite.traiter';
    case ConformiteObserver = 'conformite.observer';

    case SouscriptionsVoir = 'souscriptions.voir';
    case SouscriptionsRapprocher = 'souscriptions.rapprocher';
    case RemboursementsVoir = 'remboursements.voir';
    case RemboursementsApprouver = 'remboursements.approuver';

    case RegistreVoir = 'registre.voir';
    case RegistreExporter = 'registre.exporter';

    case AuditVoir = 'audit.voir';
    case AuditExporter = 'audit.exporter';

    case RapportsGenerer = 'rapports.generer';
    case ObservationsVoir = 'observations.voir';
    case ObservationsEmettre = 'observations.emettre';
    case ObservationsRepondre = 'observations.repondre';

    public function label(): string
    {
        // Les clés contiennent un point (« tranches.voir ») : lecture directe du tableau de traductions.
        return __('admin.droits')[$this->value] ?? $this->value;
    }

    /**
     * Section du back-office à laquelle le droit se rapporte (préfixe avant le point).
     */
    public function section(): string
    {
        return strtok($this->value, '.');
    }

    /**
     * Le droit modifie-t-il des données métier ? (Exports, rapports et observations n'en modifient pas.)
     */
    public function ecriture(): bool
    {
        return in_array($this, [
            self::AssistanceAgir, self::DemandesTraiter, self::ContenusGerer,
            self::TextesLegauxRediger, self::TextesLegauxPublier, self::ApprobationsTraiter, self::SecuriteGerer,
            self::PlateformeModifier, self::PlateformeModifierTout, self::PhaseProposer, self::PhaseConfirmer,
            self::InstitutionsGerer, self::InstitutionsInterlocuteur, self::KybVerifier,
            self::ConventionsSaisir, self::ConventionsConfirmer, self::AppelsFondsRapprocher,
            self::UtilisateursGerer, self::ReferentielsGerer,
            self::TranchesSaisir, self::TranchesConfirmer, self::TranchesPiloter,
            self::BoutiquesSaisir, self::BoutiquesConfirmer, self::BoutiquesPiloter,
            self::SitesGerer, self::SitesConfirmer,
            self::DividendesSaisir, self::DividendesConfirmer, self::DividendesPayer,
            self::SimulationsLancer, self::AllocationExecuter,
            self::KycTraiter, self::ConformiteTraiter,
            self::SouscriptionsRapprocher, self::RemboursementsApprouver,
            self::ObservationsRepondre,
        ], true);
    }

    /**
     * Droits de saisie de paramètres (RÉGULATEUR) — jamais cumulables avec la confirmation.
     *
     * @return list<self>
     */
    public static function saisies(): array
    {
        return [self::TranchesSaisir, self::DividendesSaisir, self::ConventionsSaisir, self::BoutiquesSaisir, self::SitesGerer];
    }

    /**
     * Droits de confirmation et d'exécution (VALIDATEUR).
     *
     * @return list<self>
     */
    public static function confirmationsEtExecutions(): array
    {
        return [self::TranchesConfirmer, self::TranchesPiloter, self::DividendesConfirmer, self::DividendesPayer, self::AllocationExecuter,
            self::ConventionsConfirmer, self::AppelsFondsRapprocher, self::KybVerifier, self::BoutiquesConfirmer, self::BoutiquesPiloter, self::SitesConfirmer];
    }

    /**
     * Droits communs aux profils de support (ADMIN et SUPER_ADMIN).
     *
     * @return list<self>
     */
    public static function support(): array
    {
        return [
            self::TableauDeBordVoir,
            self::AssistanceVoir, self::AssistanceAgir,
            self::DemandesTraiter,
            self::ContenusGerer,
            self::TextesLegauxRediger,
            self::PlateformeVoir, self::PlateformeModifier,
            self::InstitutionsGerer,
        ];
    }

    /**
     * Droits d'écriture sur les paramètres et les opérations financières : jamais pour les profils de support.
     *
     * @return list<self>
     */
    public static function financiers(): array
    {
        return [
            self::ReferentielsGerer,
            self::TranchesSaisir, self::TranchesConfirmer, self::TranchesPiloter,
            self::BoutiquesSaisir, self::BoutiquesConfirmer, self::BoutiquesPiloter,
            self::SitesGerer, self::SitesConfirmer,
            self::DividendesSaisir, self::DividendesConfirmer, self::DividendesPayer,
            self::SimulationsLancer, self::AllocationExecuter,
            self::KycTraiter, self::ConformiteTraiter,
            self::SouscriptionsRapprocher, self::RemboursementsApprouver,
            self::KybVerifier, self::ConventionsSaisir, self::ConventionsConfirmer, self::AppelsFondsRapprocher,
        ];
    }

    /**
     * Matrice des droits par profil.
     *
     * @return list<self>
     */
    public static function pour(Role $role): array
    {
        return match ($role) {
            // Support et assistance + comptes back-office, approbations, sécurité ; lecture seule sur
            // les paramètres (tranches, paiements, dividendes, simulations) et le journal d'audit.
            Role::SuperAdmin => [
                ...self::support(),
                self::TextesLegauxPublier,
                self::PlateformeModifierTout,
                self::InstitutionsInterlocuteur, self::RegistreAVoir,
                self::ApprobationsTraiter,
                self::UtilisateursGerer,
                self::SecuriteGerer, self::ConnexionsVoir, self::CharteVoir,
                self::PhaseVoir, self::PhaseProposer,
                self::AuditVoir,
                self::ParametresVoir, self::TranchesVoir, self::BoutiquesVoir, self::DividendesVoir, self::SimulationsVoir, self::ReferentielsVoir,
                self::SitesVoir, self::GrilleVoir, self::ImportsVoir,
            ],
            // Support et assistance uniquement.
            Role::Admin => self::support(),
            // Confirme les paramétrages et exécute ; ne saisit jamais un paramètre.
            Role::Validateur => [
                self::TableauDeBordVoir,
                self::PhaseVoir, self::PhaseConfirmer,
                self::ReferentielsVoir,
                self::TranchesVoir, self::TranchesConfirmer, self::TranchesPiloter,
                self::BoutiquesVoir, self::BoutiquesConfirmer, self::BoutiquesPiloter,
                self::SitesVoir, self::SitesConfirmer, self::GrilleVoir, self::ImportsVoir,
                self::DividendesVoir, self::DividendesConfirmer, self::DividendesPayer,
                self::SimulationsVoir, self::SimulationsLancer, self::AllocationExecuter,
                self::KycVoir, self::KycVoirPieces, self::KycTraiter,
                self::ConformiteVoir, self::ConformiteTraiter,
                self::SouscriptionsVoir, self::SouscriptionsRapprocher,
                self::RemboursementsVoir, self::RemboursementsApprouver,
                self::RegistreVoir,
                self::AuditVoir,
                self::ObservationsVoir, self::ObservationsRepondre,
                self::KybVoir, self::KybVerifier,
                self::ConventionsVoir, self::ConventionsConfirmer,
                self::AppelsFondsVoir, self::AppelsFondsRapprocher,
                self::RegistreAVoir,
            ],
            // Saisit et soumet tous les paramètres ; ne confirme ni n'exécute jamais.
            // Référentiel des sites et des boutiques uniquement : saisie et soumission, jamais de confirmation.
            Role::GestionnaireSites => [
                self::TableauDeBordVoir,
                self::SitesVoir, self::SitesGerer, self::ImportsVoir,
            ],
            Role::Regulateur => [
                self::TableauDeBordVoir,
                self::ReferentielsVoir,
                self::TranchesVoir, self::TranchesSaisir,
                self::BoutiquesVoir, self::BoutiquesSaisir,
                self::SitesVoir, self::GrilleVoir,
                self::DividendesVoir, self::DividendesSaisir,
                self::SimulationsVoir, self::SimulationsLancer,
                self::KycVoir,
                self::ConformiteVoir, self::ConformiteObserver,
                self::SouscriptionsVoir,
                self::RemboursementsVoir,
                self::RegistreVoir, self::RegistreExporter,
                self::AuditVoir, self::AuditExporter,
                self::RapportsGenerer,
                self::ObservationsVoir, self::ObservationsEmettre,
                self::KybVoir,
                self::ConventionsVoir, self::ConventionsSaisir,
                self::AppelsFondsVoir,
                self::RegistreAVoir, self::RegistreAExporter,
            ],
            default => [],
        };
    }
}
