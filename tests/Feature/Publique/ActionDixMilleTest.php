<?php

namespace Tests\Feature\Publique;

use App\Enums\CanalSouscription;
use App\Enums\DocumentAccepte;
use App\Enums\MethodeAllocation;
use App\Enums\ModeSaisie;
use App\Enums\PayeurFrais;
use App\Enums\PublicAutorise;
use App\Enums\RegleArret;
use App\Enums\RegleSiEchec;
use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Enums\StatutPaiement;
use App\Enums\StatutSouscription;
use App\Enums\StatutTranche;
use App\Enums\TypeFraisSouscription;
use App\Http\Controllers\Admin\TranchesController;
use App\Models\Duree;
use App\Models\Souscription;
use App\Models\Tranche;
use App\Models\User;
use App\Services\Invest\RaagaInvest;
use App\Services\Souscriptions\PreparationSouscription;
use App\Support\Recu;
use Database\Seeders\ComptesDemoSeeder;
use Database\Seeders\TrancheDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\Support\ParametresInvestissementConfirmes;
use Tests\TestCase;

/**
 * Action à 10 000 FCFA, minimum 1 action, une seule source : valeur indicative et nombre minimum d'actions des
 * paramètres d'investissement confirmés (souscription fermée), prix et minimum figés de la tranche (tranche ouverte).
 * Aucune valeur écrite en dur ; les souscriptions et reçus déjà enregistrés gardent le prix réellement payé.
 */
