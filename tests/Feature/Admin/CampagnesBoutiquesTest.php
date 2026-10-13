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
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

class CampagnesBoutiquesTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private Site $site;

    private TypeBoutique $petite;

    private TypeBoutique $grande;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();

        // Référentiel confirmé : 12 petites et 3 grandes boutiques au marché central.
        $this->site = Site::create(['statut' => StatutSite::Confirme, 'nom' => 'Marché central', 'region' => 'Centre', 'commune' => 'Ouagadougou',
            'adresse' => 'Avenue de la Nation', 'version' => 1, 'cree_par' => $this->regulateur->id]);
        $this->petite = TypeBoutique::create(['libelle' => 'Petite boutique', 'description' => '9 m²']);
        $this->grande = TypeBoutique::create(['libelle' => 'Grande boutique', 'description' => '24 m²']);
        foreach ([[$this->petite, 12, 'P-'], [$this->grande, 3, 'G-']] as [$type, $nombre, $prefixe]) {
            foreach (range(1, $nombre) as $n) {
                $this->site->boutiques()->create(['numero' => $prefixe.$n, 'type_boutique_id' => $type->id, 'statut' => StatutBoutique::Disponible]);
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
            'nom' => 'Attribution 2027 — marché central',
            'description' => 'Boutiques du nouveau marché, attribuées par tirage au sort.',
            'date_ouverture' => now()->addDay()->format('Y-m-d\TH:i'),
            'date_cloture' => now()->addMonth()->format('Y-m-d\TH:i'),
            'delai_paiement_heures' => 72,
            'canaux_actifs' => ['ORANGE_MONEY', 'VIREMENT'],
            'max_boutiques_par_personne' => 2,
            'cautions' => [$this->site->id => [$this->petite->id => 150000, $this->grande->id => 500000]],
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

    public function test_le_regulateur_choisit_les_sites_et_fixe_une_caution_par_site_et_par_type(): void
    {
        $this->en($this->regulateur)->get(route('admin.boutiques.create'))->assertOk()
            ->assertSee('Marché central')->assertSee('12 disponibles')->assertSee('3 disponibles');

        $campagne = $this->campagneSoumise();

        $this->assertSame(StatutCampagneBoutiques::Soumise, $campagne->statut);
        $this->assertSame(2, $campagne->max_boutiques_par_personne);
        // Nombres comptés dans le référentiel, jamais saisis.
        $this->assertSame([['Petite boutique', $this->site->id, 12, 150000], ['Grande boutique', $this->site->id, 3, 500000]],
            $campagne->categories->map(fn ($c) => [$c->libelle, $c->site_id, $c->nombre_boutiques, $c->caution])->all());
        $this->assertStringContainsString("Petite boutique — Marché central : 12 boutique(s), caution 150\u{00A0}000\u{00A0}FCFA", $campagne->versions()->first()->parametres['categories']);

        $this->en($this->regulateur)->post(route('admin.boutiques.confirmer', $campagne))->assertForbidden();
        $this->en($this->validateur)->get(route('admin.boutiques.show', $campagne))->assertOk()->assertSee('Marché central')->assertSee("500\u{00A0}000\u{00A0}FCFA");
    }

    public function test_une_campagne_sans_lot_ou_sur_un_site_non_confirme_est_refusee(): void
    {
        $this->en($this->regulateur)->post(route('admin.boutiques.store'), $this->formulaire(['cautions' => [$this->site->id => [$this->petite->id => '', $this->grande->id => '']]]))
            ->assertSessionHasErrors(['cautions' => 'Ajoutez au moins une catégorie de boutiques.']);

        $this->site->forceFill(['statut' => StatutSite::Brouillon])->save();
        $this->en($this->regulateur)->post(route('admin.boutiques.store'), $this->formulaire())->assertSessionHasErrors('cautions');

        $this->assertSame(0, CampagneBoutiques::count());
    }

    public function test_rejet_confirmation_ouverture_et_cloture_par_le_validateur(): void
    {
        $campagne = $this->campagneSoumise();

        $this->en($this->validateur)->post(route('admin.boutiques.rejeter', $campagne), ['motif' => 'Caution trop élevée'])->assertSessionHas('succes');
        $this->assertSame(StatutCampagneBoutiques::Brouillon, $campagne->refresh()->statut);

        // Seules les petites boutiques sont finalement proposées.
        $this->en($this->regulateur)->put(route('admin.boutiques.update', $campagne), $this->formulaire([
            'cautions' => [$this->site->id => [$this->petite->id => 100000, $this->grande->id => '']],
        ]))->assertSessionHasNoErrors();
        $this->assertSame([100000], $campagne->categories()->pluck('caution')->all());
        $this->en($this->regulateur)->post(route('admin.boutiques.soumettre', $campagne));
        $this->en($this->validateur)->post(route('admin.boutiques.confirmer', $campagne))->assertSessionHas('succes');

        $code = $this->activerTotp($this->validateur);
        $this->en($this->validateur)->post(route('admin.boutiques.ouvrir', $campagne), ['confirmation' => 'CONFIRMER', 'code_totp' => '000000'])->assertSessionHas('erreur');
        $this->en($this->validateur)->post(route('admin.boutiques.ouvrir', $campagne), ['confirmation' => 'CONFIRMER', 'code_totp' => $code])->assertSessionHas('succes');
        $this->assertSame(StatutCampagneBoutiques::Ouverte, $campagne->refresh()->statut);

        // Les boutiques du lot sont engagées ; les grandes, non proposées, restent disponibles.
        $this->assertSame(12, Boutique::query()->where('type_boutique_id', $this->petite->id)->where('statut', StatutBoutique::EnCampagne)->count());
        $this->assertSame(3, Boutique::query()->where('type_boutique_id', $this->grande->id)->where('statut', StatutBoutique::Disponible)->count());

        $this->en($this->validateur)->post(route('admin.boutiques.cloturer', $campagne), ['confirmation' => 'CONFIRMER', 'code_totp' => $this->codeTotp($this->validateur, 1)])->assertSessionHas('succes');
        $this->assertSame(StatutCampagneBoutiques::Cloturee, $campagne->refresh()->statut);
        $this->assertDatabaseHas('audit_logs', ['action' => 'campagne_boutiques.ouverte', 'entite_id' => $campagne->id]);
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
}
