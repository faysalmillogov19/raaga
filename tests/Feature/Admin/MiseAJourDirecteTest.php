<?php

namespace Tests\Feature\Admin;

use App\Enums\DecisionParametrage;
use App\Enums\Role;
use App\Enums\StatutTypeBoutique;
use App\Models\AuditLog;
use App\Models\Duree;
use App\Models\Niveau;
use App\Models\TypeBoutique;
use App\Models\Unite;
use App\Models\ZoneSite;
use App\Models\MoyenPaiement;
use App\Models\ParametrageDecision;
use App\Models\ParametresPaiements;
use Database\Seeders\DureesSeeder;
use Database\Seeders\MoyensPaiementSeeder;
use Database\Seeders\ParametresPaiementsSeeder;
use Database\Seeders\ReferentielsSitesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/**
 * SUPER_ADMIN (25/10/2026) : mise à jour directe, sans VALIDATEUR, de toutes les entités créées par les seeders de
 * référentiel (délais de paiement, durées, moyens de paiement, types de boutiques, unités, niveaux, zones). Ni création ni suppression ; refusée si une proposition attend ;
 * aucun autre profil n'a ce droit. Le circuit RÉGULATEUR saisit, VALIDATEUR confirme reste inchangé.
 */
class MiseAJourDirecteTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([DureesSeeder::class, ParametresPaiementsSeeder::class, MoyensPaiementSeeder::class, ReferentielsSitesSeeder::class]);
    }

    private function delais(int $caution = 96): array
    {
        return ['delai_caution_valeur' => $caution, 'delai_caution_duree' => 'HEURE', 'delai_complement_valeur' => 48, 'delai_complement_duree' => 'HEURE',
            'delai_avance_valeur' => 30, 'delai_avance_duree' => 'JOUR', 'delai_kyc_valeur' => 48, 'delai_kyc_duree' => 'HEURE'];
    }

    private function moyen(array $surcharges = []): array
    {
        return ['libelle' => 'Orange Money (nouveau libellé)', 'famille' => 'MOBILE_MONEY', 'instructions' => 'Composez le code reçu.', 'ordre' => 2,
            'usages' => ['ACTIONS' => ['autorise' => '1', 'min' => '1000', 'max' => ''], 'CAUTION' => ['autorise' => '1', 'min' => '', 'max' => '']], ...$surcharges];
    }

    public function test_le_super_admin_met_a_jour_les_delais_qui_s_appliquent_aussitot(): void
    {
        $superAdmin = $this->connecterBackoffice(Role::SuperAdmin);
        $this->assertSame(72 * 3_600, ParametresPaiements::delaiSecondes('caution'));

        $this->get(route('admin.parametres-paiements'))->assertOk()->assertSee('Mettre à jour directement');
        $this->put(route('admin.parametres-paiements.direct'), $this->delais(96))->assertSessionHas('succes');

        // En vigueur tout de suite : version confirmée à son nom, avec le motif, et journal.
        $this->assertSame(96 * 3_600, ParametresPaiements::delaiSecondes('caution'));
        $fiche = ParametresPaiements::fiche();
        $decision = ParametrageDecision::query()->where('objet_type', 'delais')->latest('id')->firstOrFail();
        $this->assertSame([DecisionParametrage::Confirme, $superAdmin->id, 'Mise à jour directe par le SUPER_ADMIN', $fiche->version],
            [$decision->decision, $decision->decideur_id, $decision->motif, $decision->version]);
        $this->assertTrue(AuditLog::query()->where('action', 'delais.mise_a_jour_directe')->where('acteur_id', $superAdmin->id)->exists());
    }

    public function test_la_mise_a_jour_directe_est_refusee_si_une_proposition_attend_le_validateur(): void
    {
        $this->connecterBackoffice(Role::Regulateur);
        $this->post(route('admin.parametres-paiements.reviser'));
        $this->put(route('admin.parametres-paiements.update'), $this->delais(100))->assertSessionHas('succes');
        $this->post(route('admin.parametres-paiements.soumettre'))->assertSessionHas('succes');
        $this->assertTrue(ParametresPaiements::fiche()->estSoumis());

        $this->app['auth']->forgetGuards();
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->get(route('admin.parametres-paiements'))->assertOk()->assertDontSee('Mettre à jour directement');
        $this->put(route('admin.parametres-paiements.direct'), $this->delais(10))
            ->assertSessionHas('erreur', 'Une proposition attend la décision du VALIDATEUR : la mise à jour directe l’écraserait. Attendez sa décision.');
        $this->assertSame(72 * 3_600, ParametresPaiements::delaiSecondes('caution'));
        $this->assertSame(100, ParametresPaiements::fiche()->delai_caution_valeur);
    }

    public function test_le_super_admin_met_a_jour_un_moyen_par_defaut_mais_pas_un_moyen_ajoute(): void
    {
        $superAdmin = $this->connecterBackoffice(Role::SuperAdmin);
        $orange = MoyenPaiement::query()->where('code', 'ORANGE_MONEY')->firstOrFail();
        $this->assertTrue($orange->par_defaut);

        $this->get(route('admin.referencement.zones.show', ['moyens', $orange->id]))->assertOk()->assertSee('Mettre à jour directement');
        $this->put(route('admin.referencement.zones.direct', ['moyens', $orange->id]), $this->moyen())->assertSessionHas('succes');

        // Appliqué tout de suite (libellé, plafond, désactivation), sans demande en attente ; version et journal.
        $orange->refresh();
        $this->assertSame(['Orange Money (nouveau libellé)', 1000, false, false, StatutTypeBoutique::Valide],
            [$orange->libelle, $orange->usage(\App\Enums\UsageMoyenPaiement::Actions)['min'], $orange->actif, $orange->aModificationEnAttente(), $orange->statut]);
        $this->assertSame('Mise à jour directe par le SUPER_ADMIN', $orange->versions()->first()?->motif);
        $this->assertTrue(AuditLog::query()->where('action', 'like', '%.mise_a_jour_directe')->where('acteur_id', $superAdmin->id)->where('entite_id', (string) $orange->id)->exists());

        // Moyen ajouté par le RÉGULATEUR (pas par le seeder) : refus, et pas de bouton.
        $ajoute = MoyenPaiement::create(['code' => 'NOUVEAU_MOYEN', 'libelle' => 'Nouveau', 'famille' => 'BANQUE', 'ordre' => 50, 'actif' => true,
            'par_defaut' => false, 'statut' => StatutTypeBoutique::Valide, 'usages' => []]);
        $this->get(route('admin.referencement.zones.show', ['moyens', $ajoute->id]))->assertOk()->assertDontSee('Mettre à jour directement');
        $this->put(route('admin.referencement.zones.direct', ['moyens', $ajoute->id]), $this->moyen(['libelle' => 'Essai']))
            ->assertSessionHas('erreur', 'Seuls les éléments créés à l’installation (par défaut) se mettent à jour directement.');
        $this->assertSame('Nouveau', $ajoute->fresh()->libelle);

        // Ni création ni suppression.
        $this->post(route('admin.referencement.zones.store', 'moyens'), ['code' => 'AUTRE', ...$this->moyen()])->assertForbidden();
        $this->delete(route('admin.referencement.zones.destroy', ['moyens', $orange->id]))->assertForbidden();
    }

    public function test_le_super_admin_met_a_jour_directement_types_unites_niveaux_et_zones_par_defaut(): void
    {
        $superAdmin = $this->connecterBackoffice(Role::SuperAdmin);

        // Type de boutique par défaut : libellé, description pour le citoyen, « réservable » et activation, sans demande.
        $boutique = TypeBoutique::query()->where('code', 'BOUT')->firstOrFail();
        $this->get(route('admin.sites.types.show', $boutique))->assertOk()->assertSee('Mettre à jour directement');
        $this->put(route('admin.sites.types.direct', $boutique), ['libelle' => 'Boutique standard', 'description' => 'Local fermé',
            'description_citoyen' => 'Local fermé, rideau métallique.', 'ordre' => 1, 'attribuable' => '1', 'actif' => '1'])->assertSessionHas('succes');
        $boutique->refresh();
        $this->assertSame(['Boutique standard', 'Local fermé, rideau métallique.', true, false], [$boutique->libelle, $boutique->description_citoyen, $boutique->actif, $boutique->aModificationEnAttente()]);
        $this->assertTrue(AuditLog::query()->where('action', 'type_boutique.mise_a_jour_directe')->where('acteur_id', $superAdmin->id)->exists());

        // Unité, niveau, zone par défaut.
        $m2 = Unite::query()->where('code', 'M2')->firstOrFail();
        $this->put(route('admin.referencement.zones.direct', ['unites', $m2->id]), ['libelle' => 'Au m² par mois', 'description' => 'Surface', 'actif' => '1'])->assertSessionHas('succes');
        $this->assertSame('Au m² par mois', $m2->fresh()->libelle);
        $r1 = Niveau::query()->where('code', 'R1')->firstOrFail();
        $this->put(route('admin.referencement.zones.direct', ['niveaux', $r1->id]), ['libelle' => 'Premier étage', 'rang' => 1, 'actif' => '1'])->assertSessionHas('succes');
        $this->assertSame('Premier étage', $r1->fresh()->libelle);
        $zone = ZoneSite::query()->where('code', 'REST')->firstOrFail();
        $this->get(route('admin.referencement.zones.show', ['zones', $zone->id]))->assertOk()->assertSee('Mettre à jour directement');
        $this->put(route('admin.referencement.zones.direct', ['zones', $zone->id]), ['libelle' => 'Espace restauration', 'ordre' => 7, 'description' => ''])->assertSessionHas('succes');
        $zone->refresh();
        $this->assertSame(['Espace restauration', false, 'Mise à jour directe par le SUPER_ADMIN'], [$zone->libelle, $zone->actif, $zone->versions()->first()?->motif]);

        // Élément ajouté ensuite (pas par le seeder) : refus.
        $ajout = ZoneSite::create(['code' => 'NEUF', 'libelle' => 'Nouvelle zone', 'ordre' => 50, 'actif' => true, 'par_defaut' => false, 'statut' => StatutTypeBoutique::Valide]);
        $this->put(route('admin.referencement.zones.direct', ['zones', $ajout->id]), ['libelle' => 'Essai', 'actif' => '1'])
            ->assertSessionHas('erreur', 'Seuls les éléments créés à l’installation (par défaut) se mettent à jour directement.');
    }

    public function test_le_super_admin_modifie_les_libelles_des_durees_mais_pas_leur_valeur(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $heure = Duree::parCode('HEURE');

        $this->get(route('admin.parametres-paiements'))->assertOk()->assertSee('Durées des délais')->assertSee('data-duree="HEURE"', false);
        $this->put(route('admin.parametres-paiements.duree', $heure), ['libelle' => 'heure pleine', 'libelle_pluriel' => 'heures pleines', 'secondes' => 1])
            ->assertSessionHas('succes');
        $heure->refresh();
        $this->assertSame(['heure pleine', 'heures pleines', 3_600], [$heure->libelle, $heure->libelle_pluriel, $heure->secondes]);
        $this->assertTrue(AuditLog::query()->where('action', 'duree.mise_a_jour_directe')->exists());

        // Les autres profils ne voient pas la section et ne peuvent rien modifier.
        $this->app['auth']->forgetGuards();
        $this->connecterBackoffice(Role::Regulateur);
        $this->get(route('admin.parametres-paiements'))->assertOk()->assertDontSee('Durées des délais');
        $this->put(route('admin.parametres-paiements.duree', $heure), ['libelle' => 'x', 'libelle_pluriel' => 'x'])->assertForbidden();
    }

    public function test_menu_du_super_admin_parametres_generaux_d_administration(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);

        // Menu « Paramètres » : intertitre « Paramètres d'administration » (six entrées), puis « Paramètres de la plateforme ».
        $this->get('/admin')->assertOk()->assertSee('aria-controls="sous-menu-parametres_generaux"', false)
            ->assertSee('data-intertitre="parametres_administration"', false)->assertSee('adm-sous-entree-separee', false)
            ->assertSeeInOrder(['<span class="adm-libelle">Paramètres</span>', 'Paramètres d’administration', 'Délais de paiement', 'Moyens de paiement',
                'Types de boutiques', 'Unités', 'Niveaux', 'Zones', 'Paramètres de la plateforme', 'Utilisateurs back-office'], false)
            ->assertDontSee('href="'.route('admin.sites').'"', false)->assertDontSee('href="'.route('admin.boutiques.grilles').'"', false)
            ->assertDontSee('href="'.route('admin.parametres').'"', false);
    }

    public static function autresProfils(): array
    {
        return ['ADMIN' => [Role::Admin], 'REGULATEUR' => [Role::Regulateur], 'VALIDATEUR' => [Role::Validateur], 'CONTROLEUR' => [Role::ControleurPaiements]];
    }

    #[DataProvider('autresProfils')]
    public function test_aucun_autre_profil_n_a_la_mise_a_jour_directe(Role $role): void
    {
        $this->connecterBackoffice($role);
        $orange = MoyenPaiement::query()->where('code', 'ORANGE_MONEY')->firstOrFail();

        $this->put(route('admin.parametres-paiements.direct'), $this->delais(5))->assertForbidden();
        $this->put(route('admin.referencement.zones.direct', ['moyens', $orange->id]), $this->moyen())->assertForbidden();
        $this->put(route('admin.sites.types.direct', TypeBoutique::query()->where('code', 'BOUT')->firstOrFail()), ['libelle' => 'x', 'ordre' => 1])->assertForbidden();
        $this->put(route('admin.referencement.zones.direct', ['unites', Unite::query()->where('code', 'M2')->firstOrFail()->id]), ['libelle' => 'x'])->assertForbidden();
        $this->assertSame(72 * 3_600, ParametresPaiements::delaiSecondes('caution'));
        $this->assertSame('Orange Money', $orange->fresh()->libelle);
    }

    public function test_le_super_admin_n_a_plus_l_ecran_des_demarches_du_site_public(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);

        $this->get(route('admin.phase'))->assertForbidden();
        $this->get(route('admin.tableau'))->assertOk()->assertDontSee(route('admin.phase'), false);
    }
}
