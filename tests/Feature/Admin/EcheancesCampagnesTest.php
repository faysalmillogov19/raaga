<?php

namespace Tests\Feature\Admin;

use App\Enums\StatutCampagneBoutiques;
use App\Models\CampagneBoutiques;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\TestCase;

/** Ouverture et clôture automatiques des campagnes de boutiques à leurs dates, sans bouton « Ouvrir ». */
class EcheancesCampagnesTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private function confirmee(array $attributs = []): CampagneBoutiques
    {
        return Boutiques::campagneOuverte(['statut' => StatutCampagneBoutiques::Confirmee, 'date_ouverture' => now()->addHour(), 'date_cloture' => now()->addMonth(), ...$attributs]);
    }

    public function test_ouverture_a_la_date_puis_cloture_a_la_date(): void
    {
        $campagne = $this->confirmee();

        $this->artisan('raaga:campagnes-boutiques')->assertSuccessful();
        $this->assertSame(StatutCampagneBoutiques::Confirmee, $campagne->refresh()->statut);

        $this->travel(61)->minutes();
        $this->artisan('raaga:campagnes-boutiques')->assertSuccessful()->expectsOutputToContain('1 campagne(s) ouverte(s), 0 campagne(s) clôturée(s).');
        $this->assertSame(StatutCampagneBoutiques::Ouverte, $campagne->refresh()->statut);
        $this->assertNotNull($campagne->ouverte_le);
        $this->assertDatabaseHas('audit_logs', ['action' => 'campagne_boutiques.ouverte', 'entite_id' => $campagne->id, 'acteur_id' => null]);

        $this->travel(1)->months();
        $this->artisan('raaga:campagnes-boutiques')->assertSuccessful()->expectsOutputToContain('0 campagne(s) ouverte(s), 1 campagne(s) clôturée(s).');
        $this->assertSame(StatutCampagneBoutiques::Cloturee, $campagne->refresh()->statut);
        $this->assertDatabaseHas('audit_logs', ['action' => 'campagne_boutiques.cloturee', 'entite_id' => $campagne->id, 'acteur_id' => null]);
    }

    public function test_la_page_publique_ouvre_la_campagne_sans_attendre_le_planificateur(): void
    {
        $campagne = $this->confirmee(['date_ouverture' => now()->subMinute()]);

        $this->get(route('boutiques'))->assertOk();
        $this->assertSame(StatutCampagneBoutiques::Ouverte, $campagne->refresh()->statut);
        $this->assertTrue($campagne->accepteReservations());
    }

    public function test_confirmee_apres_sa_date_d_ouverture_elle_s_ouvre_aussitot(): void
    {
        $this->creerActeurs();
        $campagne = $this->confirmee(['statut' => StatutCampagneBoutiques::Soumise, 'date_ouverture' => now()->subHour()]);
        $campagne->creerVersion($this->regulateur);

        $this->en($this->validateur)->post(route('admin.boutiques.confirmer', $campagne))->assertSessionHas('succes');
        $this->assertSame(StatutCampagneBoutiques::Ouverte, $campagne->refresh()->statut);
    }

    public function test_une_seule_campagne_ouverte_a_la_fois(): void
    {
        $premiere = Boutiques::campagneOuverte(['date_cloture' => now()->addDay()]);
        $seconde = $this->confirmee(['nom' => 'Réservation 2028', 'date_ouverture' => now()->subMinute()]);

        $this->artisan('raaga:campagnes-boutiques')->assertSuccessful();
        $this->assertSame(StatutCampagneBoutiques::Confirmee, $seconde->refresh()->statut);

        // La première se clôture à sa date : la seconde s'ouvre dans la même passe.
        $this->travel(2)->days();
        $this->artisan('raaga:campagnes-boutiques')->assertSuccessful()->expectsOutputToContain('1 campagne(s) ouverte(s), 1 campagne(s) clôturée(s).');
        $this->assertSame(StatutCampagneBoutiques::Cloturee, $premiere->refresh()->statut);
        $this->assertSame(StatutCampagneBoutiques::Ouverte, $seconde->refresh()->statut);
    }

    public function test_periode_passee_sans_ouverture_la_campagne_est_close(): void
    {
        $campagne = $this->confirmee(['date_ouverture' => now()->subMonth(), 'date_cloture' => now()->subDay()]);

        $this->artisan('raaga:campagnes-boutiques')->assertSuccessful();
        $this->assertSame(StatutCampagneBoutiques::Cloturee, $campagne->refresh()->statut);
    }

    public function test_la_commande_est_planifiee_chaque_minute(): void
    {
        $this->artisan('schedule:list')->assertSuccessful()->expectsOutputToContain('raaga:campagnes-boutiques');
    }
}
