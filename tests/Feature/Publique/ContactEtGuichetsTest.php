<?php

namespace Tests\Feature\Publique;

use App\Contracts\SmsProvider;
use App\Models\AuditLog;
use App\Models\DemandeContact;
use App\Models\Guichet;
use App\Support\Telephone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

class ContactEtGuichetsTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsProvider $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsProvider;
        $this->app->instance(SmsProvider::class, $this->sms);
    }

    private function formulaire(array $surcharges = []): array
    {
        return ['nom' => 'Awa OUÉDRAOGO', 'telephone' => '70 12 34 56', 'email' => '', 'objet' => 'paiement',
            'message' => 'Mon paiement Orange Money n’apparaît pas dans mon espace.', ...$surcharges];
    }

    // ---------------------------------------------------------------- Contact

    public function test_la_page_contact_affiche_les_coordonnees_des_parametres(): void
    {
        $this->get('/contact')->assertOk()
            ->assertSee('Nous contacter')
            ->assertSee(Telephone::formaterLocal(plateforme('contacts.numeroVert')))
            ->assertSee(plateforme('contacts.emailAssistance'))
            ->assertSee('Envoyer ma demande');
    }

    public function test_une_demande_est_enregistree_et_son_numero_envoye_par_sms(): void
    {
        $this->post('/contact', $this->formulaire())->assertRedirect(route('contact'))->assertSessionHas('contact.numero');

        $demande = DemandeContact::sole();
        $this->assertMatchesRegularExpression('/^C-\d{6}-\d{5}$/', $demande->numero);
        $this->assertSame('+22670123456', $demande->telephone);
        $this->assertSame('nouvelle', $demande->statut);

        $this->assertCount(1, $this->sms->envoyes);
        $this->assertSame('+22670123456', $this->sms->envoyes[0]['telephone']);
        $this->assertStringContainsString($demande->numero, $this->sms->envoyes[0]['message']);
        $this->assertStringNotContainsString('{', $this->sms->envoyes[0]['message']);

        $this->assertTrue(AuditLog::query()->where('action', 'contact.demande_creee')->where('entite_id', $demande->id)->exists());

        $this->followingRedirects()->post('/contact', $this->formulaire())->assertSee('Demande enregistrée');
    }

    public function test_le_formulaire_valide_les_saisies_en_francais(): void
    {
        $this->from('/contact')->post('/contact', $this->formulaire(['telephone' => '12', 'objet' => 'inconnu', 'message' => 'court']))
            ->assertRedirect('/contact')
            ->assertSessionHasErrors(['telephone', 'objet', 'message']);

        $this->assertSame(0, DemandeContact::count());
        $this->assertSame([], $this->sms->envoyes);
    }

    // ---------------------------------------------------------------- Guichets

    public function test_sans_guichet_un_etat_vide_clair_est_affiche(): void
    {
        Guichet::create(['nom' => 'Fermé', 'region' => 'Centre', 'commune' => 'Ouagadougou', 'adresse' => 'Rue 1', 'actif' => false]);

        $this->get('/guichets')->assertOk()
            ->assertSee('Aucun guichet ouvert pour le moment')
            ->assertDontSee('Fermé')
            ->assertDontSee('id="region"', false);
    }

    public function test_les_guichets_actifs_se_filtrent_par_region_puis_commune(): void
    {
        Guichet::create(['nom' => 'Guichet Gounghin', 'region' => 'Centre', 'commune' => 'Ouagadougou', 'adresse' => 'Avenue Kwame Nkrumah', 'horaires' => 'Lun.–ven. 8 h–17 h', 'telephone' => '+22625300000']);
        Guichet::create(['nom' => 'Guichet Komsilga', 'region' => 'Centre', 'commune' => 'Komsilga', 'adresse' => 'Marché central']);
        Guichet::create(['nom' => 'Guichet Sya', 'region' => 'Hauts-Bassins', 'commune' => 'Bobo-Dioulasso', 'adresse' => 'Place Tiéfo Amoro']);
        Guichet::create(['nom' => 'Guichet inactif', 'region' => 'Est', 'commune' => 'Fada N’Gourma', 'adresse' => 'Rue 2', 'actif' => false]);

        $this->get('/guichets')->assertOk()
            ->assertSee('3 guichets')
            ->assertSee('Lun.–ven. 8 h–17 h')
            ->assertSee('25 30 00 00')
            ->assertDontSee('Guichet inactif')
            ->assertDontSee('<option value="Est"', false);

        $this->get('/guichets?region=Centre')->assertOk()
            ->assertSee('2 guichets')->assertSee('Guichet Komsilga')->assertDontSee('Guichet Sya')
            ->assertSee('<option value="Komsilga"', false);

        $this->get('/guichets?region=Centre&commune=Komsilga')->assertOk()
            ->assertSee('1 guichet')->assertSee('Guichet Komsilga')->assertDontSee('Guichet Gounghin');

        // Valeurs inconnues ignorées : aucun filtre appliqué.
        $this->get('/guichets?region=Atlantide&commune=X')->assertOk()->assertSee('3 guichets');
    }

    public function test_le_pied_de_page_et_la_faq_menent_aux_deux_pages(): void
    {
        foreach (['/', '/faq'] as $page) {
            $this->get($page)
                ->assertSee('href="'.route('contact').'"', false)
                ->assertSee('href="'.route('guichets').'"', false);
        }
    }
}
