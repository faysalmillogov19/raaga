<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutGrilleTarifaire;
use App\Enums\StatutKyc;
use App\Enums\StatutSite;
use App\Models\CampagneBoutiques;
use App\Models\GrilleTarifaire;
use App\Models\GrilleTarifaireLigne;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\Unite;
use App\Models\User;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Boutiques\CandidatureBoutiques;
use App\Services\Boutiques\LotsCampagne;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActeursParametrage;
use Tests\Support\GrillesTarifaires;
use Tests\TestCase;

class GrilleTarifaireTest extends TestCase
{
    use ActeursParametrage, GrillesTarifaires, RefreshDatabase;

    private Site $site;

    private TypeBoutique $boutique;

    private TypeBoutique $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
        $this->boutique = TypeBoutique::create(['code' => 'BOUT', 'libelle' => 'Boutique']);
        $this->magasin = TypeBoutique::create(['code' => 'MAG', 'libelle' => 'Magasin']);
        $this->site = Site::create(['statut' => StatutSite::Confirme, 'code' => 'RAA', 'nom' => 'Marché Raaga', 'region' => 'Centre', 'commune' => 'Ouagadougou',
            'adresse' => 'Avenue de la Nation', 'version' => 1, 'cree_par' => $this->regulateur->id]);
        // Deux boutiques de 9 m² au rez-de-chaussée, une de 12,5 m² au R+1, un magasin de 40 m² au rez-de-chaussée.
        foreach ([['RC', 'A', 1, 9, $this->boutique], ['RC', 'A', 2, 9, $this->boutique], ['R1', 'B', 1, 12.5, $this->boutique], ['RC', 'M', 1, 40, $this->magasin]] as [$niveau, $bloc, $n, $surface, $type]) {
            $this->site->boutiques()->create(['niveau' => $niveau, 'bloc' => $bloc, 'numero' => sprintf('%03d', $n), 'surface' => $surface,
                'type_boutique_id' => $type->id, 'statut' => StatutBoutique::Disponible]);
        }
    }

    /** Campagne confirmée proposant les boutiques et les magasins du site (lots construits dans le référentiel). */
    private function campagneConfirmee(): CampagneBoutiques
    {
        $campagne = CampagneBoutiques::create([
            'statut' => StatutCampagneBoutiques::Confirmee, 'nom' => 'Attribution 2027', 'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addMonth(),
            'delai_paiement_heures' => 72, 'canaux_actifs' => ['ORANGE_MONEY'], 'version' => 1, 'cree_par' => $this->regulateur->id,
        ]);
        app(LotsCampagne::class)->definir($campagne, [$this->site->id => [$this->boutique->id => true, $this->magasin->id => true]]);

        return $campagne;
    }

    public function test_calculs_par_niveau(): void
    {
        // Valeurs proposées au RÉGULATEUR : caution 12 mois ; avance RC 60, R1 36, R2 24, R3 12, SS 12 ; magasins 12 quel que soit le niveau.
        $attendu = ['RC' => 60, 'R1' => 36, 'R2' => 24, 'R3' => 12, 'SS' => 12];
        foreach (array_keys($attendu) as $niveau) {
            $this->assertSame(['unite_id' => Unite::idDuCode('M2'), 'mois_caution' => 12, 'mois_avance' => $attendu[$niveau]], GrilleTarifaire::valeursProposees($niveau, $this->boutique));
            $this->assertSame(12, GrilleTarifaire::valeursProposees($niveau, $this->magasin)['mois_avance']);

            // Loyer = surface × loyer au m² ; caution = loyer × 12 ; avance = loyer × mois d'avance du niveau.
            $m = GrilleTarifaire::montants(9.0, 2000, 12, $attendu[$niveau]);
            $this->assertSame(['loyer_mensuel' => 18000, 'caution' => 216000, 'avance' => 18000 * $attendu[$niveau]], $m);
        }
        $this->assertSame(['loyer_mensuel' => 31250, 'caution' => 375000, 'avance' => 1125000], GrilleTarifaire::montants(12.5, 2500, 12, 36));
        $this->assertSame("1\u{00A0}080\u{00A0}000\u{00A0}FCFA", format_fcfa(GrilleTarifaire::montants(9.0, 2000, 12, 60)['avance']));

        // Le formulaire propose ces valeurs, et la grille confirmée donne les montants de chaque lot.
        $formulaire = $this->en($this->regulateur)->get(route('admin.boutiques.grilles.edit', $this->site))->assertOk()->getContent();
        foreach ([[$this->boutique->id, 60], [$this->magasin->id, 12]] as [$type, $mois]) {
            $this->assertMatchesRegularExpression('/name="lignes\[RC\]\['.$type.'\]\[mois_avance\]"[^>]*value="'.$mois.'"/', $formulaire);
        }
        $this->grilleConfirmee($this->site, [
            'RC' => [$this->boutique->id => [2000, 12, 60], $this->magasin->id => [1500, 12, 12]],
            'R1' => [$this->boutique->id => [2500, 12, 36]],
        ]);
        $lots = $this->campagneConfirmee()->categories()->get()->keyBy('libelle');
        $tarif = fn (string $lot) => array_intersect_key(app(LotsCampagne::class)->tarifer($lots[$lot]), array_flip(['loyer_mensuel', 'caution', 'avance']));
        $this->assertSame(['loyer_mensuel' => 18000, 'caution' => 216000, 'avance' => 1080000], $tarif('Boutique · Rez-de-chaussée · 9 m²'));
        $this->assertSame(['loyer_mensuel' => 31250, 'caution' => 375000, 'avance' => 1125000], $tarif('Boutique · R+1 · 12,50 m²'));
        $this->assertSame(['loyer_mensuel' => 60000, 'caution' => 720000, 'avance' => 720000], $tarif('Magasin · Rez-de-chaussée · 40 m²'));
    }

    public function test_les_montants_sont_figes_apres_la_candidature(): void
    {
        $this->grilleConfirmee($this->site, ['RC' => [$this->boutique->id => [2000, 12, 60], $this->magasin->id => [1500, 12, 12]], 'R1' => [$this->boutique->id => [2500, 12, 36]]]);
        $campagne = $this->campagneConfirmee();
        app(LotsCampagne::class)->engager($campagne);
        $campagne->forceFill(['statut' => StatutCampagneBoutiques::Ouverte])->save();
        $lot = $campagne->categories()->where('libelle', 'Boutique · Rez-de-chaussée · 9 m²')->sole();

        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide]);
        $candidature = app(CandidatureBoutiques::class)->candidater($campagne, $citoyen, $lot->id,
            array_map(fn ($d) => $d->value, CandidatureBoutiques::ENGAGEMENTS), '127.0.0.1', 'test');
        $this->assertSame([216000, 1080000, 18000, 12, 60], [$candidature->caution, $candidature->avance, $candidature->loyer_mensuel, $candidature->mois_caution, $candidature->mois_avance]);
        $this->assertSame(1296000, $candidature->montantAPayer());

        // Nouvelle grille confirmée : ni le lot ouvert ni la candidature ne changent.
        $this->grilleConfirmee($this->site, ['RC' => [$this->boutique->id => [3000, 6, 24]]]);
        $this->assertSame([216000, 1080000], [$candidature->fresh()->caution, $candidature->fresh()->avance]);
        $this->assertSame([216000, 1080000], [$lot->fresh()->caution, $lot->fresh()->avance]);

        // Caution et avance sur deux lignes, avec leur mention.
        $this->actingAs($citoyen)->get(route('boutiques.candidature', $candidature->reference))->assertOk()
            ->assertSeeInOrder(['Caution', "216\u{00A0}000\u{00A0}FCFA", 'Caution : remboursable selon le règlement.',
                'Avance', "1\u{00A0}080\u{00A0}000\u{00A0}FCFA", 'Avance : loyers payés d’avance, imputés sur les 60 premiers mois.'], false);
    }

    public function test_une_campagne_ne_s_ouvre_pas_sans_grille_confirmee(): void
    {
        $campagne = $this->campagneConfirmee();
        $ouvrir = function () use ($campagne) {
            try {
                app(LotsCampagne::class)->engager($campagne);
                $this->fail('La campagne s’est ouverte sans grille confirmée.');
            } catch (RegleGestionException $e) {
                $this->assertStringContainsString('grille tarifaire', $e->getMessage());
            }
            $this->assertSame(0, $this->site->boutiques()->where('statut', StatutBoutique::EnCampagne)->count());
        };

        // Aucune grille, puis grille seulement soumise.
        $ouvrir();
        $this->en($this->regulateur)->put(route('admin.boutiques.grilles.update', $this->site), ['lignes' => [
            'RC' => [$this->boutique->id => ['unite_id' => Unite::idDuCode('M2'), 'loyer_m2' => 2000, 'mois_caution' => 12, 'mois_avance' => 60], $this->magasin->id => ['unite_id' => Unite::idDuCode('M2'), 'loyer_m2' => 1500, 'mois_caution' => 12, 'mois_avance' => 12]],
            'R1' => [$this->boutique->id => ['unite_id' => Unite::idDuCode('M2'), 'loyer_m2' => 2500, 'mois_caution' => 12, 'mois_avance' => 36]],
        ]]);
        $this->en($this->regulateur)->post(route('admin.boutiques.grilles.soumettre', $this->site))->assertSessionHas('succes');
        $ouvrir();
        $this->en($this->validateur)->post(route('admin.boutiques.grilles.rejeter', $this->site), ['motif' => 'Loyers à revoir'])->assertSessionHas('succes');

        // Grille confirmée, mais sans ligne pour les boutiques du R+1.
        $this->grilleConfirmee($this->site, ['RC' => [$this->boutique->id => [2000, 12, 60], $this->magasin->id => [1500, 12, 12]]]);
        $ouvrir();

        // Toutes les boutiques couvertes : ouverture possible, montants figés dans les lots.
        $this->grilleConfirmee($this->site, ['RC' => [$this->boutique->id => [2000, 12, 60], $this->magasin->id => [1500, 12, 12]], 'R1' => [$this->boutique->id => [2500, 12, 36]]]);
        app(LotsCampagne::class)->engager($campagne);
        $this->assertSame(4, $this->site->boutiques()->where('statut', StatutBoutique::EnCampagne)->count());
        $this->assertTrue($campagne->categories()->get()->every(fn ($lot) => $lot->caution > 0 && $lot->grille_version === 3));
    }

    public function test_circuit_regulateur_puis_validateur(): void
    {
        $lignes = ['lignes' => ['RC' => [$this->boutique->id => ['unite_id' => Unite::idDuCode('M2'), 'loyer_m2' => 2000, 'mois_caution' => 12, 'mois_avance' => 60]]]];
        $gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();

        // Seul le RÉGULATEUR saisit et soumet.
        foreach ([$this->validateur, $gestionnaire, User::factory()->role(Role::Admin)->create()] as $profil) {
            $this->en($profil)->put(route('admin.boutiques.grilles.update', $this->site), $lignes)->assertForbidden();
        }
        $this->en($this->regulateur)->put(route('admin.boutiques.grilles.update', $this->site), $lignes)->assertSessionHas('succes');
        $this->en($this->regulateur)->post(route('admin.boutiques.grilles.soumettre', $this->site))->assertSessionHas('succes');
        $grille = $this->site->grilleTarifaire()->sole();
        $this->assertSame(StatutGrilleTarifaire::Soumis, $grille->statut);

        // Soumise : plus modifiable ; seul le VALIDATEUR confirme.
        $this->en($this->regulateur)->put(route('admin.boutiques.grilles.update', $this->site), $lignes)->assertSessionHas('erreur');
        $this->en($this->regulateur)->post(route('admin.boutiques.grilles.confirmer', $this->site))->assertForbidden();
        $this->en($gestionnaire)->post(route('admin.boutiques.grilles.confirmer', $this->site))->assertForbidden();
        $this->en($this->validateur)->get(route('admin.boutiques.grilles.show', $this->site))->assertOk()->assertSee("2\u{00A0}000\u{00A0}FCFA", false);
        $this->en($this->validateur)->post(route('admin.boutiques.grilles.confirmer', $this->site))->assertSessionHas('succes');
        $this->assertSame(StatutGrilleTarifaire::Confirme, $grille->fresh()->statut);
        $this->assertSame(['version' => 1, 'lignes' => ['RC|'.$this->boutique->id => ['unite_loyer' => 'M2', 'loyer_m2' => 2000, 'mois_caution' => 12, 'mois_avance' => 60]]], $grille->confirmee());

        // Révision : la version confirmée reste en vigueur jusqu'à la suivante ; l'historique est conservé.
        $this->en($this->regulateur)->post(route('admin.boutiques.grilles.reviser', $this->site))->assertSessionHas('succes');
        $this->en($this->regulateur)->put(route('admin.boutiques.grilles.update', $this->site), ['lignes' => ['RC' => [$this->boutique->id => ['unite_id' => Unite::idDuCode('M2'), 'loyer_m2' => 2400, 'mois_caution' => 12, 'mois_avance' => 60]]]]);
        $this->assertSame(1, $grille->fresh()->confirmee()['version']);
        $this->en($this->regulateur)->post(route('admin.boutiques.grilles.soumettre', $this->site));
        $this->en($this->validateur)->get(route('admin.boutiques.grilles.show', $this->site))->assertOk()->assertSee("2\u{00A0}400\u{00A0}FCFA/m²", false);
        $this->en($this->validateur)->post(route('admin.boutiques.grilles.confirmer', $this->site))->assertSessionHas('succes');
        $this->assertSame(2, $grille->fresh()->confirmee()['version']);
        $this->assertSame(2, $grille->versions()->count());
    }

    public function test_l_unite_d_une_ligne_est_une_cle_etrangere_du_referentiel(): void
    {
        $this->grilleConfirmee($this->site, ['RC' => [$this->boutique->id => [2000, 12, 60, 'M2']]]);
        $this->assertSame('M2', GrilleTarifaireLigne::query()->first()->unite->code);

        // Unité utilisée par une ligne de grille (clé étrangère par identifiant) : suppression refusée par la base elle-même.
        $this->expectException(QueryException::class);
        DB::table('unites')->where('code', 'M2')->delete();
    }
}
