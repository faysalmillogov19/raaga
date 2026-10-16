<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/** Plafond de requêtes : badges et file « À valider » calculés une seule fois par page, quel que soit le nombre d'éléments. */
class RequetesAValiderTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    /** Une requête par table consultée (file « À valider », « À traiter », notifications), jamais une par élément. */
    // Rubriques « Paiements » (corrections, remboursements) et « Identités » : trois requêtes de plus pour le VALIDATEUR.
    // Une requête par rubrique de la file (la rubrique « Avances en retard » en ajoute une), jamais une par élément.
    private const PLAFONDS = ['a_valider' => 29, 'tableau' => 38];

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
        $gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->en($gestionnaire)->post(route('admin.sites.types.store'), ['code' => 'BOUT', 'libelle' => 'Petite boutique', 'attribuable' => 1]);
        $this->en($this->validateur)->post(route('admin.sites.types.confirmer', TypeBoutique::query()->sole()))->assertSessionHas('succes');
        // File variée : 4 sites soumis (auteurs différents), un type, une unité, une démarche.
        foreach (['SAR', 'A04', 'RAA', 'KOU'] as $i => $code) {
            $auteur = $i % 2 ? $gestionnaire : User::factory()->role(Role::GestionnaireSites)->create();
            $this->en($auteur)->post(route('admin.sites.store'), ['code' => $code, 'nom' => 'Marché '.$code, 'region' => 'Centre', 'ville' => 'Ouagadougou']);
            $site = Site::query()->where('code', $code)->sole();
            $this->en($auteur)->post(route('admin.referencement.boutiques.store', $site), [
                'niveau' => 'R1', 'bloc' => 'A', 'numero' => 1, 'surface' => 9, 'type_boutique_id' => TypeBoutique::query()->where('code', 'BOUT')->value('id'), 'etat_physique' => 'LIVREE',
            ]);
            $this->en($auteur)->post(route('admin.sites.soumettre', $site))->assertSessionHas('succes');
        }
        $this->en($gestionnaire)->post(route('admin.sites.types.store'), ['code' => 'KIO', 'libelle' => 'Kiosque', 'attribuable' => 0]);
        $this->en($this->regulateur)->post(route('admin.referencement.zones.store', 'unites'), ['code' => 'FORFAIT', 'libelle' => 'Forfait mensuel']);
        $this->en($this->regulateur)->post(route('admin.phase.proposer'), ['presentation_actions' => 1, 'souscription_actions' => 0, 'motif' => 'Ouverture prochaine']);
    }

    private function requetes(string $url): int
    {
        $this->en($this->validateur);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    /** @return array<string, int> */
    private function mesures(): array
    {
        return ['a_valider' => $this->requetes(route('admin.a-valider')), 'rubrique' => $this->requetes(route('admin.a-valider.rubrique', 'sites')),
            'tableau' => $this->requetes(route('admin.tableau'))];
    }

    public function test_plafond_de_requetes_pour_le_validateur(): void
    {
        $avant = $this->mesures();
        $this->assertLessThanOrEqual(self::PLAFONDS['a_valider'], $avant['a_valider']);
        $this->assertLessThanOrEqual(self::PLAFONDS['a_valider'], $avant['rubrique']);
        $this->assertLessThanOrEqual(self::PLAFONDS['tableau'], $avant['tableau']);

        // Trois sites soumis de plus, par d'autres auteurs : pas une requête de plus.
        foreach (['ZAB', 'DAP', 'KOM'] as $code) {
            $auteur = User::factory()->role(Role::GestionnaireSites)->create();
            $this->en($auteur)->post(route('admin.sites.store'), ['code' => $code, 'nom' => 'Marché '.$code, 'region' => 'Centre', 'ville' => 'Ouagadougou']);
            $site = Site::query()->where('code', $code)->sole();
            $this->en($auteur)->post(route('admin.referencement.boutiques.store', $site), [
                'niveau' => 'R1', 'bloc' => 'A', 'numero' => 1, 'surface' => 9, 'type_boutique_id' => TypeBoutique::query()->where('code', 'BOUT')->value('id'), 'etat_physique' => 'LIVREE',
            ]);
            $this->en($auteur)->post(route('admin.sites.soumettre', $site))->assertSessionHas('succes');
        }
        $this->assertSame($avant, $this->mesures());
    }
}
