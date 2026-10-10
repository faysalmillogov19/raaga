<?php

namespace Tests\Feature\Admin;

use App\Contracts\SmsProvider;
use App\Enums\Role;
use App\Enums\StatutTranche;
use App\Http\Controllers\Admin\TranchesController;
use App\Models\AuditLog;
use App\Models\Tranche;
use App\Models\User;
use App\Support\Armoiries;
use App\Support\Plateforme\Catalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\Finder;
use Tests\Support\ConnexionBackoffice;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

class ParametresPlateformeTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    /** Une saisie valide par section (noms de champs du formulaire). */
    private function saisie(string $section): array
    {
        return match ($section) {
            'identite' => ['nomPlateforme' => 'Raaga · Souscription', 'sousTitre' => 'Plateforme officielle', 'mentionTutelle' => 'Sous tutelle',
                'deviseNationale' => 'Unité', 'expediteurSms' => 'RAAGASA', 'urlSiteOfficiel' => 'https://raaga.example.bf'],
            'contacts' => ['numeroVert' => '80 00 00 00', 'telephoneInternational' => '+226 25 30 00 00', 'emailAssistance' => 'aide@raaga.example.bf',
                'horairesCentreAppels' => ['jours' => ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam'], 'debut' => '07:30', 'fin' => '18:00'],
                'messageHorsHoraires' => 'Laissez un message.'],
            'siege' => ['adresseSiege' => "Avenue Kwame N'Krumah\nOuagadougou", 'boitePostale' => '01 BP 1000',
                'coordonneesGps' => ['latitude' => '12.37', 'longitude' => '-1.52'], 'lienCarte' => 'https://maps.example.org/raaga'],
            'contacts_specialises' => ['contactFraude' => ['libelle' => 'Signaler une fraude', 'nom' => 'Cellule fraude', 'telephone' => '+22625300001', 'email' => 'fraude@raaga.example.bf', 'afficher' => '1']],
            'mentions' => ['denominationSociale' => 'Raaga SA', 'formeJuridique' => 'Société anonyme', 'capitalSocial' => '10000000000', 'rccm' => 'BF-OUA-2026-B-0001',
                'ifu' => '00012345X', 'directeurPublication' => ['nom' => 'A. SAWADOGO', 'fonction' => 'Directrice générale']],
            'reseaux' => ['reseauxSociaux' => [['reseau' => 'facebook', 'url' => 'https://facebook.com/raaga', 'afficher' => '1', 'ordre' => '1']],
                'liensInstitutionnels' => [['libelle' => 'Service public', 'url' => 'https://service-public.gov.bf', 'ordre' => '1']]],
            'projet' => ['nombreBoutiques' => '900', 'coutTotalProjet' => '100', 'repartitionFinancement' => [
                ['libelle' => 'A', 'montant' => '60', 'couleur' => 'vert', 'ordre' => '1'],
                ['libelle' => 'B', 'montant' => '40', 'couleur' => 'gris', 'ordre' => '2'],
            ]],
            'messages' => ['bandeauAnnonce' => ['texte' => 'Ouverture le 1er novembre', 'type' => 'information'], 'messageAntiFraude' => 'Ne communiquez jamais votre code.'],
        };
    }

    private function enregistrer(string $section, array $surcharges = [], bool $confirmer = true)
    {
        return $this->put(route('admin.plateforme.update', $section), [...$this->saisie($section), ...$surcharges, 'confirmation_sensible' => $confirmer ? '1' : '0']);
    }

    public function test_le_super_admin_modifie_les_8_sections(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);

        foreach (array_keys(Catalogue::SECTIONS) as $section) {
            $this->get(route('admin.plateforme', ['section' => $section]))->assertOk()->assertDontSee('Modifiable par un SUPER_ADMIN');
            $this->enregistrer($section)->assertSessionHasNoErrors()->assertSessionHas('succes');
        }

        $this->assertSame('RAAGASA', plateforme('identite.expediteurSms'));
        $this->assertSame('+22680000000', plateforme('contacts.numeroVert'));
        $this->assertSame(10_000_000_000, plateforme('mentions.capitalSocial'));
        $this->assertSame(8, AuditLog::query()->where('action', 'plateforme.modifiee')->count());
    }

    public function test_l_admin_modifie_les_sections_2_3_6_8_et_toute_autre_ecriture_renvoie_403(): void
    {
        $this->connecterBackoffice(Role::Admin);

        foreach (['contacts', 'siege', 'reseaux', 'messages'] as $section) {
            $this->enregistrer($section)->assertSessionHasNoErrors()->assertSessionHas('succes');
        }

        foreach (['identite', 'contacts_specialises', 'mentions', 'projet'] as $section) {
            $this->get(route('admin.plateforme', ['section' => $section]))->assertOk()
                ->assertSee('Modifiable par un SUPER_ADMIN')->assertDontSee(route('admin.plateforme.update', $section));
            $this->enregistrer($section)->assertForbidden();
            $this->post(route('admin.plateforme.apercu', $section), $this->saisie($section))->assertForbidden();
        }

        $version = DB::table('parametres_plateforme_versions')->value('id');
        $this->post(route('admin.plateforme.restaurer', $version))->assertForbidden();
        $this->assertSame('Raaga · Participation populaire', plateforme('identite.nomPlateforme'));
    }

    public static function profilsSansAcces(): array
    {
        return ['VALIDATEUR' => [Role::Validateur], 'REGULATEUR' => [Role::Regulateur]];
    }

    #[DataProvider('profilsSansAcces')]
    public function test_regulateur_et_validateur_n_ont_aucun_acces(Role $role): void
    {
        $this->connecterBackoffice($role);

        $this->get(route('admin.plateforme'))->assertForbidden();
        $this->enregistrer('contacts')->assertForbidden();
        $this->assertSame('+22680000000', plateforme('contacts.numeroVert'));
    }

    public function test_le_changement_du_numero_vert_apparait_partout(): void
    {
        $sms = new FakeSmsProvider;
        $this->app->instance(SmsProvider::class, $sms);
        $sa = $this->connecterBackoffice(Role::SuperAdmin);

        // Sans confirmation : refusé, rien ne change.
        $this->enregistrer('contacts', ['numeroVert' => '81 23 45 67'], confirmer: false)->assertSessionHasErrors('confirmation_sensible');
        $this->assertSame('+22680000000', plateforme('contacts.numeroVert'));

        $this->enregistrer('contacts', ['numeroVert' => '81 23 45 67'])->assertSessionHas('succes');

        // En-tête et pied de page (accueil), page contact (FAQ), page de connexion.
        $accueil = $this->get('/')->assertOk()->getContent();
        $this->assertSame(2, substr_count($accueil, '81 23 45 67'), 'en-tête et pied de page');
        $this->get('/faq')->assertSee('81 23 45 67');
        $this->assertStringNotContainsString('80 00 00 00', $this->get('/faq')->getContent());

        // Prochain SMS : expéditeur et numéro vert issus des paramètres.
        User::factory()->role(Role::Citoyen)->create(['telephone' => '+22670123456']);
        $this->post('/admin/deconnexion');
        $this->post('/connexion/telephone', ['telephone' => '70 12 34 56']);
        $dernier = end($sms->envoyes);
        $this->assertStringContainsString('Aide : 81 23 45 67', $dernier['message']);
        $this->assertSame('RAAGA', $dernier['expediteur']);

        // Prochain document (en-tête commun des futurs PDF : règlement de tranche).
        $tranche = Tranche::create([...TranchesController::valeursParDefaut(), 'nom' => 'Tranche 1', 'statut' => StatutTranche::Brouillon, 'version' => 0, 'cree_par' => $sa->id]);
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->get(route('admin.tranches.reglement', $tranche))->assertOk()->assertSee('Numéro vert 81 23 45 67');
    }

    public function test_une_repartition_dont_le_total_differe_du_cout_est_refusee(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);

        $this->enregistrer('projet', ['coutTotalProjet' => '150'])
            ->assertSessionHasErrors(['repartitionFinancement' => "Le total de la répartition (100\u{00A0}FCFA) doit être égal au coût total du projet (150\u{00A0}FCFA)."]);

        $this->assertSame(16_245_000_000, plateforme('projet.coutTotalProjet'));
    }

    public function test_les_valeurs_invalides_sont_refusees_avec_un_message_en_francais(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);

        $this->enregistrer('identite', ['expediteurSms' => 'RAAGA SA 2026', 'urlSiteOfficiel' => 'http://raaga.bf'])
            ->assertSessionHasErrors([
                'expediteurSms' => '11 lettres ou chiffres au plus, sans espace ni accent.',
                'urlSiteOfficiel' => 'L’adresse doit commencer par https://.',
            ]);
        $this->enregistrer('identite', ['armoiries' => UploadedFile::fake()->create('logo.jpg', 100, 'image/jpeg')])
            ->assertSessionHasErrors(['armoiries' => 'Fichier SVG ou PNG de 500 Ko au plus.']);
    }

    public function test_les_armoiries_deposees_sont_servies_sans_execution_de_script(): void
    {
        Storage::fake('local');
        $this->connecterBackoffice(Role::SuperAdmin);

        $avant = Armoiries::url();

        // Fichier trop lourd : refusé, et le formulaire le dit en haut de page.
        $this->from(route('admin.plateforme', ['section' => 'identite']))->followingRedirects()
            ->put(route('admin.plateforme.update', 'identite'), [...$this->saisie('identite'), 'armoiries' => UploadedFile::fake()->create('armoiries.png', 600, 'image/png')])
            ->assertSee('Rien n’a été enregistré', false)->assertSee('Les armoiries n’ont pas été remplacées', false);

        $svg = UploadedFile::fake()->createWithContent('armoiries.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->enregistrer('identite', ['armoiries' => $svg])->assertSessionHas('succes');

        // Nouvelle adresse versionnée : aucun navigateur ne garde l'ancienne image.
        $apres = Armoiries::url();
        $this->assertNotSame($avant, $apres);
        $this->get('/')->assertSee(e($apres), false);

        $reponse = $this->get('/armoiries')->assertOk();
        $this->assertStringContainsString("default-src 'none'", $reponse->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $reponse->headers->get('X-Content-Type-Options'));
    }

    public function test_le_mode_maintenance_masque_le_site_public_mais_pas_le_back_office(): void
    {
        $this->connecterBackoffice(Role::Admin);

        $this->enregistrer('messages', ['modeMaintenance' => ['actif' => '1', 'message' => 'Mise à jour en cours.']])->assertSessionHas('succes');

        foreach (['/', '/faq', '/offre', '/connexion'] as $url) {
            $this->get($url)->assertStatus(503)->assertSee('Mise à jour en cours.');
        }
        $this->get('/admin')->assertOk();
        $this->get(route('admin.plateforme'))->assertOk();

        $this->enregistrer('messages', ['modeMaintenance' => ['actif' => '0']])->assertSessionHas('succes');
        $this->get('/')->assertOk();
    }

    public function test_un_brouillon_d_aperçu_n_est_jamais_enregistre(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);

        $this->post(route('admin.plateforme.apercu', 'identite'), [...$this->saisie('identite'), 'nomPlateforme' => 'Nom en aperçu'])
            ->assertOk()->assertSee('Nom en aperçu')->assertSee('ces valeurs ne sont pas encore enregistrées');

        $this->get('/')->assertDontSee('Nom en aperçu')->assertSee('Raaga · Participation populaire');
        $this->assertSame(0, DB::table('parametres_plateforme_versions')->count());
    }

    public function test_la_restauration_d_une_version_remet_les_anciennes_valeurs_partout(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);

        $this->enregistrer('identite', ['nomPlateforme' => 'Premier nom']);
        $this->enregistrer('identite', ['nomPlateforme' => 'Second nom']);
        $this->get('/')->assertSee('Second nom');

        $premiere = DB::table('parametres_plateforme_versions')->where('section', 'identite')->orderBy('id')->first();
        $this->get(route('admin.plateforme', ['section' => 'identite', 'onglet' => 'historique']))->assertOk()
            ->assertSee('Premier nom')->assertSee('Second nom')->assertSee('Restaurer cette version');

        $this->post(route('admin.plateforme.restaurer', $premiere->id))->assertSessionHas('succes');

        $this->get('/')->assertSee('Premier nom')->assertDontSee('Second nom');
        $this->assertSame(3, DB::table('parametres_plateforme_versions')->where('section', 'identite')->count());
        $this->assertTrue(AuditLog::query()->where('action', 'plateforme.restauree')->exists());
    }

    public function test_un_champ_vide_n_est_jamais_affiche(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->enregistrer('contacts', ['numeroVert' => '', 'emailAssistance' => '', 'telephoneInternational' => '', 'messageHorsHoraires' => '',
            'horairesCentreAppels' => ['jours' => [], 'debut' => '', 'fin' => '']]);

        $faq = $this->get('/faq')->assertOk()->getContent();
        $this->assertStringNotContainsString('Numéro vert gratuit', $faq);
        $this->assertStringNotContainsString('null', strip_tags($faq));
        $this->assertStringNotContainsString('non communiqué', $faq);
    }

    public function test_aucune_valeur_publique_ne_reste_codee_en_dur(): void
    {
        $anciennes = ['80 00 00 00', '22680000000', 'contact@raaga.bf', 'Raaga · Participation populaire', 'La Patrie ou la Mort',
            'Placée sous la tutelle', 'Plateforme officielle de souscription', 'CNSS, CARFO', 'Futurs occupants', '16_245_000_000',
            '6_454_000_000', 'Raaga ne vous demandera jamais votre mot de passe'];

        $fichiers = Finder::create()->files()->in(array_map('base_path', ['app', 'resources', 'lang', 'config', 'routes', 'bootstrap']))
            ->notPath(['cache'])->name(['*.php', '*.js', '*.css']);

        $trouves = [];
        foreach ($fichiers as $fichier) {
            foreach ($anciennes as $valeur) {
                if (str_contains($fichier->getContents(), $valeur)) {
                    $trouves[] = $fichier->getRelativePathname()." : « {$valeur} »";
                }
            }
        }

        $this->assertSame([], $trouves, 'Valeurs codées en dur (hors seed) : '.implode(' ; ', $trouves));
    }
}
