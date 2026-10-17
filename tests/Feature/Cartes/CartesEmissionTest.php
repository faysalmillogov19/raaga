<?php

namespace Tests\Feature\Cartes;

use App\Enums\MethodeAllocation;
use App\Enums\ModeSaisie;
use App\Enums\PayeurFrais;
use App\Enums\PublicAutorise;
use App\Enums\RegleArret;
use App\Enums\RegleSiEchec;
use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutCarte;
use App\Enums\StatutKyc;
use App\Enums\StatutReservationBoutique;
use App\Enums\StatutTranche;
use App\Enums\TypeCarte;
use App\Enums\TypeFraisSouscription;
use App\Models\Boutique;
use App\Models\Carte;
use App\Models\Duree;
use App\Models\LigneRegistre;
use App\Models\ReservationBoutique;
use App\Models\Tranche;
use App\Models\User;
use App\Notifications\CarteEmise;
use App\Notifications\PieceBientotExpiree;
use App\Services\Boutiques\ReservationsBoutiques;
use App\Services\Cartes\Cartes;
use App\Services\Kyc\DossiersIdentite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * Cartes d'accès Raaga : émission COMMERÇANT (attribution), ACTIONNAIRE (registre), PERSONNEL (SUPER_ADMIN) ; lien avec
 * le dossier d'identité (conforme, en attente, douteux), mise à jour des emplacements, révocations et réémission,
 * validité bornée par la pièce d'identité, échéances.
 */
class CartesEmissionTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerActeurs();
        $this->admin = User::factory()->role(Role::Admin)->create();
        Notification::fake();
    }

    private function citoyen(StatutKyc $kyc = StatutKyc::Valide, ?string $piece = null): User
    {
        return User::factory()->role(Role::Citoyen)->create(['statut_kyc' => $kyc, 'nom' => 'OUEDRAOGO', 'prenoms' => 'Awa',
            'piece_expire_le' => $piece ?? now()->addYears(10)->toDateString(),
            'identite_validee' => $kyc === StatutKyc::Valide ? ['nom' => 'OUEDRAOGO', 'prenoms' => 'Awa'] : null]);
    }

    /** Réservation réservée, avance payée, puis attribuée par le VALIDATEUR (contrat signé à cette date). */
    private function attribuer(User $citoyen, Boutique $boutique, string $signeLe): ReservationBoutique
    {
        $campagne = Boutiques::campagneOuverte();
        $boutique->forceFill(['statut' => StatutBoutique::Reservee])->save();
        $r = ReservationBoutique::create(['reference' => 'BTQ-'.strtoupper(substr(md5((string) $boutique->id), 0, 8)), 'user_id' => $citoyen->id,
            'campagne_boutiques_id' => $campagne->id, 'boutique_id' => $boutique->id, 'caution' => 540_000, 'avance' => 135_000, 'mois_avance' => 3,
            'statut' => StatutReservationBoutique::Reservee, 'expire_le' => now()->subDay(), 'avance_payee_le' => now(), 'reference_avance' => 'PAY-AV']);
        app(ReservationsBoutiques::class)->attribuer($r, $signeLe, $this->validateur);

        return $r->fresh();
    }

    private function inscrireAuRegistre(User $citoyen, int $actions = 40): void
    {
        $tranche = Tranche::query()->first() ?? Tranche::create([
            'statut' => StatutTranche::Ouverte, 'nom' => 'Tranche 1', 'valeur_unitaire' => 10_000, 'min_actions_par_souscription' => 1,
            'objectif_montant' => 1_000_000_000, 'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addDays(30),
            'publics_autorises' => [PublicAutorise::ResidentBf->value], 'mode_saisie' => ModeSaisie::NbActions, 'souscriptions_multiples' => true,
            'regle_arret' => RegleArret::ArretAObjectif, 'methode_allocation' => MethodeAllocation::Prorata, 'regle_si_echec' => RegleSiEchec::RemboursementIntegral,
            'delai_remboursement_jours' => 30, 'frais_paiement_supportes_par' => PayeurFrais::Raaga, 'frais_souscription_type' => TypeFraisSouscription::Aucun,
            'frais_souscription_valeur' => 0, 'canaux_actifs' => ['ORANGE_MONEY'], 'delai_paiement_valeur' => 48, 'delai_paiement_duree_id' => Duree::parCode('HEURE')->id,
            'delai_paiement_secondes' => 172_800, 'version' => 1, 'cree_par' => $this->regulateur->id,
        ]);
        LigneRegistre::create(['numero_certificat' => 'CB-'.$citoyen->id.'-'.$actions, 'user_id' => $citoyen->id, 'tranche_id' => $tranche->id,
            'nb_actions' => $actions, 'numero_action_debut' => ($debut = (int) LigneRegistre::query()->max('numero_action_fin') + 1),
            'numero_action_fin' => $debut + $actions - 1, 'date_inscription' => now()]);
    }

    public function test_commercant_emise_a_la_premiere_attribution_et_mise_a_jour_a_chaque_boutique(): void
    {
        $citoyen = $this->citoyen();
        Boutiques::campagneOuverte();
        $this->attribuer($citoyen, Boutiques::boutique('A', 1), now()->subMonths(2)->toDateString());

        $carte = Cartes::courante($citoyen, TypeCarte::Commercant);
        $this->assertSame(StatutCarte::Active, $carte->statut);
        $this->assertMatchesRegularExpression('/^RG-C-\d{6}$/', $carte->numero);
        $this->assertGreaterThanOrEqual(60, strlen($carte->jeton));
        $this->assertSame('Awa OUEDRAOGO', $carte->contenu['nom']);
        $this->assertSame([['code' => Boutiques::boutique('A', 1)->code, 'site' => 'Marché central']], $carte->contenu['emplacements']);
        // Validité : fin du contrat le plus lointain (signature + durée paramétrée).
        $this->assertSame(now()->subMonths(2)->addYears(5)->toDateString(), $carte->valide_jusqu_au->toDateString());
        Notification::assertSentTo($citoyen, CarteEmise::class);

        // Deuxième boutique, contrat plus récent : même carte, emplacements et validité mis à jour, nouvelle version.
        $this->attribuer($citoyen, Boutiques::boutique('A', 2), now()->toDateString());
        $carte2 = Cartes::courante($citoyen, TypeCarte::Commercant);
        $this->assertSame($carte->id, $carte2->id);
        $this->assertCount(2, $carte2->contenu['emplacements']);
        $this->assertSame(now()->addYears(5)->toDateString(), $carte2->valide_jusqu_au->toDateString());
        $this->assertSame(2, $carte2->version);
        // Une carte par personne et par type.
        $this->assertSame(1, Carte::query()->where('user_id', $citoyen->id)->where('type', TypeCarte::Commercant)->count());
    }

    public function test_validite_jamais_au_dela_de_la_piece_et_avertissement_30_jours_avant(): void
    {
        $citoyen = $this->citoyen(StatutKyc::Valide, now()->addDays(20)->toDateString());
        Boutiques::campagneOuverte();
        $this->attribuer($citoyen, Boutiques::boutique('A', 1), now()->toDateString());
        $this->assertSame(now()->addDays(20)->toDateString(), Cartes::courante($citoyen, TypeCarte::Commercant)->valide_jusqu_au->toDateString());

        $this->artisan('raaga:cartes')->assertSuccessful();
        Notification::assertSentTo($citoyen, PieceBientotExpiree::class);
        $this->assertNotNull($citoyen->fresh()->piece_avertie_le);
        // Une seule fois.
        $this->artisan('raaga:cartes')->assertSuccessful();
        Notification::assertSentToTimes($citoyen, PieceBientotExpiree::class, 1);
    }

    public function test_en_attente_du_kyc_puis_emise_quand_l_admin_marque_le_dossier_conforme(): void
    {
        $citoyen = $this->citoyen(StatutKyc::EnAttente);
        Boutiques::campagneOuverte();
        $this->attribuer($citoyen, Boutiques::boutique('A', 1), now()->toDateString());
        $this->assertSame(StatutCarte::EnAttente, Cartes::courante($citoyen, TypeCarte::Commercant)->statut);
        Notification::assertNotSentTo($citoyen, CarteEmise::class);
        $this->actingAs($citoyen)->get(route('espace.carte'))->assertOk()->assertSee('En attente de vérification d’identité');

        // L'ADMIN marque le dossier conforme : carte émise, identité validée figée, titulaire notifié.
        app(DossiersIdentite::class)->conforme($citoyen, $this->admin, null, now()->addYears(6)->toDateString());
        $carte = Cartes::courante($citoyen, TypeCarte::Commercant);
        $this->assertSame(StatutCarte::Active, $carte->statut);
        $this->assertSame(['nom' => 'OUEDRAOGO', 'prenoms' => 'Awa'], array_intersect_key($citoyen->fresh()->identite_validee, ['nom' => 1, 'prenoms' => 1]));
        Notification::assertSentTo($citoyen, CarteEmise::class);
        $this->actingAs($citoyen)->get(route('espace.carte'))->assertOk()->assertSee($carte->numero)->assertDontSee('En attente de vérification');
    }

    public function test_identite_douteuse_pas_de_carte_commercant_revoquee_actionnaire_suspendue_puis_regularisee(): void
    {
        // Sans carte : identité douteuse confirmée, aucune carte émise.
        $sans = $this->citoyen(StatutKyc::Rejete);
        $this->inscrireAuRegistre($sans);
        $this->assertNull(Cartes::courante($sans, TypeCarte::Actionnaire));

        // Commerçant et actionnaire avec cartes actives.
        $citoyen = $this->citoyen();
        Boutiques::campagneOuverte();
        $this->attribuer($citoyen, Boutiques::boutique('A', 1), now()->toDateString());
        $this->inscrireAuRegistre($citoyen);
        $commercant = Cartes::courante($citoyen, TypeCarte::Commercant);
        $actionnaire = Cartes::courante($citoyen, TypeCarte::Actionnaire);

        $proposition = app(DossiersIdentite::class)->douteux($citoyen, $this->admin, 'Photo de la CNIB retouchée');
        app(DossiersIdentite::class)->confirmerAnnulation($proposition, $this->validateur);
        $this->assertSame(StatutCarte::Revoquee, $commercant->fresh()->statut);
        $this->assertNull(Cartes::courante($citoyen, TypeCarte::Commercant));
        $this->assertSame(StatutCarte::Suspendue, $actionnaire->fresh()->statut);
        // Ses actions restent inscrites.
        $this->assertSame(40, (int) LigneRegistre::query()->where('user_id', $citoyen->id)->sum('nb_actions'));

        // Régularisation (dossier de nouveau conforme) : carte actionnaire réactivée.
        $citoyen->fresh()->forceFill(['statut_kyc' => StatutKyc::Valide])->save();
        $this->assertSame(StatutCarte::Active, $actionnaire->fresh()->statut);
    }

    public function test_actionnaire_a_la_premiere_inscription_sans_nombre_d_actions_ni_montant_renouvelee(): void
    {
        $citoyen = $this->citoyen();
        $this->inscrireAuRegistre($citoyen, 75);
        $carte = Cartes::courante($citoyen, TypeCarte::Actionnaire);
        $this->assertSame(StatutCarte::Active, $carte->statut);
        $this->assertSame(sprintf('RG-A-%06d', $citoyen->id), $carte->contenu['numero_actionnaire']);
        $this->assertStringNotContainsString('75', json_encode($carte->contenu));
        $this->assertSame(now()->addYear()->toDateString(), $carte->valide_jusqu_au->toDateString());

        // Échéance : renouvelée d'un an tant qu'il détient des actions.
        $this->travelTo(now()->addYear()->addDay());
        $this->artisan('raaga:cartes')->assertSuccessful();
        $this->assertSame(StatutCarte::Active, $carte->fresh()->statut);
        $this->assertSame(now()->addYear()->toDateString(), $carte->fresh()->valide_jusqu_au->toDateString());
    }

    public function test_personnel_emise_par_le_super_admin_reemise_et_revoquee_a_la_desactivation(): void
    {
        $agent = User::factory()->role(Role::ControleurPaiements)->create(['name' => 'Issa Sawadogo']);
        $this->en($this->regulateur)->post(route('admin.utilisateurs.carte', $agent))->assertForbidden();
        $this->en($this->superAdmin)->post(route('admin.utilisateurs.carte', $agent))->assertSessionHas('succes');
        $carte = Cartes::courante($agent, TypeCarte::Personnel);
        $this->assertSame(StatutCarte::Active, $carte->statut);
        $this->assertSame('Contrôleur des paiements', $carte->contenu['fonction']);
        $this->assertSame(now()->addYear()->toDateString(), $carte->valide_jusqu_au->toDateString());
        $this->en($agent)->get(route('admin.carte'))->assertOk()->assertSee($carte->numero)->assertSee('Contrôleur des paiements');

        // Réémission (perte) : ancienne révoquée, nouveau numéro et nouveau jeton.
        $this->en($this->superAdmin)->post(route('admin.utilisateurs.carte', $agent))->assertSessionHas('succes');
        $nouvelle = Cartes::courante($agent, TypeCarte::Personnel);
        $this->assertNotSame($carte->numero, $nouvelle->numero);
        $this->assertNotSame($carte->jeton, $nouvelle->jeton);
        $this->assertSame(StatutCarte::Revoquee, $carte->fresh()->statut);
        $this->assertSame($carte->id, $nouvelle->remplace_carte_id);

        // Compte désactivé : carte révoquée.
        $this->en($this->superAdmin)->post(route('admin.utilisateurs.desactiver', $agent), ['motif' => 'Départ de l’agent'])->assertSessionHas('succes');
        $this->assertSame(StatutCarte::Revoquee, $nouvelle->fresh()->statut);
    }
}
