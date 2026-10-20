<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutSite;
use App\Enums\StatutTypeBoutique;
use App\Http\Controllers\Admin\CapacitesController;
use App\Models\MoyenPaiement;
use App\Models\Niveau;
use App\Models\ParametresPaiements;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use App\Models\ZoneSite;
use Database\Seeders\MoyensPaiementSeeder;
use Database\Seeders\ReferentielsSitesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/**
 * Navigation du back-office : onglet « Référentiels » (types, niveaux, zones) pour le GESTIONNAIRE DES SITES ET BOUTIQUES
 * et le SUPER_ADMIN en lecture ; onglet « Modalités de paiement » (moyens, délais) pour le RÉGULATEUR et le SUPER_ADMIN
 * en lecture ; unités près de la grille tarifaire ; filtre par site des capacités ; « À valider » intact pour le VALIDATEUR.
 */
class NavigationReferentielsModalitesTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private User $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
        $this->seed([ReferentielsSitesSeeder::class, MoyensPaiementSeeder::class]);
        $this->gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
    }

    /** Un compte de chaque profil du back-office. @return array<string, User> */
    private function profils(): array
    {
        return [
            'SUPER_ADMIN' => $this->superAdmin, 'ADMIN' => User::factory()->role(Role::Admin)->create(), 'VALIDATEUR' => $this->validateur,
            'REGULATEUR' => $this->regulateur, 'GESTIONNAIRE_SITES' => $this->gestionnaire, 'CONTROLEUR_PAIEMENTS' => User::factory()->role(Role::ControleurPaiements)->create(),
        ];
    }

    /** Menu dépliant de la barre latérale, ou chaîne vide. */
    private function menu(string $html, string $cle): string
    {
        return preg_match('#<li class="adm-deroulant" x-data="menuDeroulant\(\{ deplie: \w+, cle: \''.$cle.'\'.*?</ul>\s*</div>\s*</li>#s', $html, $m) ? $m[0] : '';
    }

    public function test_referentiels_visibles_et_accessibles_selon_le_profil(): void
    {
        $type = TypeBoutique::query()->firstOrFail();
        $pages = [route('admin.sites.types'), route('admin.sites.types.show', $type), route('admin.referencement.niveaux'), route('admin.referencement.zones'),
            route('admin.referencement.zones.show', ['niveaux', Niveau::query()->value('id')]), route('admin.referencement.zones.show', ['zones', ZoneSite::query()->value('id')])];

        foreach ($this->profils() as $nom => $user) {
            $autorise = in_array($nom, ['SUPER_ADMIN', 'GESTIONNAIRE_SITES'], true);
            $menu = $this->menu($this->en($user)->get(route('admin.tableau'))->assertOk()->getContent(), 'referentiels');
            if ($autorise) {
                foreach (['Types de boutiques', 'Niveaux', 'Zones'] as $libelle) {
                    $this->assertStringContainsString($libelle, $menu, $nom);
                }
                $this->assertStringNotContainsString('Unités', $menu, $nom);
            } else {
                $this->assertSame('', $menu, $nom);
            }
            foreach ($pages as $url) {
                $this->en($user)->get($url)->assertStatus($autorise ? 200 : 403);
            }
        }
    }

    public function test_modalites_de_paiement_visibles_et_accessibles_selon_le_profil(): void
    {
        $pages = [route('admin.referencement.moyens'), route('admin.referencement.zones.show', ['moyens', MoyenPaiement::parCode('ORANGE_MONEY')->id]), route('admin.parametres-paiements')];

        foreach ($this->profils() as $nom => $user) {
            $autorise = in_array($nom, ['SUPER_ADMIN', 'REGULATEUR'], true);
            $menu = $this->menu($this->en($user)->get(route('admin.tableau'))->assertOk()->getContent(), 'modalites');
            if ($autorise) {
                $this->assertStringContainsString('Modalités de paiement', $menu, $nom);
                $this->assertStringContainsString('href="'.route('admin.referencement.moyens').'"', $menu, $nom);
                $this->assertStringContainsString('href="'.route('admin.parametres-paiements').'"', $menu, $nom);
            } else {
                $this->assertSame('', $menu, $nom);
            }
            foreach ($pages as $url) {
                $this->en($user)->get($url)->assertStatus($autorise ? 200 : 403);
            }
        }

        // Page d'un sous-menu : menu déplié, sous-menu surligné ; état mémorisé.
        $menu = $this->menu($this->en($this->regulateur)->get(route('admin.parametres-paiements'))->getContent(), 'modalites');
        $this->assertStringContainsString('aria-expanded="true"', $menu);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('admin.parametres-paiements'), '#').'"[^>]*aria-current="page"#', $menu);
        $this->en($this->regulateur)->postJson(route('admin.preferences.menu'), ['menu' => 'modalites', 'ouvert' => true])->assertOk();
        $this->assertStringContainsString('aria-expanded="true"', $this->menu($this->en($this->regulateur)->get(route('admin.tableau'))->getContent(), 'modalites'));
    }

    public function test_le_super_admin_lit_sans_aucun_bouton_ni_ecriture(): void
    {
        $niveau = Niveau::query()->firstOrFail();
        $type = TypeBoutique::query()->firstOrFail();
        $orange = MoyenPaiement::parCode('ORANGE_MONEY');
        $sa = $this->superAdmin;

        // Référentiels.
        $this->en($sa)->get(route('admin.sites.types'))->assertOk()->assertDontSee('Ajouter le type');
        $this->en($sa)->get(route('admin.referencement.niveaux'))->assertOk()->assertDontSee('Ajouter un niveau');
        $this->en($sa)->get(route('admin.referencement.zones.show', ['niveaux', $niveau->id]))->assertOk()->assertDontSee('Désactiver')->assertDontSee('Valider');
        $this->en($sa)->post(route('admin.sites.types.store'), ['code' => 'NEW', 'libelle' => 'Nouveau'])->assertForbidden();
        $this->en($sa)->put(route('admin.sites.types.update', $type), ['libelle' => 'X', 'ordre' => 1])->assertForbidden();
        $this->en($sa)->post(route('admin.referencement.zones.store', 'niveaux'), ['code' => 'R7', 'libelle' => 'R+7', 'rang' => 7])->assertForbidden();
        $this->en($sa)->post(route('admin.referencement.zones.desactiver', ['niveaux', $niveau->id]))->assertForbidden();
        $this->en($sa)->post(route('admin.referencement.zones.confirmer', ['niveaux', $niveau->id]))->assertForbidden();

        // Modalités de paiement.
        $this->en($sa)->get(route('admin.referencement.moyens'))->assertOk()->assertDontSee('Ajouter un moyen de paiement');
        $this->en($sa)->get(route('admin.referencement.zones.show', ['moyens', $orange->id]))->assertOk()->assertDontSee('Désactiver')->assertDontSee('<dialog id="modifier-element"', false);
        $this->en($sa)->get(route('admin.parametres-paiements'))->assertOk()->assertDontSee('id="modifier-delais"', false)->assertDontSee('Soumettre');
        $this->en($sa)->post(route('admin.referencement.zones.store', 'moyens'), ['code' => 'SANK', 'libelle' => 'Sank'])->assertForbidden();
        $this->en($sa)->post(route('admin.referencement.zones.desactiver', ['moyens', $orange->id]))->assertForbidden();
        $this->en($sa)->put(route('admin.parametres-paiements.update'), ['delai_caution_valeur' => 72, 'delai_caution_duree' => 'HEURE', 'delai_complement_valeur' => 24, 'delai_complement_duree' => 'HEURE', 'delai_avance_valeur' => 30, 'delai_avance_duree' => 'JOUR', 'delai_kyc_valeur' => 48, 'delai_kyc_duree' => 'HEURE'])->assertForbidden();
        $this->en($sa)->post(route('admin.parametres-paiements.soumettre'))->assertForbidden();
        $this->en($sa)->post(route('admin.parametres-paiements.confirmer'))->assertForbidden();
        $this->assertSame([true, 48 * 3_600], [$orange->fresh()->actif, ParametresPaiements::delaiSecondes('complement')]);
    }

    public function test_unites_pres_de_la_grille_tarifaire_pour_le_regulateur(): void
    {
        $html = $this->en($this->regulateur)->get(route('admin.tableau'))->assertOk()->getContent();
        $lien = 'href="'.route('admin.referencement.unites').'"';
        $this->assertStringContainsString($lien, $html);
        $this->assertGreaterThan(strpos($html, 'href="'.route('admin.boutiques.grilles').'"'), strpos($html, $lien));
        $this->assertStringNotContainsString(route('admin.referencement.unites'), $this->menu($html, 'referentiels'));
        $this->en($this->regulateur)->get(route('admin.referencement.unites'))->assertOk()->assertSee('Ajouter une unité');
        $this->en($this->superAdmin)->get(route('admin.referencement.unites'))->assertOk()->assertDontSee('Ajouter une unité');
    }

    public function test_filtre_par_site_des_capacites_retenu_pendant_la_session(): void
    {
        $type = TypeBoutique::query()->where('code', 'BOUT')->firstOrFail();
        $site = fn (string $code, string $nom) => Site::create(['statut' => StatutSite::Brouillon, 'code' => $code, 'nom' => $nom, 'region' => 'Centre',
            'commune' => 'Ouagadougou', 'adresse' => 'Avenue', 'version' => 1, 'cree_par' => $this->gestionnaire->id]);
        $raa = $site('RAA', 'Marché Raaga');
        $sar = $site('SAR', 'Marché de Sarfalao');
        $raa->capacites()->create(['niveau' => 'RC', 'zone' => 'A', 'type_boutique_id' => $type->id, 'nombre_prevu' => 12, 'source' => 'Plan RAA']);
        $raa->capacites()->create(['niveau' => 'R1', 'zone' => 'B', 'type_boutique_id' => $type->id, 'nombre_prevu' => 30, 'source' => 'Plan RAA']);
        $sar->capacites()->create(['niveau' => 'RC', 'zone' => 'A', 'type_boutique_id' => $type->id, 'nombre_prevu' => 7, 'source' => 'Plan SAR']);
        $url = route('admin.referencement.capacites');
        $lignes = fn ($reponse) => substr_count($reponse->getContent(), 'Plan RAA').'/'.substr_count($reponse->getContent(), 'Plan SAR');

        // Filtre en haut de la liste : tous les sites, puis un site.
        $page = $this->en($this->gestionnaire)->get($url)->assertOk()->assertSee('<option value="">Tous les sites</option>', false);
        $this->assertSame('2/1', $lignes($page));
        $this->assertSame('0/1', $lignes($this->en($this->gestionnaire)->get($url.'?site='.$sar->id)));

        // Dernier site retenu pendant la session : la page sans paramètre le réapplique.
        $page = $this->en($this->gestionnaire)->get($url)->assertOk()->assertSee('<option value="'.$sar->id.'" selected>SAR · Marché de Sarfalao</option>', false);
        $this->assertSame('0/1', $lignes($page));
        $this->assertSame($sar->id, session(CapacitesController::SESSION_SITE));

        // Avec la recherche et le tri du tableau.
        $this->en($this->gestionnaire)->get($url.'?site='.$raa->id.'&q=R1')->assertOk()->assertSee('30')->assertDontSee('Plan SAR');
        // Tri par nombre prévu décroissant : 30 (R1) avant 12 (RC), dans les résultats du tableau.
        $tri = $this->en($this->gestionnaire)->withHeader('X-Tableau', 'capacites')->get($url.'?site='.$raa->id.'&tri=-nombre')->assertOk()->getContent();
        $this->assertNotFalse(strpos($tri, '>12<'));
        $this->assertLessThan(strpos($tri, '>12<'), strpos($tri, '>30<'));

        // « Tous les sites » choisi dans le tableau (rechargement partiel sans site) : le choix est oublié.
        $this->en($this->gestionnaire)->withHeader('X-Tableau', 'capacites')->get($url)->assertOk();
        $this->assertNull(session(CapacitesController::SESSION_SITE));
        $this->assertSame('2/1', $lignes($this->en($this->gestionnaire)->get($url)));
    }

    public function test_a_valider_reste_complet_pour_le_validateur(): void
    {
        // Éléments soumis par leurs auteurs : une zone (GESTIONNAIRE), un moyen de paiement et un délai (RÉGULATEUR).
        $this->en($this->gestionnaire)->post(route('admin.referencement.zones.store', 'zones'), ['code' => 'TMP', 'libelle' => 'Temporaire'])->assertSessionHas('succes');
        $zone = ZoneSite::query()->where('code', 'TMP')->sole();
        $this->en($this->regulateur)->post(route('admin.referencement.zones.store', 'moyens'), ['code' => 'SANK_MONEY', 'libelle' => 'Sank Money', 'famille' => 'MOBILE_MONEY',
            'instructions' => 'Numéro marchand', 'ordre' => 10, 'usages' => ['ACTIONS' => ['autorise' => 1, 'min' => '', 'max' => ''], 'CAUTION' => ['autorise' => 1, 'min' => '', 'max' => '']]])
            ->assertSessionHas('succes');
        $sank = MoyenPaiement::query()->where('code', 'SANK_MONEY')->sole();
        $this->en($this->regulateur)->put(route('admin.parametres-paiements.update'), ['delai_caution_valeur' => 72, 'delai_caution_duree' => 'HEURE', 'delai_complement_valeur' => 72, 'delai_complement_duree' => 'HEURE', 'delai_avance_valeur' => 30, 'delai_avance_duree' => 'JOUR', 'delai_kyc_valeur' => 48, 'delai_kyc_duree' => 'HEURE'])->assertSessionHas('succes');
        $this->en($this->regulateur)->post(route('admin.parametres-paiements.soumettre'))->assertSessionHas('succes');
        $delais = ParametresPaiements::fiche();

        // Modification d'un élément validé : différences avant → après.
        $niveau = Niveau::query()->where('par_defaut', true)->firstOrFail();
        $this->en($this->gestionnaire)->put(route('admin.referencement.zones.update', ['niveaux', $niveau->id]), ['libelle' => 'Rez-de-chaussée rénové', 'rang' => $niveau->rang])
            ->assertSessionHas('succes');
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', ['rubrique' => 'referentiels', 'detail' => 'niveaux:'.$niveau->id]))->assertOk()
            ->assertSee('data-champ-modifie="libelle"', false)->assertSee('Rez-de-chaussée rénové')->assertDontSee('Ouvrir la fiche complète');

        // Détail visible dans « À valider », sans lien vers une fiche refusée au VALIDATEUR.
        foreach ([['referentiels', 'zones:'.$zone->id, 'Temporaire'], ['finances-sites', 'moyens:'.$sank->id, 'Sank Money'], ['finances-sites', 'delais:'.$delais->id, 'Délais de paiement']] as [$rubrique, $cle, $attendu]) {
            $detail = $this->en($this->validateur)->get(route('admin.a-valider.rubrique', ['rubrique' => $rubrique, 'detail' => $cle]))->assertOk();
            $detail->assertSee('data-detail="'.$cle.'"', false)->assertSee($attendu)->assertDontSee('Ouvrir la fiche complète');
        }

        // Tableau de bord : « Examiner » ouvre le détail dans « À valider ».
        $this->en($this->validateur)->get(route('admin.tableau'))->assertOk()
            ->assertSee('href="'.e(route('admin.a-valider', ['detail' => 'zones:'.$zone->id])).'"', false)
            ->assertDontSee('href="'.route('admin.referencement.zones.show', ['zones', $zone->id]).'"', false);

        // Décisions : appliquées comme avant.
        foreach (['zones:'.$zone->id, 'moyens:'.$sank->id, 'delais:'.$delais->id] as $cle) {
            $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => [$cle], 'decision' => 'confirmer', 'mode' => 'ligne'])->assertSessionHas('succes');
        }
        $this->assertSame(StatutTypeBoutique::Valide, $zone->fresh()->statut);
        $this->assertSame(StatutTypeBoutique::Valide, $sank->fresh()->statut);
        $this->assertSame(72 * 3_600, ParametresPaiements::delaiSecondes('complement'));

        // Décision prise hors « À valider » : retour sur « À valider », pas sur une fiche refusée.
        $this->en($this->gestionnaire)->post(route('admin.referencement.zones.store', 'zones'), ['code' => 'TMQ', 'libelle' => 'Autre'])->assertSessionHas('succes');
        $this->en($this->validateur)->post(route('admin.referencement.zones.confirmer', ['zones', ZoneSite::query()->where('code', 'TMQ')->value('id')]))
            ->assertRedirect(route('admin.a-valider'))->assertSessionHas('succes');
    }
}
