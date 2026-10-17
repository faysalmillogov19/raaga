<?php

namespace Tests\Feature\Admin;

use App\Enums\Droit;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La matrice des droits respecte la répartition : le RÉGULATEUR saisit, le VALIDATEUR confirme
 * et exécute ; SUPER_ADMIN et ADMIN assurent le support, sans aucune décision financière.
 */
class MatriceDroitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_quatre_yeux_aucun_profil_ne_cumule_saisie_et_confirmation(): void
    {
        foreach (Role::cases() as $role) {
            $droits = Droit::pour($role);

            foreach (Droit::quatreYeux() as [$saisie, $confirmation]) {
                $this->assertFalse(
                    in_array($saisie, $droits, true) && in_array($confirmation, $droits, true),
                    "Le profil {$role->value} cumule {$saisie->value} et {$confirmation->value}.",
                );
            }
        }
    }

    public function test_le_regulateur_saisit_mais_ne_confirme_ni_n_execute_jamais(): void
    {
        $regulateur = User::factory()->role(Role::Regulateur)->create();

        foreach ([Droit::TranchesSaisir, Droit::DividendesSaisir, Droit::SimulationsLancer, Droit::RegistreExporter,
            Droit::AuditExporter, Droit::RapportsGenerer, Droit::ObservationsEmettre, Droit::KycVoir] as $droit) {
            $this->assertTrue($regulateur->can($droit->value), $droit->value);
        }

        foreach ([...Droit::confirmationsEtExecutions(), Droit::KycVoirPieces, Droit::KycTraiter, Droit::ConformiteTraiter,
            Droit::SouscriptionsRapprocher, Droit::RemboursementsApprouver, Droit::UtilisateursGerer, Droit::ReferentielsGerer] as $droit) {
            $this->assertFalse($regulateur->can($droit->value), $droit->value);
        }
    }

    public function test_le_validateur_confirme_et_execute_mais_ne_saisit_jamais(): void
    {
        $validateur = User::factory()->role(Role::Validateur)->create();

        foreach (Droit::confirmationsEtExecutions() as $droit) {
            $this->assertTrue($validateur->can($droit->value), $droit->value);
        }

        foreach ([...Droit::saisies(), Droit::UtilisateursGerer, Droit::ReferentielsGerer, Droit::ConnexionsVoir,
            Droit::RegistreExporter, Droit::AuditExporter, Droit::RapportsGenerer, Droit::ObservationsEmettre] as $droit) {
            $this->assertFalse($validateur->can($droit->value), $droit->value);
        }
    }

    public function test_les_profils_de_support_n_ont_aucun_droit_d_ecriture_financier(): void
    {
        foreach ([Role::SuperAdmin, Role::Admin] as $role) {
            $user = User::factory()->role($role)->create();

            foreach ([...Droit::financiers(), ...Droit::saisies(), ...Droit::confirmationsEtExecutions()] as $droit) {
                $this->assertFalse($user->can($droit->value), "{$role->value} : {$droit->value}");
            }
        }

        // Pièces d'identité : l'ADMIN les voit (vérifications d'identité), jamais le SUPER_ADMIN.
        foreach ([Droit::KycVoir, Droit::KycVoirPieces, Droit::KycTraiter] as $droit) {
            $this->assertFalse(User::factory()->role(Role::SuperAdmin)->create()->can($droit->value), $droit->value);
        }
    }

    public function test_le_super_admin_fait_tout_ce_que_fait_l_admin_plus_approbations_securite_et_comptes(): void
    {
        $superAdmin = User::factory()->role(Role::SuperAdmin)->create();

        foreach ([...Droit::support(), Droit::TextesLegauxPublier, Droit::ApprobationsTraiter, Droit::SecuriteGerer,
            Droit::UtilisateursGerer, Droit::ConnexionsVoir, Droit::AuditVoir,
            Droit::ParametresVoir, Droit::TranchesVoir, Droit::DividendesVoir, Droit::SimulationsVoir, Droit::ReferentielsVoir] as $droit) {
            $this->assertTrue($superAdmin->can($droit->value), $droit->value);
        }

        // Plus de lecture des opérations (KYC, alertes, souscriptions, registre) ni des observations.
        foreach ([Droit::KycVoir, Droit::ConformiteVoir, Droit::SouscriptionsVoir, Droit::RemboursementsVoir, Droit::RegistreVoir,
            Droit::ObservationsVoir, Droit::ObservationsRepondre, Droit::AuditExporter] as $droit) {
            $this->assertFalse($superAdmin->can($droit->value), $droit->value);
        }
    }

    public function test_l_admin_n_a_que_les_droits_de_support(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        // Support, plus la liste des manifestations d'intérêt RAAGA Invest et les vérifications d'identité (que le SUPER_ADMIN n'a pas).
        foreach (Droit::cases() as $droit) {
            $this->assertSame(in_array($droit, [...Droit::support(), Droit::InteretsVoir, Droit::KycVoir, Droit::KycVoirPieces, Droit::KycTraiter, Droit::CartesVerifier], true),
                $admin->can($droit->value), $droit->value);
        }
        $this->assertFalse(User::factory()->role(Role::SuperAdmin)->create()->can(Droit::InteretsVoir->value));
    }

    public function test_les_autres_profils_n_ont_aucun_droit_back_office(): void
    {
        foreach ([Role::Citoyen, Role::AgentGuichet, Role::Conformite, Role::Auditeur, Role::Investisseur, Role::PorteurProjet] as $role) {
            $user = User::factory()->role($role)->create();

            foreach (Droit::cases() as $droit) {
                $this->assertFalse($user->can($droit->value), "{$role->value} : {$droit->value}");
            }
        }
    }

    public function test_un_compte_desactive_perd_tous_ses_droits(): void
    {
        $regulateur = User::factory()->role(Role::Regulateur)->create(['actif' => false]);

        foreach (Droit::cases() as $droit) {
            $this->assertFalse($regulateur->can($droit->value));
        }
    }
}
