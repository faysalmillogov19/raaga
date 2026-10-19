<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutSite;
use App\Models\Boutique;
use App\Models\CampagneBoutiques;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use App\Notifications\DecisionRendue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\ActeursParametrage;
use Tests\Support\GrillesTarifaires;
use Tests\TestCase;

class CampagnesBoutiquesTest extends TestCase
{
    use ActeursParametrage, GrillesTarifaires, RefreshDatabase;

    private Site $site;

    private TypeBoutique $petite;

    private TypeBoutique $grande;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();

        // Référentiel confirmé : 12 petites boutiques (R+1, 9 m²) et 3 grandes (rez-de-chaussée, 24 m²) au marché central.
        $this->site = Site::create(['statut' => StatutSite::Confirme, 'nom' => 'Marché central', 'region' => 'Centre', 'commune' => 'Ouagadougou',
            'adresse' => 'Avenue de la Nation', 'version' => 1, 'cree_par' => $this->regulateur->id]);
        $this->petite = TypeBoutique::create(['libelle' => 'Petite boutique', 'description' => '9 m²']);
        $this->grande = TypeBoutique::create(['libelle' => 'Grande boutique', 'description' => '24 m²']);
        foreach ([[$this->petite, 12, 'P-', 'R1', 9], [$this->grande, 3, 'G-', 'RC', 24]] as [$type, $nombre, $prefixe, $niveau, $surface]) {
            foreach (range(1, $nombre) as $n) {
                $this->site->boutiques()->create(['numero' => $prefixe.$n, 'niveau' => $niveau, 'surface' => $surface, 'type_boutique_id' => $type->id, 'statut' => StatutBoutique::Disponible]);
            }
        }
    }

    private function activerTotp(User $user): string
    {
        $google2fa = new Google2FA;
        $user->forceFill(['totp_secret' => $google2fa->generateSecretKey(32), 'totp_active_le' => now()])->save();

        return $this->codeTotp($user);
    }

    private function codeTotp(User $user, int $decalage = 0): string
    {
        $google2fa = new Google2FA;

        // Le rejeu d'un code est refusé : une seconde action utilise le code de la fenêtre suivante (tolérée).
        return $google2fa->oathTotp($user->fresh()->totp_secret, $google2fa->getTimestamp() + $decalage);
    }

    private function formulaire(array $surcharges = []): array
    {
        return [
            'nom' => 'Réservation 2027 · marché central',
            'description' => 'Boutiques du nouveau marché, réservées au premier arrivé.',
            'date_ouverture' => now()->addDay()->format('Y-m-d\TH:i'),
            'date_cloture' => now()->addMonth()->format('Y-m-d\TH:i'),
            'canaux_actifs' => ['ORANGE_MONEY', 'VIREMENT'],
            'sites' => [$this->site->id],
            'types' => [$this->petite->id, $this->grande->id],
            'limites' => [$this->petite->id => 2, $this->grande->id => 1],
            ...$surcharges,
        ];
    }

    private function campagneSoumise(): CampagneBoutiques
    {
        $this->en($this->regulateur)->post(route('admin.boutiques.store'), $this->formulaire())->assertRedirect()->assertSessionHasNoErrors();
        $campagne = CampagneBoutiques::query()->firstOrFail();
        $this->en($this->regulateur)->post(route('admin.boutiques.soumettre', $campagne))->assertSessionHas('succes');

        return $campagne->refresh();
    }

    public function test_le_regulateur_choisit_les_sites_et_les_parametres(): void
    {
        // Valeurs par défaut : un emplacement par personne et par type, caution à payer sous 72 h.
        $creation = $this->en($this->regulateur)->get(route('admin.boutiques.create'))->assertOk()->assertSee('Marché central')->getContent();
        $this->assertMatchesRegularExpression('/name="limites\['.$this->petite->id.'\]"[^>]*value="1"/', $creation);
        // Délai de paiement de la caution : celui des « Délais de paiement » (72 heures par défaut), affiché sans être saisi.
        $this->assertStringNotContainsString('name="delai_paiement', $creation);
        $this->assertMatchesRegularExpression('/data-delai-caution>\s*<p[^>]*>[^<]*<\/p>\s*<p class="font-semibold">72 heures<\/p>/', $creation);

        $campagne = $this->campagneSoumise();

        $this->assertSame(StatutCampagneBoutiques::Soumise, $campagne->statut);
        $this->assertSame([2, 1], [$campagne->limitePour($this->petite->id), $campagne->limitePour($this->grande->id)]);
        $this->assertStringContainsString(': 2', $campagne->versions()->first()->parametres['limites']);
        $this->assertSame([$this->site->id], $campagne->sites);
        $this->assertSame('Marché central', $campagne->versions()->first()->parametres['sites']);

        // Aucune grille confirmée : l'ouverture sera refusée, la fiche le signale.
        $this->en($this->regulateur)->post(route('admin.boutiques.confirmer', $campagne))->assertForbidden();
        $this->en($this->validateur)->get(route('admin.boutiques.show', $campagne))->assertOk()->assertSee('Marché central')
            ->assertSee('ces sites n’ont pas de grille tarifaire confirmée');
    }

    public function test_une_campagne_sans_site_ou_sur_un_site_non_confirme_est_refusee(): void
    {
        $this->en($this->regulateur)->post(route('admin.boutiques.store'), $this->formulaire(['sites' => []]))
            ->assertSessionHasErrors(['sites' => 'Choisissez au moins un site confirmé.']);

        $this->site->forceFill(['statut' => StatutSite::Brouillon])->save();
        $this->en($this->regulateur)->post(route('admin.boutiques.store'), $this->formulaire())->assertSessionHasErrors('sites.0');

        $this->assertSame(0, CampagneBoutiques::count());
    }

    public function test_rejet_et_confirmation_par_le_validateur_puis_ouverture_automatique(): void
    {
        $campagne = $this->campagneSoumise();

        $this->en($this->validateur)->post(route('admin.boutiques.rejeter', $campagne), ['motif' => 'Délai trop court'])->assertSessionHas('succes');
        $this->assertSame(StatutCampagneBoutiques::Brouillon, $campagne->refresh()->statut);

        $this->en($this->regulateur)->put(route('admin.boutiques.update', $campagne), $this->formulaire(['limites' => [$this->petite->id => 1, $this->grande->id => 1]]))->assertSessionHasNoErrors();
        $this->assertSame(1, $campagne->refresh()->limitePour($this->petite->id));
        $this->en($this->regulateur)->post(route('admin.boutiques.soumettre', $campagne));
        $this->en($this->validateur)->post(route('admin.boutiques.confirmer', $campagne))->assertSessionHas('succes');

        // Confirmée avant sa date : elle attend son ouverture automatique ; plus de bouton « Ouvrir les réservations ».
        $this->assertSame(StatutCampagneBoutiques::Confirmee, $campagne->refresh()->statut);
        $this->en($this->validateur)->get(route('admin.boutiques.show', $campagne))->assertOk()
            ->assertSee('data-ouverture-prevue', false)->assertDontSee('Ouvrir les réservations');

        // Date atteinte sans grille confirmée sur le site : l'ouverture attend, la fiche dit pourquoi.
        $this->travel(2)->days();
        $this->artisan('raaga:campagnes-boutiques')->assertSuccessful()->expectsOutputToContain('0 campagne(s) ouverte(s)');
        $this->assertSame(StatutCampagneBoutiques::Confirmee, $campagne->refresh()->statut);
        $this->en($this->validateur)->get(route('admin.boutiques.show', $campagne))->assertSee('data-ouverture-attente', false)->assertSee('grille tarifaire confirmée');

        $this->grilleConfirmee($this->site, ['R1' => [$this->petite->id => [2000, 12, 36]], 'RC' => [$this->grande->id => [2000, 12, 60]]]);
        $this->artisan('raaga:campagnes-boutiques')->assertSuccessful()->expectsOutputToContain('1 campagne(s) ouverte(s)');
        $this->assertSame(StatutCampagneBoutiques::Ouverte, $campagne->refresh()->statut);
        $this->en($this->validateur)->get(route('admin.boutiques.show', $campagne))->assertSee('data-cloture-prevue', false);

        // Aucune boutique n'est engagée en bloc : elles restent disponibles jusqu'à leur réservation.
        $this->assertSame(15, Boutique::query()->where('statut', StatutBoutique::Disponible)->count());
        $this->assertSame(['disponible' => 15, 'pre_reservee' => 0, 'reservee' => 0, 'attribuee' => 0], $campagne->compteurs());

        // Clôture anticipée par le VALIDATEUR : confirmation renforcée (mot et code TOTP).
        $this->activerTotp($this->validateur);
        $this->en($this->validateur)->post(route('admin.boutiques.cloturer', $campagne), ['confirmation' => 'CONFIRMER', 'code_totp' => '000000'])->assertSessionHas('erreur');
        $this->en($this->validateur)->post(route('admin.boutiques.cloturer', $campagne), ['confirmation' => 'CONFIRMER', 'code_totp' => $this->codeTotp($this->validateur, 1)])->assertSessionHas('succes');
        $this->assertSame(StatutCampagneBoutiques::Cloturee, $campagne->refresh()->statut);
        $this->assertDatabaseHas('audit_logs', ['action' => 'campagne_boutiques.ouverte', 'entite_id' => $campagne->id, 'acteur_id' => null]);
    }

    public function test_les_profils_de_support_consultent_sans_rien_modifier(): void
    {
        $campagne = $this->campagneSoumise();
        $superAdmin = User::factory()->role(Role::SuperAdmin)->create();

        $this->en($superAdmin)->get(route('admin.boutiques.show', $campagne))->assertOk();
        $this->en($superAdmin)->post(route('admin.boutiques.confirmer', $campagne))->assertForbidden();
        $this->en($superAdmin)->get(route('admin.boutiques.create'))->assertForbidden();
        $this->en(User::factory()->role(Role::Admin)->create())->get(route('admin.boutiques'))->assertForbidden();
    }

    public function test_decision_depuis_la_fiche_de_la_campagne_notifiee_une_seule_fois(): void
    {
        $campagne = $this->campagneSoumise();
        Notification::fake();

        $this->en($this->validateur)->post(route('admin.boutiques.confirmer', $campagne))->assertSessionHas('succes');

        Notification::assertSentToTimes($this->regulateur, DecisionRendue::class, 1);
        Notification::assertSentTo($this->regulateur, DecisionRendue::class, fn (DecisionRendue $n) => str_contains($n->toArray($this->regulateur)['message'], 'Campagne')
            && str_contains($n->toArray($this->regulateur)['message'], 'validé'));
        Notification::assertCount(1);
    }

    public function test_delais_des_listes_d_attente_saisis_par_le_regulateur(): void
    {
        // Sans saisie : 7 jours par défaut.
        $this->en($this->regulateur)->post(route('admin.boutiques.store'), $this->formulaire())->assertSessionHasNoErrors();
        $defaut = CampagneBoutiques::query()->latest('id')->firstOrFail();
        $this->assertSame([604_800, '7 jours'], [$defaut->delaiListeSecondes('kyc'), $defaut->libelleDelaiListe('relance')]);

        // Saisis : valeur et durée, convertis en secondes ; affichés sur la fiche et figés dans la version.
        $this->en($this->regulateur)->post(route('admin.boutiques.store'), $this->formulaire(['nom' => 'Délais',
            'delai_kyc_valeur' => 2, 'delai_kyc_duree' => 'JOUR', 'delai_relance_valeur' => 48, 'delai_relance_duree' => 'HEURE']))->assertSessionHasNoErrors();
        $c = CampagneBoutiques::query()->where('nom', 'Délais')->sole();
        $this->assertSame([172_800, 172_800], [$c->delaiListeSecondes('kyc'), $c->delaiListeSecondes('relance')]);
        $this->en($this->regulateur)->get(route('admin.boutiques.show', $c))->assertOk()
            ->assertSee('data-delai-liste="kyc"', false)->assertSee('2 jours')->assertSee('48 heures');
        $this->en($this->regulateur)->get(route('admin.boutiques.edit', $c))->assertOk()->assertSee('data-delais-listes', false);
        $this->assertSame('2 jours', $c->snapshotParametres()['delai_kyc']);

        // Valeur sans durée : refusée.
        $this->en($this->regulateur)->post(route('admin.boutiques.store'), $this->formulaire(['delai_kyc_valeur' => 3]))->assertSessionHasErrors('delai_kyc_duree');
    }
}