class ActionDixMilleTest extends TestCase
{
    use ParametresInvestissementConfirmes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['raaga.phase.presentation_actions' => true, 'raaga.phase.souscription_actions' => false]);
        Cache::flush();
    }

    private function tranche(int $prix = 10_000, int $minimum = 1, StatutTranche $statut = StatutTranche::Ouverte): Tranche
    {
        return Tranche::create([
            'statut' => $statut, 'nom' => 'Tranche 1', 'valeur_unitaire' => $prix, 'min_actions_par_souscription' => $minimum,
            'objectif_montant' => 3_000_000_000, 'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addDays(30),
            'publics_autorises' => [PublicAutorise::ResidentBf->value], 'mode_saisie' => ModeSaisie::NbActions, 'souscriptions_multiples' => true,
            'regle_arret' => RegleArret::ArretAObjectif, 'methode_allocation' => MethodeAllocation::Prorata, 'regle_si_echec' => RegleSiEchec::RemboursementIntegral,
            'delai_remboursement_jours' => 30, 'frais_paiement_supportes_par' => PayeurFrais::Raaga, 'frais_souscription_type' => TypeFraisSouscription::Aucun,
            'frais_souscription_valeur' => 0, 'canaux_actifs' => ['ORANGE_MONEY'], 'delai_paiement_valeur' => 48, 'delai_paiement_duree_id' => Duree::parCode('HEURE')->id, 'delai_paiement_secondes' => 172_800, 'version' => 1,
            'cree_par' => User::factory()->create()->id,
        ]);
    }

    public function test_aucune_valeur_en_dur_dans_les_vues_ni_les_textes(): void
    {
        $fichiers = [...File::allFiles(resource_path('views')), ...File::allFiles(lang_path('fr'))];
        foreach ($fichiers as $fichier) {
            $source = $fichier->getContents();
            $this->assertDoesNotMatchRegularExpression('/7[\s\x{00A0}\x{202F}_]?500/u', $source, $fichier->getRelativePathname());
            $this->assertDoesNotMatchRegularExpression('/(\d+|:n)\s+au minimum/u', $source, $fichier->getRelativePathname());
            // Plus de montant minimum calculé (« Minimum : 4 actions, soit 30 000 FCFA »).
            $this->assertDoesNotMatchRegularExpression('/Minimum : [^\'"]*soit/u', $source, $fichier->getRelativePathname());
        }

        // Sans paramètres confirmés, la création d'une tranche ne propose aucun prix.
        $this->assertNull(TranchesController::valeursParDefaut()['valeur_unitaire']);
        $this->assertNull(RaagaInvest::conditions());
    }

    public function test_objectif_de_tranche_lu_dans_les_parametres(): void
    {
        // Aucune trace de 3 000 000 000 dans le code (hors tests), sauf le plan de financement du projet (contenu éditable).
        $exceptions = ["['libelle' => 'Participation populaire', 'montant' => 3_000_000_000"];
        foreach ([app_path(), resource_path(), lang_path(), config_path(), base_path('routes'), database_path(), public_path('js')] as $dossier) {
            foreach (File::allFiles($dossier) as $fichier) {
                if (! in_array($fichier->getExtension(), ['php', 'js', 'json'], true)) {
                    continue;
                }
                $source = str_replace($exceptions, '', $fichier->getContents());
                $this->assertDoesNotMatchRegularExpression('/3[_\s\x{00A0}\x{202F}]?000[_\s\x{00A0}\x{202F}]?000[_\s\x{00A0}\x{202F}]?000/u', $source, $fichier->getRelativePathname());
            }
        }

        // Sans paramètres confirmés : aucun objectif proposé ; avec : l'objectif de collecte confirmé, ajustable.
        $this->assertNull(TranchesController::valeursParDefaut()['objectif_montant']);
        $this->confirmerParametresInvestissement(10_000, objectif: 2_000_000_000);
        $this->assertSame(2_000_000_000, TranchesController::valeursParDefaut()['objectif_montant']);
    }

    public function test_montant_indicatif_a_10_000(): void
    {
        $this->confirmerParametresInvestissement(10_000, minimum: 1);

        $this->assertSame(['nombre' => 1, 'valeur_action' => 10_000, 'montant' => 10_000, 'part_objectif' => 0.001], RaagaInvest::simuler(1));
        $this->assertSame(250_000, RaagaInvest::simuler(25)['montant']);
        // Manifestation d'intérêt (offre) : valeur de départ, le nombre minimum d'actions (1).
        $this->actingAs(User::factory()->role(Role::Citoyen)->create())->get(route('offre'))->assertOk()
            ->assertSee('name="nombre_actions"', false)->assertSee('value="1"', false);
    }

    public function test_offre_souscription_fermee_valeur_indicative_et_minimum(): void
    {
        // Rien de confirmé : aucune valeur.
        $this->get(route('offre'))->assertOk()->assertDontSee('Valeur indicative de l’action', false)->assertDontSee('Prix de l’action', false);

        // Paramètres confirmés, tranche ouverte mais souscription fermée : valeur indicative, minimum, « Souscription fermée ».
        $this->confirmerParametresInvestissement(10_000, minimum: 1);
        $this->tranche(prix: 12_000, minimum: 3);
        $this->get(route('offre'))->assertOk()
            ->assertSee("Valeur indicative de l’action : 10\u{00A0}000\u{00A0}FCFA", false)
            ->assertSee('Minimum : 1 action')->assertSee('Souscription fermée')
            ->assertDontSee('Prix de l’action', false)->assertDontSee("12\u{00A0}000", false);
        $this->get('/')->assertOk()->assertSee("Valeur indicative de l’action : 10\u{00A0}000\u{00A0}FCFA", false)->assertSee('Minimum : 1 action');
    }

    public function test_offre_tranche_ouverte_prix_fige_de_la_tranche(): void
    {
        config(['raaga.phase.souscription_actions' => true]);
        Cache::flush();
        $this->confirmerParametresInvestissement(10_000, minimum: 1);
        $this->tranche(prix: 10_000, minimum: 1);

        $this->get(route('offre'))->assertOk()
            ->assertSee("Prix de l’action : 10\u{00A0}000\u{00A0}FCFA", false)->assertSee('Minimum : 1 action')
            ->assertDontSee('Souscription fermée')->assertDontSee('Valeur indicative de l’action', false);
    }

    public function test_souscription_d_une_seule_action_acceptee_zero_refusee(): void
    {
        config(['raaga.phase.souscription_actions' => true]);
        Cache::flush();
        $this->confirmerParametresInvestissement(10_000, minimum: 1);
        $tranche = $this->tranche(prix: 10_000, minimum: 1);
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'pays_residence' => 'BF']);
        $engagements = array_map(fn (DocumentAccepte $d) => $d->value, PreparationSouscription::ENGAGEMENTS);

        $this->actingAs($citoyen)->get(route('souscrire'))->assertOk()->assertSee("10\u{00A0}000\u{00A0}FCFA", false);

        // Zéro action : refusé, rien n'est réservé.
        $this->actingAs($citoyen)->post(route('souscrire.store'), ['nb_actions' => 0, 'engagements' => $engagements])->assertSessionHasErrors('nb_actions');
        $this->assertSame(0, Souscription::query()->count());

        // Une seule action : acceptée, au prix figé de la tranche.
        $this->actingAs($citoyen)->post(route('souscrire.store'), ['nb_actions' => 1, 'engagements' => $engagements])->assertSessionHasNoErrors()->assertRedirect();
        $s = Souscription::query()->sole();
        $this->assertSame([1, 10_000, 10_000, $tranche->id], [$s->nb_actions_demandees, (int) $s->valeur_unitaire, (int) $s->montant, $s->tranche_id]);
    }

    public function test_les_anciens_recus_et_souscriptions_restent_inchanges(): void
    {
        // Souscription payée avant la valeur indicative à 10 000 FCFA : 8 000 FCFA l'action.
        $tranche = $this->tranche(prix: 8_000, minimum: 1, statut: StatutTranche::Cloturee);
        $citoyen = User::factory()->role(Role::Citoyen)->create(['name' => 'Awa OUÉDRAOGO', 'statut_kyc' => StatutKyc::Valide]);
        $s = Souscription::create([
            'reference' => 'RAA-B-ANCIEN01', 'user_id' => $citoyen->id, 'tranche_id' => $tranche->id, 'nb_actions_demandees' => 3,
            'valeur_unitaire' => 8_000, 'montant' => 24_000, 'frais_souscription' => 0, 'canal' => CanalSouscription::Web,
            'statut' => StatutSouscription::Payee, 'expire_le' => now()->addDay(),
        ]);
        $paiement = $s->paiements()->create(['canal' => 'ORANGE_MONEY', 'montant' => 24_000, 'frais' => 0, 'reference' => 'PAY-ANCIEN01',
            'statut' => StatutPaiement::Confirme, 'date_confirmation' => now()]);
        $avant = $s->fresh()->getAttributes();
        $recu = fn () => view('documents.recu-souscription', ['souscription' => $s->fresh()->load(['tranche', 'user']), 'paiement' => $paiement->fresh(),
            'numero' => Recu::numero($paiement->fresh()), 'pdf' => true])->render();
        $recuAvant = $recu();

        // Nouvelle valeur indicative, nouvelle tranche à 10 000 FCFA : l'ancien reçu garde le prix réellement payé.
        $this->confirmerParametresInvestissement(10_000, minimum: 1);
        $this->tranche(prix: 10_000, minimum: 1, statut: StatutTranche::Brouillon);

        $this->assertSame($avant, $s->fresh()->getAttributes());
        $this->assertSame(8_000, (int) $tranche->fresh()->valeur_unitaire);
        $this->assertSame($recuAvant, $recu());
        $this->assertStringContainsString("8\u{00A0}000\u{00A0}FCFA", $recuAvant);
        $this->assertStringNotContainsString("10\u{00A0}000\u{00A0}FCFA", $recuAvant);
    }

    public function test_tranche_de_demonstration_a_10_000_minimum_1(): void
    {
        $this->seed(ComptesDemoSeeder::class);
        $this->seed(TrancheDemoSeeder::class);
        $this->seed(TrancheDemoSeeder::class);

        $t = Tranche::query()->where('nom', TrancheDemoSeeder::NOM)->sole();
        $this->assertSame([10_000, 1, StatutTranche::Brouillon], [(int) $t->valeur_unitaire, $t->min_actions_par_souscription, $t->statut]);

        // Jamais en production.
        $this->app['env'] = 'production';
        $this->expectException(\Throwable::class);
        (new TrancheDemoSeeder)->run();
    }
}
