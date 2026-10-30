<?php

namespace Tests\Feature\Cartes;

use App\Enums\Role;
use App\Enums\StatutCarte;
use App\Enums\StatutKyc;
use App\Enums\TypeCarte;
use App\Models\AuditLog;
use App\Models\Carte;
use App\Models\User;
use App\Services\Cartes\Cartes;
use App\Services\Cartes\Code128;
use App\Services\Cartes\Filigrane;
use App\Services\Cartes\ReglagesCarteManquants;
use App\Services\Cartes\RenduCarte;
use App\Services\Plateforme\ParametresPlateforme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ConnexionBackoffice;
use Tests\Support\ReglagesCarteComplets;
use Tests\TestCase;

/**
 * Carte d'identification (maquette validée le 24/10/2026) : code-barres Code 128, réglages figés à l'émission et
 * réimpression à l'identique, génération refusée si un réglage manque, rendu des trois types (PNG 1011 × 638 par face,
 * PDF d'une page par face), filigrane or des armoiries mis en cache par fichier source.
 */
class CarteIdentificationRenduTest extends TestCase
{
    use ConnexionBackoffice;
    use RefreshDatabase;
    use ReglagesCarteComplets;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function carte(TypeCarte $type = TypeCarte::Commercant, ?User $user = null, array $contenu = []): Carte
    {
        $user ??= User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'date_naissance' => '1988-03-14']);
        $carte = Carte::create(['type' => $type, 'user_id' => $user->id, 'statut' => StatutCarte::Active, 'courante' => true, 'jeton' => Str::random(64),
            'emise_le' => now(), 'valide_jusqu_au' => '2028-12-31', 'contenu' => ['nom' => 'Aïcha OUATTARA', ...$contenu]]);
        $carte->forceFill(['numero' => sprintf('RG-C-%06d', $carte->id)])->save();

        return $carte;
    }

    public function test_code_128_jeux_b_et_c_et_somme_de_controle(): void
    {
        // Jeu B : départ 104, A B C = 33 34 35, contrôle (104 + 33 + 2 × 34 + 3 × 35) mod 103 = 1.
        $this->assertSame([104, 33, 34, 35, 1], Code128::symboles('ABC'));
        // Jeu C dès le départ pour quatre chiffres : 105, 12, 34, contrôle (105 + 12 + 2 × 34) mod 103 = 82.
        $this->assertSame([105, 12, 34, 82], Code128::symboles('1234'));
        // Numéro et expiration : « RG-C- » en B, « 000003 » en C, « - » en B, « 1228 » en C.
        $s = Code128::symboles('RG-C-000003-1228');
        $this->assertSame([104, 50, 39, 13, 35, 13, 99, 0, 0, 3, 100, 13, 99, 12, 28], array_slice($s, 0, -1));
        $somme = $s[0];
        foreach (array_slice($s, 1, -1) as $i => $v) {
            $somme += ($i + 1) * $v;
        }
        $this->assertSame($somme % 103, end($s));
        // Chaque symbole fait 11 modules, l'arrêt 13.
        $this->assertSame(count($s) * 11 + 13, array_sum(Code128::largeurs('RG-C-000003-1228')));
    }

    public function test_les_reglages_sont_figes_a_l_emission_et_une_reimpression_les_reutilise(): void
    {
        $anciens = $this->completerReglagesCarte();
        $agent = User::factory()->role(Role::Admin)->create(['date_naissance' => '1990-01-01']);
        $carte = app(Cartes::class)->emettrePersonnel($agent, User::factory()->role(Role::SuperAdmin)->create());
        $this->assertSame($anciens['signatureAutorite'], $carte->reglages_rendu['signature']);
        $this->assertSame('Awa OUÉDRAOGO', $carte->reglages_rendu['nom']);

        // Nouvelle signature et nouvelle autorité dans les paramètres.
        $nouvelle = 'plateforme/signature-autorite-nouvelle.png';
        Storage::disk('local')->put($nouvelle, $this->pngTest(900, 300, [200, 0, 0]));
        $parametres = app(ParametresPlateforme::class);
        $parametres->enregistrer('carte', [...$parametres->section('carte'), 'signatureAutorite' => $nouvelle, 'nomAutorite' => 'Issa KABORÉ'], null);

        // Réimpression : l'ancienne signature et l'ancien nom.
        $donnees = app(RenduCarte::class)->donnees($carte->fresh());
        $ancienneSignature = 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($anciens['signatureAutorite']));
        $this->assertSame($ancienneSignature, $donnees['signature']);
        $this->assertSame('Awa OUÉDRAOGO', $donnees['nom_autorite']);

        // Nouvelle émission : les paramètres en vigueur.
        $reemise = app(Cartes::class)->reemettre($agent, TypeCarte::Personnel, $agent);
        $this->assertSame($nouvelle, $reemise->reglages_rendu['signature']);
        $this->assertSame('Issa KABORÉ', app(RenduCarte::class)->donnees($reemise)['nom_autorite']);
        Storage::disk('local')->assertExists([$anciens['signatureAutorite'], $nouvelle]);
    }

    public function test_la_generation_s_arrete_si_un_reglage_manque(): void
    {
        $this->completerReglagesCarte(['cachetAutorite' => null]);
        $carte = $this->carte();
        $this->assertNull($carte->reglages_rendu);

        try {
            app(RenduCarte::class)->donnees($carte);
            $this->fail('La génération aurait dû être refusée.');
        } catch (ReglagesCarteManquants $e) {
            $this->assertSame(['cachetAutorite'], $e->manquants);
            $this->assertStringContainsString('Cachet de l’autorité', $e->getMessage());
        }

        // Impression au guichet : refusée avec le détail des réglages, rien n'est tracé.
        // Titulaire : retour sur sa carte avec « en cours de préparation » (ni page d'erreur, ni mention des réglages) ;
        // sa page « Ma carte » le dit à la place de la carte, sans bouton de téléchargement.
        $this->actingAs($carte->user)->from(route('espace.carte', ['type' => 'commercant']))->get(route('cartes.fichier', [$carte, 'pdf']))
            ->assertRedirect(route('espace.carte', ['type' => 'commercant']))
            ->assertSessionHas('erreur', 'Votre carte est en cours de préparation. Elle sera disponible prochainement.');
        $this->get(route('espace.carte', ['type' => 'commercant']))->assertOk()->assertSee('data-carte-preparation', false)
            ->assertSee('Votre carte est en cours de préparation. Elle sera disponible prochainement.')
            ->assertDontSee('data-carte-identification', false)->assertDontSee('data-telecharger', false)->assertDontSee('Cachet');
        $this->app['auth']->forgetGuards();
        $this->connecterBackoffice(Role::AgentGuichet);
        $this->from(route('admin.guichet.carte', $carte->jeton))->post(route('admin.guichet.imprimer', $carte->jeton), ['piece_verifiee' => '1'])
            ->assertSessionHasErrors(['piece_verifiee' => (new ReglagesCarteManquants(['cachetAutorite']))->getMessage()]);
        $this->assertSame(0, AuditLog::query()->where('action', 'carte.imprimee')->count());
        $this->assertNull($carte->fresh()->reglages_rendu);

        // Réglage complété : la carte, émise avant, fige les réglages à sa première génération.
        $this->completerReglagesCarte();
        $this->assertSame('Le Maire de l’Arrondissement N°1', app(RenduCarte::class)->donnees($carte)['qualite']);
        $this->assertNotNull($carte->fresh()->reglages_rendu);
    }

    public function test_autre_echec_du_rendu_message_technique_pour_le_titulaire(): void
    {
        $this->completerReglagesCarte();
        $carte = $this->carte();
        // Chrome introuvable : la génération échoue pour une autre raison qu'un réglage manquant.
        config(['raaga.cartes.chrome' => 'C:/introuvable/chrome.exe']);

        $this->actingAs($carte->user)->from(route('espace.carte', ['type' => 'commercant']))->get(route('cartes.fichier', [$carte, 'png']))
            ->assertRedirect(route('espace.carte', ['type' => 'commercant']))
            ->assertSessionHas('erreur', 'Votre carte ne peut pas être téléchargée pour le moment. Réessayez plus tard ou contactez l’assistance.');
    }

    public function test_ma_carte_affiche_le_recto_et_le_verso_du_gabarit_sans_boutique(): void
    {
        $this->completerReglagesCarte();
        $carte = $this->carte(contenu: ['emplacements' => [['code' => 'RAA-R1-A-045', 'site' => 'Marché de Rood Woko']]]);

        // Site public : recto et verso du gabarit (titulaire, né(e) le, carte n°, expire le), aucune boutique ni marché.
        $this->actingAs($carte->user)->get(route('espace.carte', ['type' => 'commercant']))->assertOk()
            ->assertSee('data-carte-identification', false)->assertSee('data-face="recto"', false)->assertSee('data-face="verso"', false)
            ->assertSee('Aïcha OUATTARA')->assertSee('Expire le')->assertSee('31/12/2028')->assertSee('14/03/1988')->assertSee($carte->numero)
            ->assertSee('Le Maire de l’Arrondissement N°1')->assertSee('css/carte-raaga.css', false)
            ->assertDontSee('RAA-R1-A-045')->assertDontSee('Rood Woko')->assertDontSee('SPÉCIMEN')->assertSee('data-plein-ecran', false);

        // Carte suspendue : la carte reste affichée, sans plein écran ni téléchargement.
        $carte->forceFill(['statut' => StatutCarte::Suspendue])->save();
        $this->get(route('espace.carte', ['type' => 'commercant']))->assertOk()->assertSee('data-carte-identification', false)
            ->assertDontSee('data-plein-ecran', false)->assertDontSee('data-telecharger', false);

        // Back-office : « Ma carte » du personnel, même gabarit ; réglage manquant : « en cours de préparation ».
        $this->app['auth']->forgetGuards();
        $admin = $this->connecterBackoffice(Role::Admin);
        $personnel = $this->carte(TypeCarte::Personnel, $admin, ['nom' => 'Rasmata OUÉDRAOGO', 'fonction' => 'Administrateur']);
        $this->get(route('admin.carte'))->assertOk()->assertSee('data-carte-identification', false)->assertSee('Rasmata OUÉDRAOGO')
            ->assertSee('data-plein-ecran', false)->assertSee(route('cartes.fichier', [$personnel, 'png']), false);
        $this->app['auth']->forgetGuards();
        $this->carte(TypeCarte::Personnel, $this->connecterBackoffice(Role::Admin));
        $this->completerReglagesCarte(['cachetAutorite' => null]);
        $this->get(route('admin.carte'))->assertOk()->assertSee('data-carte-preparation', false)
            ->assertDontSee('data-carte-identification', false);
    }

    public function test_recto_et_verso_de_chaque_type_en_png_et_en_pdf(): void
    {
        $this->exigerChrome();
        $this->completerReglagesCarte(filigranes: true);
        $rendu = app(RenduCarte::class);

        foreach ([TypeCarte::Commercant, TypeCarte::Actionnaire, TypeCarte::Personnel] as $type) {
            $carte = $this->carte($type);
            $donnees = $rendu->donnees($carte);
            $this->assertSame(mb_strtoupper($type->label()), $donnees['type']);
            $this->assertSame([$carte->numero.'-1228', '14/03/1988', '31/12/2028', '+226 80 00 00 00'],
                [$donnees['code'], $donnees['ne_le'], $donnees['expire'], $donnees['numero_vert']]);

            $html = $rendu->html($donnees, 'pdf');
            $this->assertStringNotContainsString('Emplacement', $html);
            foreach (['recto-principal', 'recto-secondaire', 'verso-principal', 'verso-secondaire'] as $place) {
                $this->assertStringContainsString('class="cr-filigrane cr-filigrane-'.$place.'"', $html);
            }
            $this->assertStringContainsString('N° de carte · Expiration', $html);

            $faces = $rendu->pngFaces($carte);
            foreach ($faces as $png) {
                $image = imagecreatefromstring($png);
                $this->assertSame([1011, 638], [imagesx($image), imagesy($image)]);
                // Coins arrondis : le coin de l'image est transparent.
                $this->assertSame(127, (imagecolorat($image, 0, 0) >> 24) & 0x7F);
            }
            $pdf = $rendu->pdf($carte);
            $this->assertStringStartsWith('%PDF', $pdf);
            $this->assertSame(2, preg_match_all('#/Type\s*/Page\b#', $pdf));
        }
    }

    public function test_filigrane_or_fond_blanc_transparent_opacite_du_rang_et_cache_par_source(): void
    {
        Storage::fake('local');
        $filigrane = app(Filigrane::class);
        $source = Storage::disk('local')->path('source.png');
        file_put_contents($source, $this->pngCouleur(1000, 800, [0, 107, 60]));

        $principal = $filigrane->chemin($source, 0.14);
        $this->assertSame($principal, $filigrane->chemin($source, 0.14));
        $this->assertNotSame($principal, $filigrane->chemin($source, 0.11));
        $this->assertCount(2, Storage::disk('local')->files('cartes/filigranes'));

        // Fond blanc : transparent ; motif : or, à 14 % au plus.
        $image = imagecreatefrompng($principal);
        $this->assertSame(127, (imagecolorat($image, 2, 2) >> 24) & 0x7F);
        $centre = imagecolorat($image, intdiv(imagesx($image), 2), intdiv(imagesy($image), 2));
        $this->assertSame(Filigrane::OR, [($centre >> 16) & 0xFF, ($centre >> 8) & 0xFF, $centre & 0xFF]);
        $alpha = ($centre >> 24) & 0x7F;
        $this->assertLessThan(127, $alpha);
        $this->assertGreaterThanOrEqual(127 - (int) round(0.14 * 127), $alpha);

        // Autre fichier source : autre filigrane.
        $autre = Storage::disk('local')->path('autre.png');
        file_put_contents($autre, $this->pngCouleur(900, 900, [150, 30, 30]));
        $this->assertNotSame($principal, $filigrane->chemin($autre, 0.14));
    }

    public function test_filigranes_facultatifs_la_carte_se_genere_sans_eux_avec_un_avertissement(): void
    {
        $this->completerReglagesCarte();
        $carte = $this->carte();

        $donnees = app(RenduCarte::class)->donnees($carte);
        $this->assertSame([null, null, null, null], array_values($donnees['filigranes']));
        $this->assertStringNotContainsString('class="cr-filigrane ', app(RenduCarte::class)->html($donnees, 'pdf'));

        $this->connecterBackoffice(Role::SuperAdmin);
        $this->get(route('admin.plateforme', ['section' => 'carte']))->assertOk()
            ->assertSee('data-etat-carte="pret"', false)->assertSee('data-filigranes-manquants', false)
            ->assertSee('Filigrane secondaire recto non renseigné');
    }

    public function test_une_reimpression_garde_l_ancien_filigrane(): void
    {
        $anciens = $this->completerReglagesCarte(filigranes: true);
        $agent = User::factory()->role(Role::Admin)->create();
        $carte = app(Cartes::class)->emettrePersonnel($agent, User::factory()->role(Role::SuperAdmin)->create());
        $this->assertSame($anciens['filigranePrincipalRecto'], $carte->reglages_rendu['filigranePrincipalRecto']);

        // Nouveau filigrane principal recto dans les paramètres.
        Storage::disk('local')->put('plateforme/filigrane-nouveau.png', $this->pngCouleur(1000, 800, [200, 0, 0]));
        $parametres = app(ParametresPlateforme::class);
        $parametres->enregistrer('carte', [...$parametres->section('carte'), 'filigranePrincipalRecto' => 'plateforme/filigrane-nouveau.png'], null);

        $filigrane = app(Filigrane::class);
        $attendu = fn (string $relatif) => 'data:image/png;base64,'.base64_encode((string) file_get_contents($filigrane->chemin(Storage::disk('local')->path($relatif), 0.14)));
        // Réimpression : l'ancien ; nouvelle émission : le nouveau.
        $this->assertSame($attendu($anciens['filigranePrincipalRecto']), app(RenduCarte::class)->donnees($carte->fresh())['filigranes']['filigranePrincipalRecto']);
        $reemise = app(Cartes::class)->reemettre($agent, TypeCarte::Personnel, $agent);
        $this->assertSame($attendu('plateforme/filigrane-nouveau.png'), app(RenduCarte::class)->donnees($reemise)['filigranes']['filigranePrincipalRecto']);
        Storage::disk('local')->assertExists([$anciens['filigranePrincipalRecto'], 'plateforme/filigrane-nouveau.png']);
    }
}
