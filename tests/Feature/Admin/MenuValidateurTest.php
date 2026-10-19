<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutKyc;
use App\Enums\StatutReservationBoutique;
use App\Enums\StatutSite;
use App\Models\AuditLog;
use App\Models\DecisionPhase;
use App\Models\ReservationBoutique;
use App\Models\Site;
use App\Models\User;
use App\Services\Boutiques\ReservationsBoutiques;
use App\Support\MenuBackoffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Boutiques;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/**
 * Menu du VALIDATEUR regroupé par domaine : « À valider » (rubriques sous quatre intertitres, sommes des badges,
 * rubrique vide grisée), « Boutiques » (attributions à faire, cautions, consultation), « Contrôle » (historique de
 * mes décisions, journal d'audit). Mêmes URL, mêmes droits ; les autres profils gardent leur menu.
 */
class MenuValidateurTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    private User $validateur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validateur = $this->connecterBackoffice(Role::Validateur);
    }

    /** Ancien menu : la définition commune filtrée par les droits du profil. */
    private function ancienMenu(User $user): array
    {
        return collect(MenuBackoffice::definition())->flatten(1)
            ->filter(fn (array $e) => $user->can($e['droit']->value) && ! (isset($e['masque_si']) && $user->can($e['masque_si']->value)))
            ->reject(fn (array $e) => MenuBackoffice::enPreparation($e['cle']))->values()->all();
    }

    /** Deux sites soumis (Référencement) et une réservation prête à attribuer (Campagnes & réservations). */
    private function file(): ReservationBoutique
    {
        $auteur = User::factory()->role(Role::GestionnaireSites)->create();
        foreach (['SAR', 'KOU'] as $code) {
            Site::create(['statut' => StatutSite::Soumis, 'code' => $code, 'nom' => 'Marché '.$code, 'region' => 'Centre', 'commune' => 'Ouagadougou', 'adresse' => 'Avenue',
                'version' => 1, 'cree_par' => $auteur->id, 'soumis_par' => $auteur->id, 'soumis_le' => now()]);
        }
        $campagne = Boutiques::campagneOuverte();
        $boutique = Boutiques::boutique('A', 1);
        $boutique->forceFill(['statut' => StatutBoutique::Reservee])->save();

        return ReservationBoutique::create(['reference' => 'BTQ-PRETE001', 'user_id' => User::factory()->create(['statut_kyc' => StatutKyc::Valide])->id, 'campagne_boutiques_id' => $campagne->id,
            'boutique_id' => $boutique->id, 'caution' => 540_000, 'avance' => 135_000, 'statut' => StatutReservationBoutique::Reservee, 'expire_le' => now()->subDay(),
            'avance_payee_le' => now(), 'reference_avance' => 'PAY-AVANCE01']);
    }

    public function test_menu_final_et_aucun_lien_mort(): void
    {
        $menu = MenuBackoffice::pour($this->validateur);
        $elements = MenuBackoffice::regrouper($menu['pilotage'], $this->validateur);
        $this->assertSame('tableau', $elements[0]['entree']['cle']);
        $this->assertSame(['a_valider', 'gestion_boutiques', 'invest', 'actions_a', 'controle'], array_column(array_slice($elements, 1), 'cle'));

        // « À valider » inchangé : vue d'ensemble, puis les rubriques dans l'ordre des intertitres.
        $aValider = $elements[1]['entrees'];
        $this->assertSame('a_valider', $aValider[0]['cle']);
        $this->assertSame(['sites', 'imports', 'referentiels', 'autorisations', 'finances', 'grilles', 'paiements', 'cautions', 'campagnes', 'attributions', 'demarches', 'identites', 'cartes'],
            array_map(fn ($e) => substr($e['cle'], strlen('a_valider_')), array_slice($aValider, 1)));
        $attendus = [
            'gestion_boutiques' => ['attributions_a_faire', 'cautions', 'boutiques', 'sites', 'boutiques_referentiel', 'imports_sites', 'imports_boutiques'],
            // Pages en préparation masquées : Simulations, Registre B, Observations, Alertes conformité, Journal d'audit.
            'invest' => ['investissement', 'tranches', 'dividendes'],
            'actions_a' => ['dossiers_kyb', 'conventions', 'appels_fonds', 'registre_a'],
            'controle' => ['historique'],
        ];
        foreach (array_slice($elements, 2) as $element) {
            $this->assertSame($attendus[$element['cle']], array_column($element['entrees'], 'cle'), $element['cle']);
        }

        // Aucun lien mort : chaque entrée est dans la page et répond ; même URL que dans la définition commune.
        $page = $this->get('/admin')->assertOk()->assertSee('<span class="adm-libelle">RAAGA Invest</span>', false)->assertSee('<span class="adm-libelle">Actions A</span>', false);
        foreach (collect($menu)->flatten(1) as $entree) {
            if ($commune = MenuBackoffice::entree($entree['cle'])) {
                $this->assertSame(MenuBackoffice::url($commune), MenuBackoffice::url($entree), $entree['cle']);
            }
            $page->assertSee('href="'.MenuBackoffice::url($entree).'"', false);
            $this->get(MenuBackoffice::url($entree))->assertOk();
        }
    }

    public function test_onglets_retires_refuses_et_profils_repreneurs_autorises(): void
    {
        $site = Site::create(['statut' => StatutSite::Confirme, 'code' => 'RAA', 'nom' => 'Marché Raaga', 'region' => 'Centre', 'commune' => 'Ouagadougou', 'adresse' => 'Avenue',
            'version' => 1, 'cree_par' => $this->validateur->id]);
        $retires = ['kyc' => Role::Admin, 'souscriptions' => Role::Regulateur, 'remboursements' => Role::ControleurPaiements, 'phase' => Role::Regulateur,
            'referentiels' => Role::Regulateur, 'capacites' => Role::GestionnaireSites, 'grille' => Role::Regulateur];
        $page = $this->get('/admin')->assertOk();
        foreach ($retires as $cle => $repreneur) {
            $url = MenuBackoffice::url(MenuBackoffice::entree($cle));
            $page->assertDontSee('href="'.$url.'"', false);
            $this->get($url)->assertForbidden();
        }
        $this->get(route('admin.boutiques.grilles.show', $site))->assertForbidden();
        $this->get(route('admin.kyc.dossier', User::factory()->create()))->assertForbidden();
        // Plus de lien vers les capacités ni vers les grilles depuis ses écrans.
        $this->get(route('admin.sites'))->assertOk()->assertDontSee(route('admin.referencement.capacites', ['site' => $site->id]), false);
        $this->get(route('admin.boutiques'))->assertOk()->assertDontSee('href="'.route('admin.boutiques.grilles').'"', false);

        // Les profils qui font ce travail y ont toujours accès (le SUPER_ADMIN garde ses lectures).
        foreach ($retires as $cle => $repreneur) {
            $this->connecterBackoffice($repreneur);
            $this->get(MenuBackoffice::url(MenuBackoffice::entree($cle)))->assertOk();
        }
        $this->connecterBackoffice(Role::SuperAdmin);
        foreach (['capacites', 'grille', 'phase'] as $cle) {
            $this->get(MenuBackoffice::url(MenuBackoffice::entree($cle)))->assertOk();
        }
    }

    public function test_decisions_et_detail_toujours_possibles_dans_a_valider(): void
    {
        // Démarche proposée par le RÉGULATEUR : détail ouvert dans « À valider », sans lien vers l'écran retiré, puis décision.
        $regulateur = User::factory()->role(Role::Regulateur)->create();
        $d = DecisionPhase::create(['presentation_actions' => true, 'souscription_actions' => false, 'statut' => DecisionPhase::SOUMISE,
            'motif' => 'Ouverture prochaine', 'propose_par' => $regulateur->id]);
        $this->get(route('admin.a-valider.rubrique', ['rubrique' => 'demarches', 'detail' => 'phase:'.$d->id]))->assertOk()
            ->assertSee('data-detail="phase:'.$d->id.'"', false)->assertDontSee('href="'.route('admin.phase').'"', false);
        $this->post(route('admin.a-valider.decider'), ['elements' => ['phase:'.$d->id], 'decision' => 'confirmer', 'mode' => 'ligne'])->assertSessionHas('succes');
        $this->assertSame(DecisionPhase::CONFIRMEE, $d->fresh()->statut);
        // Ancienne route directe de décision : toujours permise, retour sur « À valider » (plus sur l'écran retiré).
        $d2 = DecisionPhase::create(['presentation_actions' => false, 'souscription_actions' => false, 'statut' => DecisionPhase::SOUMISE,
            'motif' => 'Fermeture', 'propose_par' => $regulateur->id]);
        $this->post(route('admin.phase.rejeter', $d2), ['motif' => 'Pas maintenant'])->assertRedirect(route('admin.a-valider.rubrique', 'demarches'));
    }

    public function test_avance_absente_seulement_si_zero_selon_la_grille_confirmee(): void
    {
        $prete = $this->file();
        $base = ['user_id' => $prete->user_id, 'campagne_boutiques_id' => $prete->campagne_boutiques_id, 'caution' => 540_000, 'statut' => StatutReservationBoutique::Reservee,
            'expire_le' => now()->subDay()];
        $boutique = fn (int $n) => Boutiques::boutique('A', $n)->id;
        // Avance figée à 0 FCFA par la grille confirmée (0 mois d'avance) : prête sans paiement d'avance.
        $sansAvance = ReservationBoutique::create([...$base, 'reference' => 'BTQ-ZEROAV01', 'boutique_id' => $boutique(2), 'avance' => 0, 'mois_avance' => 0]);
        // Avance non figée (0 par défaut, aucun mois d'avance) : jamais « sans avance ».
        $nonFigee = ReservationBoutique::create([...$base, 'reference' => 'BTQ-NONFIG01', 'boutique_id' => $boutique(3), 'avance' => 0, 'mois_avance' => null]);
        // Avance due, pas encore confirmée : pas prête.
        $impayee = ReservationBoutique::create([...$base, 'reference' => 'BTQ-IMPAYE01', 'boutique_id' => $boutique(4), 'avance' => 135_000, 'mois_avance' => 3]);

        $this->assertSame(['BTQ-PRETE001', 'BTQ-ZEROAV01'], ReservationsBoutiques::aAttribuer()->orderBy('reference')->pluck('reference')->all());
        $this->assertSame([true, true, false, false], [$prete->avanceReglee(), $sansAvance->avanceReglee(), $nonFigee->avanceReglee(), $impayee->avanceReglee()]);
        $this->get(route('admin.boutiques.attributions'))->assertOk()->assertSee('BTQ-ZEROAV01')->assertDontSee('BTQ-NONFIG01')->assertDontSee('BTQ-IMPAYE01');
        foreach ([$nonFigee, $impayee] as $r) {
            $this->post(route('admin.boutiques.attribuer', [$r->campagne_boutiques_id, $r->id]), ['contrat_signe_le' => now()->toDateString()])->assertSessionHasErrors('contrat_signe_le');
            $this->assertSame(StatutReservationBoutique::Reservee, $r->fresh()->statut);
        }
    }

    public function test_sommes_des_badges_et_rubrique_vide_grisee(): void
    {
        $this->file();
        $html = $this->get('/admin')->assertOk()->getContent();

        // Total sur « À valider » (2 sites + 1 attribution), somme par intertitre, compteur par rubrique.
        $this->assertMatchesRegularExpression('#aria-controls="sous-menu-a_valider".*?<span class="adm-libelle">À valider</span>\s*<span class="adm-compteur">3<#s', $html);
        $this->assertMatchesRegularExpression('#data-intertitre="referencement">\s*<span>Référencement</span>\s*<span class="adm-compteur">2<#', $html);
        $this->assertMatchesRegularExpression('#data-intertitre="campagnes">\s*<span>Campagnes &amp; réservations</span>\s*<span class="adm-compteur">1<#', $html);
        $this->assertMatchesRegularExpression('#data-intertitre="finances">\s*<span>Finances</span>\s*</li>#', $html);
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('admin.a-valider.rubrique', 'sites'), '#').'"[^>]*>\s*<span class="adm-libelle">Sites</span>\s*<span class="adm-compteur">2<#', $html);
        // Rubrique vide : visible, grisée, sans compteur.
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('admin.a-valider.rubrique', 'grilles'), '#').'" class="bo-lien adm-sous-lien adm-sous-lien-vide"\s*>\s*<span class="adm-libelle">Grilles tarifaires</span>\s*</a>#', $html);
        // « Boutiques » : attributions à faire, remontées sur le parent.
        $this->assertMatchesRegularExpression('#aria-controls="sous-menu-gestion_boutiques".*?<span class="adm-libelle">Boutiques</span>\s*<span class="adm-compteur">1<#s', $html);
    }

    public function test_menu_de_la_page_en_cours_ouvert_et_rubrique_active(): void
    {
        $this->file();
        $actif = fn (string $url) => '#href="'.preg_quote($url, '#').'" class="bo-lien adm-sous-lien bo-lien-actif"\s+aria-current="page"#';

        $html = $this->get(route('admin.a-valider.rubrique', 'attributions'))->assertOk()->assertSee('BTQ-PRETE001')->assertSee('data-attribuer', false)
            ->assertDontSee('aria-label="Valider BTQ-PRETE001', false)->getContent();
        $this->assertStringContainsString('id="sous-menu-a_valider" class="adm-deroulant-corps est-deplie"', $html);
        $this->assertMatchesRegularExpression($actif(route('admin.a-valider.rubrique', 'attributions')), $html);
        $this->assertSame(1, substr_count($html, 'bo-lien-actif'));

        $html = $this->get(route('admin.boutiques.attributions'))->assertOk()->getContent();
        $this->assertStringContainsString('id="sous-menu-gestion_boutiques" class="adm-deroulant-corps est-deplie"', $html);
        $this->assertStringContainsString('id="sous-menu-a_valider" class="adm-deroulant-corps"', $html);
        $this->assertMatchesRegularExpression($actif(route('admin.boutiques.attributions')), $html);
        $this->assertSame(1, substr_count($html, 'bo-lien-actif'));

        $html = $this->get(route('admin.historique-decisions'))->assertOk()->getContent();
        $this->assertStringContainsString('id="sous-menu-controle" class="adm-deroulant-corps est-deplie"', $html);
        $this->assertMatchesRegularExpression($actif(route('admin.historique-decisions')), $html);
    }

    public function test_attribution_depuis_attributions_a_faire_et_historique_de_mes_decisions(): void
    {
        $reservation = $this->file();
        // Une attribution n'est jamais un « confirmer ou rejeter » de « À valider ».
        $this->post(route('admin.a-valider.decider'), ['elements' => ['attribution:'.$reservation->id], 'decision' => 'confirmer', 'mode' => 'ligne'])->assertSessionHasErrors('elements.0');

        $this->get(route('admin.boutiques.attributions'))->assertOk()->assertSee('BTQ-PRETE001')->assertSee('PAY-AVANCE01')->assertSee('1 réservation prête à attribuer');
        $this->from(route('admin.boutiques.attributions'))->post(route('admin.boutiques.attribuer', [$reservation->campagne_boutiques_id, $reservation->id]), ['contrat_signe_le' => now()->toDateString()])
            ->assertRedirect(route('admin.boutiques.attributions'))->assertSessionHas('succes');
        $this->assertSame(StatutReservationBoutique::Attribuee, $reservation->fresh()->statut);
        $this->get(route('admin.boutiques.attributions'))->assertOk()->assertSee('Aucune attribution à faire.');

        // Historique : ses décisions seulement (pas ses soumissions, pas les décisions des autres).
        $autre = User::factory()->role(Role::Validateur)->create();
        AuditLog::query()->insert([
            ['acteur_id' => $this->validateur->id, 'action' => 'grille_tarifaire.rejete', 'entite_type' => 'GrilleTarifaire', 'entite_id' => '7', 'nouvelle_valeur' => json_encode(['motif' => 'Loyer du niveau R1 trop élevé']), 'created_at' => now()],
            ['acteur_id' => $this->validateur->id, 'action' => 'kyc.annulation_confirmee', 'entite_type' => 'User', 'entite_id' => '9', 'nouvelle_valeur' => null, 'created_at' => now()],
            ['acteur_id' => $this->validateur->id, 'action' => 'site.soumis', 'entite_type' => 'Site', 'entite_id' => '3', 'nouvelle_valeur' => null, 'created_at' => now()],
            ['acteur_id' => $autre->id, 'action' => 'site.confirme', 'entite_type' => 'Site', 'entite_id' => '4', 'nouvelle_valeur' => null, 'created_at' => now()],
        ]);
        $this->get(route('admin.historique-decisions'))->assertOk()->assertSee('Historique de mes décisions')
            ->assertSee('Grille tarifaire n° 7')->assertSee('Rejetée')->assertSee('Loyer du niveau R1 trop élevé')
            ->assertSee('Identité n° 9')->assertSee('Réservation de boutique n° '.$reservation->id)->assertSee('Attribuée')
            ->assertDontSee('Site n° 3')->assertDontSee('Site n° 4');

        // Les autres profils n'y ont pas accès.
        $this->connecterBackoffice(Role::Regulateur);
        $this->get(route('admin.historique-decisions'))->assertForbidden();
        $this->get(route('admin.boutiques.attributions'))->assertForbidden();
    }

    public function test_autres_profils_inchanges(): void
    {
        foreach ([Role::SuperAdmin, Role::GestionnaireSites, Role::ControleurPaiements, Role::Admin] as $role) {
            $user = $this->connecterBackoffice($role);
            $this->assertSame(collect($this->ancienMenu($user))->pluck('cle')->all(), collect(MenuBackoffice::pour($user))->flatten(1)->pluck('cle')->all(), $role->value);
            $this->get('/admin')->assertOk()->assertDontSee('data-intertitre', false)->assertDontSee('sous-menu-gestion_boutiques', false);
        }
    }
}
