<?php

namespace Tests\Feature\Publique;

use App\Enums\MethodeAllocation;
use App\Enums\ModeSaisie;
use App\Enums\PayeurFrais;
use App\Enums\PublicAutorise;
use App\Enums\RegleArret;
use App\Enums\RegleSiEchec;
use App\Enums\Role;
use App\Enums\StatutTranche;
use App\Enums\TypeFraisSouscription;
use App\Http\Controllers\Admin\DividendesController;
use App\Models\DistributionDividende;
use App\Models\Tranche;
use App\Models\User;
use App\Support\MotsInterdits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Finder\Finder;
use Tests\Support\Boutiques;
use Tests\Support\ConnexionBackoffice;
use Tests\Support\ParametresInvestissementConfirmes;
use Tests\TestCase;

/** Branding du site public : deux démarches, « Investir dans Raaga » et « Obtenir une boutique ». */
class BrandingDemarchesTest extends TestCase
{
    use ConnexionBackoffice, ParametresInvestissementConfirmes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['raaga.phase.presentation_actions' => true, 'raaga.phase.souscription_actions' => true]);
        Cache::flush();
    }

    private function tranche(): Tranche
    {
        return Tranche::create([
            'statut' => StatutTranche::Ouverte, 'nom' => 'Tranche 2027', 'valeur_unitaire' => 12_000, 'min_actions_par_souscription' => 2,
            'plafond_actions_par_personne' => 500, 'objectif_montant' => 3_000_000_000, 'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addDays(30),
            'publics_autorises' => [PublicAutorise::ResidentBf->value], 'mode_saisie' => ModeSaisie::NbActions, 'souscriptions_multiples' => true,
            'regle_arret' => RegleArret::ArretAObjectif, 'methode_allocation' => MethodeAllocation::Prorata, 'regle_si_echec' => RegleSiEchec::RemboursementIntegral,
            'delai_remboursement_jours' => 30, 'frais_paiement_supportes_par' => PayeurFrais::Raaga, 'frais_souscription_type' => TypeFraisSouscription::Pourcentage,
            'frais_souscription_valeur' => 150, 'canaux_actifs' => ['ORANGE_MONEY'], 'delai_paiement_heures' => 48, 'seuil_kyc_renforce' => 1_000_000,
            'version' => 1, 'cree_par' => User::factory()->create()->id,
        ]);
    }

    public function test_code_visuel_sur_les_deux_parcours(): void
    {
        $accueil = $this->get('/')->assertOk();
        $accueil->assertSee('Raaga, le marché que nous bâtissons et possédons ensemble.')
            ->assertSee('acc-demarche demarche-investir', false)->assertSee('acc-demarche demarche-boutiques', false)
            ->assertSee('data-picto="piece-pousse"', false)->assertSee('data-picto="boutique-auvent"', false)
            ->assertSee('data-badge-demarche="investir"', false)->assertSee('data-badge-demarche="boutiques"', false)
            ->assertSee('class="menu-picto demarche-investir"', false)->assertSee('class="menu-picto demarche-boutiques"', false)
            ->assertSeeInOrder(['Devenez actionnaire de votre marché', 'Droit aux dividendes décidés par l’assemblée générale', 'Rendement non garanti', 'Installez votre commerce dans un marché moderne', 'Je réserve'], false)
            ->assertSeeInOrder(['D’où viennent les dividendes ?', 'Les loyers des boutiques', 'Les parkings', 'Les services du marché',
                'Certaines années, il peut n’y en avoir aucun.'], false);

        // Chaque page d'un parcours : badge et filet de sa démarche ; le rouge reste réservé aux alertes.
        foreach ([route('offre'), route('transparence'), route('souscription.bientot')] as $url) {
            $page = $this->get($url)->assertOk();
            $page->assertSee('class="encadre-demarche demarche-investir"', false)->assertSee('data-badge-demarche="investir"', false);
            $this->assertStringNotContainsString('data-badge-demarche="boutiques"', $page->getContent());
        }
        $this->get(route('offre'))->assertSee('entete-demarche demarche-investir', false);
        $this->get(route('boutiques'))->assertOk()->assertSee('class="encadre-demarche demarche-boutiques"', false)
            ->assertSee('data-badge-demarche="boutiques"', false)->assertSee('entete-demarche demarche-boutiques', false)->assertSee('Obtenir une boutique');

        // Mon espace : un onglet par démarche, chacun avec son code couleur.
        $citoyen = User::factory()->role(Role::Citoyen)->create();
        $this->actingAs($citoyen)->get(route('espace'))->assertSee('onglet-espace', false)->assertSee('demarche-investir est-actif', false)
            ->assertSeeInOrder(['Mes actions', 'Mon attestation et mes documents', 'Dividendes reçus', 'Compte de versement des dividendes', 'Bénéficiaire désigné']);
    }

    public function test_textes_lus_depuis_la_tranche_et_les_parametres(): void
    {
        // Sans tranche ni distribution : aucune valeur inventée.
        $this->get(route('offre'))->assertSee('Le prix et le minimum sont fixés pour chaque tranche.', false)
            ->assertSee('au taux en vigueur, indiqué à chaque distribution', false)->assertDontSee('FCFA');

        $tranche = $this->tranche();
        DistributionDividende::query()->create([
            ...DividendesController::valeursParDefaut(), 'classe_actions' => 'B', 'taux_irvm_pb' => 1000,
            'ag_date' => '2026-06-30', 'ag_pv_numero' => 'PV-1', 'resultat_distribuable' => 1000, 'montant_global' => 500,
            'date_arrete' => '2026-07-15', 'date_mise_en_paiement' => '2026-08-01', 'statut' => 'CONFIRME', 'confirme_le' => now(), 'version' => 1,
            'cree_par' => $tranche->cree_par,
        ]);

        // La tranche seule ne donne aucune valeur d'action sur l'accueil : il faut des paramètres d'investissement confirmés.
        $this->get('/')->assertSee('RAAGA Invest : bientôt')->assertDontSee('Prix de l’action', false);
        $this->confirmerParametresInvestissement(10_000, minimum: 1);
        // Accueil : valeur indicative et minimum des paramètres, sans montant minimum calculé.
        $this->get('/')->assertSee("Valeur indicative de l’action : 10\u{00A0}000\u{00A0}FCFA", false)->assertSee('Minimum : 1 action')
            ->assertDontSee('soit', false);
        // Offre, tranche ouverte : prix et minimum figés de la tranche (12 000 FCFA, 2 actions), jamais la valeur indicative.
        $this->get(route('offre'))->assertOk()
            ->assertSee("Prix de l’action : 12\u{00A0}000\u{00A0}FCFA", false)->assertSee('Minimum : 2 actions')
            ->assertDontSee('Valeur indicative de l’action', false)->assertDontSee('Souscription fermée')
            ->assertSee('Le prix et le minimum sont fixés pour chaque tranche.', false)
            ->assertSee('après la retenue de l’IRVM au taux de 10 %.', false)
            ->assertSeeInOrder(['Les risques', 'Valeur non garantie', 'Dividendes non garantis', 'Aucun droit à une boutique', 'Revente'], false)
            ->assertSee('Statuts de Raaga SA')->assertSee('Règlement de la tranche')->assertSee('href="'.route('souscrire.reglement').'"', false)
            ->assertSee('Questions sur l’investissement', false)->assertSee('Je deviens actionnaire');
    }

    public function test_aucun_mot_interdit_sur_le_site_public(): void
    {
        // Vues et textes publics.
        $fichiers = [lang_path('fr/public.php')];
        foreach ((new Finder)->files()->in([resource_path('views/public'), resource_path('views/components/public')])->name('*.blade.php') as $f) {
            $fichiers[] = $f->getRealPath();
        }
        foreach ($fichiers as $fichier) {
            foreach (file($fichier) as $n => $ligne) {
                $this->assertNull(MotsInterdits::trouver($ligne), basename($fichier).':'.($n + 1).' : « '.MotsInterdits::trouver($ligne).' »');
            }
        }
        $this->assertNull(MotsInterdits::trouver('Rendement non garanti : la valeur peut baisser.'));
        foreach (['Rendement garanti', 'Placement sans risque', 'Un rendement de 8 %', 'Gagnez plus', '10 % par an', 'Dividendes assurés'] as $texte) {
            $this->assertNotNull(MotsInterdits::trouver($texte), $texte);
        }

        // Textes saisis par l'ADMIN, carrousel compris : refusés.
        $this->connecterBackoffice(Role::Admin);
        $this->put(route('admin.plateforme.update', 'messages'), ['bandeauAnnonce' => ['texte' => 'Placement sans risque', 'type' => 'information'], 'messageAntiFraude' => 'Gagnez 10 % par an'])
            ->assertSessionHasErrors(['bandeauAnnonce.texte', 'messageAntiFraude']);
        $this->put(route('admin.plateforme.update', 'site_public'), ['carrousel' => [['alt' => 'Marché', 'titre' => 'Rendement garanti', 'active' => '1']]])
            ->assertSessionHasErrors('carrousel.0.titre');
    }

    public function test_encadres_toujours_presents(): void
    {
        $this->tranche();
        Boutiques::campagneOuverte();
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => 'VALIDE', 'pays_residence' => 'BF']);

        foreach ([route('offre'), route('transparence'), route('souscrire.reglement')] as $url) {
            $this->get($url)->assertSee('Acheter des actions ne donne aucun droit ni priorité pour obtenir une boutique.', false);
        }
        $this->actingAs($citoyen)->get(route('souscrire'))->assertSee('Acheter des actions ne donne aucun droit ni priorité pour obtenir une boutique.', false);
        foreach ([route('boutiques'), route('boutiques.reglement'), route('boutiques.boutique', Boutiques::boutique()->code)] as $url) {
            $this->actingAs($citoyen)->get($url)->assertOk()->assertSee('Réserver une boutique ne fait pas de vous un actionnaire.', false);
        }
        // Les deux cartes de l'accueil portent aussi leur rappel.
        $this->get('/')->assertSee('Acheter des actions ne donne aucun droit ni priorité pour obtenir une boutique.', false)
            ->assertSee('Réserver une boutique ne fait pas de vous un actionnaire.', false);
    }
}
