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

    // File « À valider » : tout ce qui attend une décision du VALIDATEUR, décision sur une ligne ou une sélection.
    case AValiderVoir = 'a_valider.voir';

    // « Mes brouillons » : brouillons et rejetés de l'auteur (GESTIONNAIRE, RÉGULATEUR), à soumettre au VALIDATEUR.
    case BrouillonsVoir = 'brouillons.voir';

    case ReferentielsVoir = 'referentiels.voir';
    case ReferentielsGerer = 'referentiels.gerer';

    case TranchesVoir = 'tranches.voir';
    case TranchesSaisir = 'tranches.saisir';
    case TranchesConfirmer = 'tranches.confirmer';
    case TranchesPiloter = 'tranches.piloter';

    // Référentiel des sites du marché et de leurs boutiques (GESTIONNAIRE DES SITES ET BOUTIQUES saisit, VALIDATEUR confirme).
    case SitesVoir = 'sites.voir';
    // Capacités prévues des sites : distinct de « sites.voir », que le VALIDATEUR garde sans les capacités (16/10/2026).
    case CapacitesVoir = 'sites.capacites_voir';
    // Référencement › Boutiques (liste et fiches des boutiques de tous les sites) : distinct de « sites.voir », que le
    // SUPER_ADMIN garde sans les boutiques (30/10/2026).
    case BoutiquesReferentielVoir = 'sites.boutiques_voir';
    case SitesGerer = 'sites.gerer';
    case SitesConfirmer = 'sites.confirmer';

    // Onglet « Référentiels » (types de boutiques, niveaux, zones) : GESTIONNAIRE DES SITES ET BOUTIQUES, SUPER_ADMIN en
    // lecture. Le VALIDATEUR décide depuis « À valider », sans l'onglet.
    case ReferentielsBoutiquesVoir = 'referentiels_boutiques.voir';

    // Onglet « Modalités de paiement » (moyens et délais de paiement) : RÉGULATEUR, SUPER_ADMIN.
    case ModalitesPaiementVoir = 'modalites_paiement.voir';
    // SUPER_ADMIN (25/10/2026) : mise à jour directe, sans validation, de toutes les entités créées par les seeders de
    // référentiel (« par défaut ») : délais de paiement, durées, moyens de paiement, types de boutiques, unités de loyer,
    // niveaux, zones. Ni création ni suppression ; le circuit saisie puis confirmation reste pour tout le reste.
    case InstallationMettreAJour = 'installation.mettre_a_jour';

    // Unités de loyer (référentiel de la grille tarifaire) : RÉGULATEUR saisit, VALIDATEUR confirme.
    case UnitesVoir = 'unites.voir';
    case UnitesGerer = 'unites.gerer';
    case UnitesConfirmer = 'unites.confirmer';

    // Moyens de paiement (souscriptions, cautions, dividendes) : RÉGULATEUR saisit, VALIDATEUR confirme.
    case MoyensPaiementVoir = 'moyens_paiement.voir';
    case MoyensPaiementGerer = 'moyens_paiement.gerer';
    case MoyensPaiementConfirmer = 'moyens_paiement.confirmer';

    // Référencement : grille tarifaire en lecture (la saisie et la confirmation suivent les droits des campagnes),
    // journal des imports (sites et boutiques).
    case GrilleVoir = 'grille.voir';
    case ImportsVoir = 'imports.voir';

    // Attribution des boutiques (indépendante des actions) : campagnes, cautions, tirage au sort.
    case BoutiquesVoir = 'boutiques.voir';
    case BoutiquesSaisir = 'boutiques.saisir';
    case BoutiquesConfirmer = 'boutiques.confirmer';
    case BoutiquesPiloter = 'boutiques.piloter';

    // Avances des boutiques en retard, au cas par cas : liste (statuts ; montants seulement avec BoutiquesVoir),
    // proposition par le RÉGULATEUR, décision par le VALIDATEUR (quatre yeux).
    case AvancesVoir = 'avances.voir';
    case AvancesProposer = 'avances.proposer';
    case AvancesDecider = 'avances.decider';

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
    // Cartes d'accès : vérifier une carte scannée (photo, nom, type, statut), l'imprimer au guichet.
    case CartesVerifier = 'cartes.verifier';
    case CartesImprimer = 'cartes.imprimer';
    // Agent de guichet : faire souscrire des actions à une personne présente (compte, identité validée sur place,
    // souscription et encaissement définitif) et tenir sa caisse du jour.
    case GuichetSouscrire = 'guichet.souscrire';
    // ADMIN : demande de révocation (motif), réémission après perte, photos du personnel ; VALIDATEUR : confirme la révocation.
    case CartesGerer = 'cartes.gerer';
    case CartesRevocationConfirmer = 'cartes.revocation_confirmer';

    case ConformiteVoir = 'conformite.voir';
    case ConformiteTraiter = 'conformite.traiter';
    case ConformiteObserver = 'conformite.observer';

    case SouscriptionsVoir = 'souscriptions.voir';
    case SouscriptionsRapprocher = 'souscriptions.rapprocher';

    // RAAGA Invest : manifestations d'intérêt (simulations non engageantes), en lecture et export (RÉGULATEUR seul).
    case InteretsVoir = 'interets.voir';
    // Contrôle des paiements (CONTRÔLEUR DES PAIEMENTS) : dossiers, relances, suivi des remboursements.
    case PaiementsVoir = 'paiements.voir';
    case RelancesEnvoyer = 'relances.envoyer';
    case RemboursementsSuivre = 'remboursements.suivre';
    // Décisions du VALIDATEUR dans « À valider » : corrections de paiements confirmés, annulations pour identité douteuse.
    case PaiementsCorrectionsConfirmer = 'paiements.corrections_confirmer';
    case KycAnnulationConfirmer = 'kyc.annulation_confirmer';
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
            self::BoutiquesSaisir, self::BoutiquesConfirmer, self::BoutiquesPiloter, self::AvancesProposer, self::AvancesDecider,
            self::SitesGerer, self::SitesConfirmer, self::UnitesGerer, self::UnitesConfirmer, self::MoyensPaiementGerer, self::MoyensPaiementConfirmer,
            self::DividendesSaisir, self::DividendesConfirmer, self::DividendesPayer,
            self::SimulationsLancer, self::AllocationExecuter,
            self::InstallationMettreAJour,
            self::KycTraiter, self::ConformiteTraiter, self::KycAnnulationConfirmer,
            self::CartesImprimer, self::CartesGerer, self::CartesRevocationConfirmer, self::GuichetSouscrire,
            self::SouscriptionsRapprocher, self::RemboursementsApprouver, self::RemboursementsSuivre, self::RelancesEnvoyer,
            self::PaiementsCorrectionsConfirmer,
            self::ObservationsRepondre,
        ], true);
    }

    /**
     * Droits de saisie de paramètres (RÉGULATEUR) · jamais cumulables avec la confirmation.
     *
     * @return list<self>
     */
    public static function saisies(): array
    {
        return [self::TranchesSaisir, self::DividendesSaisir, self::ConventionsSaisir, self::BoutiquesSaisir, self::SitesGerer, self::UnitesGerer, self::MoyensPaiementGerer, self::AvancesProposer];
    }

    /**
     * Droits de confirmation et d'exécution (VALIDATEUR).
     *
     * @return list<self>
     */
    public static function confirmationsEtExecutions(): array
    {
        return [self::TranchesConfirmer, self::TranchesPiloter, self::DividendesConfirmer, self::DividendesPayer, self::AllocationExecuter,
            self::ConventionsConfirmer, self::AppelsFondsRapprocher, self::KybVerifier, self::BoutiquesConfirmer, self::BoutiquesPiloter, self::SitesConfirmer, self::UnitesConfirmer, self::MoyensPaiementConfirmer,
            self::PaiementsCorrectionsConfirmer, self::RemboursementsApprouver, self::KycAnnulationConfirmer, self::AvancesDecider, self::CartesRevocationConfirmer];
    }

    /**
     * Couples « proposition / confirmation » jamais cumulés par un même profil (quatre yeux).
     *
     * @return list<array{0: self, 1: self}>
     */
    public static function quatreYeux(): array
    {
        return [
            [self::TranchesSaisir, self::TranchesConfirmer], [self::DividendesSaisir, self::DividendesConfirmer],
            [self::SouscriptionsRapprocher, self::PaiementsCorrectionsConfirmer], [self::RemboursementsSuivre, self::RemboursementsApprouver],
            [self::KycTraiter, self::KycAnnulationConfirmer], [self::AvancesProposer, self::AvancesDecider],
            [self::CartesGerer, self::CartesRevocationConfirmer],
        ];
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
            self::BoutiquesSaisir, self::BoutiquesConfirmer, self::BoutiquesPiloter, self::AvancesProposer, self::AvancesDecider,
            self::SitesGerer, self::SitesConfirmer, self::UnitesGerer, self::UnitesConfirmer, self::MoyensPaiementGerer, self::MoyensPaiementConfirmer,
            self::DividendesSaisir, self::DividendesConfirmer, self::DividendesPayer,
            self::SimulationsLancer, self::AllocationExecuter,
            // KYC : l'ADMIN marque le dossier (sans aucun montant), hors de cette liste ; le VALIDATEUR confirme l'annulation.
            self::ConformiteTraiter, self::KycAnnulationConfirmer,
            self::SouscriptionsRapprocher, self::RemboursementsApprouver, self::RemboursementsSuivre, self::PaiementsCorrectionsConfirmer,
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
            // Support et assistance + comptes back-office, approbations, sécurité ; lecture seule sur les paramètres
            // (boutiques, référentiels) et le journal d'audit. Depuis le 25/10/2026, aucun accès aux interfaces de
            // souscription et d'investissement (tranches, dividendes, simulations, registre des actions A, démarches du
            // site public), réservées au RÉGULATEUR et au VALIDATEUR, sauf la mise à jour directe de toutes les entités
            // créées par les seeders de référentiel.
            Role::SuperAdmin => [
                ...self::support(),
                self::TextesLegauxPublier,
                self::PlateformeModifierTout,
                self::InstitutionsInterlocuteur,
                self::ApprobationsTraiter,
                self::UtilisateursGerer,
                self::SecuriteGerer, self::ConnexionsVoir, self::CharteVoir,
                self::AuditVoir,
                // Depuis le 30/10/2026 : ni sites, ni grille tarifaire, ni capacités, ni imports, ni boutiques (référentiel et
                // campagnes). Restent les référentiels créés à l'installation (types, niveaux, zones, unités, paiements).
                self::ReferentielsVoir,
                self::UnitesVoir, self::MoyensPaiementVoir,
                self::ReferentielsBoutiquesVoir, self::ModalitesPaiementVoir, self::InstallationMettreAJour,
            ],
            // Support et assistance. Depuis le 25/10/2026, plus la liste des manifestations d'intérêt RAAGA Invest :
            // interfaces de souscription et d'investissement réservées au RÉGULATEUR et au VALIDATEUR.
            Role::Admin => [...self::support(),
                // Vérifications d'identité : voit la pièce, marque le dossier conforme ou douteux (aucun montant).
                self::KycVoir, self::KycVoirPieces, self::KycTraiter, self::CartesVerifier, self::CartesGerer],
            // Confirme les paramétrages et exécute ; ne saisit jamais un paramètre. Droits recentrés le 16/10/2026 : plus
            // d'écran Démarches du site public, Référentiels, Capacités prévues, Grille tarifaire (décidés et consultés dans
            // « À valider »), ni Vérifications d'identité (ADMIN), Souscriptions et Remboursements (CONTRÔLEUR DES PAIEMENTS).
            Role::Validateur => [
                self::TableauDeBordVoir,
                self::PhaseConfirmer, self::AValiderVoir,
                self::TranchesVoir, self::TranchesConfirmer, self::TranchesPiloter,
                self::BoutiquesVoir, self::BoutiquesConfirmer, self::BoutiquesPiloter, self::AvancesVoir, self::AvancesDecider,
                // Référentiels, unités, moyens et délais de paiement : décidés depuis « À valider » (détail et différences), sans les onglets.
                self::SitesVoir, self::BoutiquesReferentielVoir, self::SitesConfirmer, self::ImportsVoir, self::UnitesConfirmer,
                self::MoyensPaiementVoir, self::MoyensPaiementConfirmer,
                self::DividendesVoir, self::DividendesConfirmer, self::DividendesPayer,
                self::SimulationsVoir, self::SimulationsLancer, self::AllocationExecuter,
                // KYC : confirme seulement l'annulation d'un dossier douteux, pièces vues depuis « À valider » (le dossier est à l'ADMIN).
                self::KycVoirPieces, self::KycAnnulationConfirmer,
                // Cartes d'accès : confirme ou rejette la révocation manuelle demandée par l'ADMIN.
                self::CartesRevocationConfirmer,
                self::ConformiteVoir, self::ConformiteTraiter,
                // Corrections et remboursements : décidés dans « À valider » ; les écrans sont au CONTRÔLEUR DES PAIEMENTS.
                self::PaiementsCorrectionsConfirmer, self::RemboursementsApprouver,
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
                self::TableauDeBordVoir, self::BrouillonsVoir,
                self::SitesVoir, self::BoutiquesReferentielVoir, self::CapacitesVoir, self::SitesGerer, self::ImportsVoir, self::ReferentielsBoutiquesVoir,
                // Pas de « Cautions à rembourser » (17/10/2026) : la retenue et le remboursement ne relèvent pas du référentiel.
            ],
            // Rapproche, marque les paiements manuels, relance, suit les remboursements ; aucune grille, aucun montant,
            // aucun moyen de paiement ; ses corrections et remboursements sont confirmés par le VALIDATEUR.
            Role::ControleurPaiements => [
                self::TableauDeBordVoir,
                self::PaiementsVoir, self::SouscriptionsRapprocher, self::RelancesEnvoyer,
                self::RemboursementsVoir, self::RemboursementsSuivre,
            ],
            // Agent de guichet (profil du back-office) : vérifie les cartes scannées, consulte le dossier du titulaire
            // (aucun montant) et imprime sa carte après contrôle de sa pièce d'identité ; fait souscrire des actions à une
            // personne présente (compte, identité validée sur place, encaissement) et tient sa caisse du jour.
            Role::AgentGuichet => [self::TableauDeBordVoir, self::CartesVerifier, self::CartesImprimer, self::GuichetSouscrire],
            Role::Regulateur => [
                self::TableauDeBordVoir, self::BrouillonsVoir, self::InteretsVoir,
                self::PhaseVoir, self::PhaseProposer,
                self::ReferentielsVoir,
                self::TranchesVoir, self::TranchesSaisir,
                self::BoutiquesVoir, self::BoutiquesSaisir, self::AvancesVoir, self::AvancesProposer,
                self::SitesVoir, self::BoutiquesReferentielVoir, self::CapacitesVoir, self::GrilleVoir, self::UnitesVoir, self::UnitesGerer, self::MoyensPaiementVoir, self::MoyensPaiementGerer, self::ModalitesPaiementVoir,
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
