<?php

namespace Tests\Feature\Cartes;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutCarte;
use App\Enums\StatutKyc;
use App\Enums\StatutReservationBoutique;
use App\Enums\TypeCarte;
use App\Models\Boutique;
use App\Models\Carte;
use App\Models\Commune;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Services\Boutiques\ReservationsBoutiques;
use App\Services\Cartes\Cartes;
use App\Services\Cartes\RenduCarte;
use App\Services\Plateforme\ParametresPlateforme;
use App\Support\Plateforme\ReglagesCarte;
use Database\Seeders\CommunesSeeder;
use Database\Seeders\ParametresCarteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\Support\ReglagesCarteComplets;
use Tests\TestCase;

/**
 * Carte de commerçant selon la maquette du comité restreint du 03/11/2026 : numéro = IFU, sexe, date et lieu de
 * naissance, secteur, site(s), établie le, expire le ; au verso groupe sanguin (seulement avec consentement, sinon « Non
 * renseigné »), lieu de résidence, personne à prévenir, code-barres IFU et expiration MMAA ; QR code sans l'IFU ; aucun
 * bandeau tricolore ; validité de 3 ans par défaut, réglable ; organisme émetteur et code de contrôle à la place de
 * l'autorité, de la signature, du cachet et du numéro vert ; seeder des paramètres ; toute modification donne une
 * nouvelle version.
 */
class CarteCommercantMaquetteTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase, ReglagesCarteComplets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerActeurs();
        Notification::fake();
        $this->seed(CommunesSeeder::class);
        Boutiques::campagneOuverte();
    }

    private function commercant(array $attributs = []): User
    {
        return User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'nom' => 'OUATTARA', 'prenoms' => 'Aïcha',
            'ifu' => '12345678X', 'date_naissance' => '1988-03-12', 'sexe' => 'F', 'lieu_naissance' => 'Bobo-Dioulasso',
            'commune_id' => Commune::query()->where('nom', 'Ouagadougou')->value('id'), 'secteur_activite_id' => Boutiques::secteur('TISSUS'),
            'personne_prevenir_nom' => 'OUATTARA Moussa', 'personne_prevenir_telephone' => '+22670000000',
            'piece_expire_le' => now()->addYears(10)->toDateString(), ...$attributs]);
    }

    private function attribuer(User $user, Boutique $boutique): void
    {
        $boutique->forceFill(['statut' => StatutBoutique::Reservee])->save();
        $reservation = ReservationBoutique::create(['reference' => 'BTQ-'.strtoupper(Str::random(8)), 'user_id' => $user->id,
            'campagne_boutiques_id' => Boutiques::campagneOuverte()->id, 'boutique_id' => $boutique->id, 'caution' => 540_000, 'avance' => 135_000,
            'mois_avance' => 3, 'statut' => StatutReservationBoutique::Reservee, 'expire_le' => now(), 'avance_payee_le' => now(), 'reference_avance' => 'PAY-'.$boutique->id]);
        app(ReservationsBoutiques::class)->attribuer($reservation, now()->toDateString(), $this->validateur);
    }

    public function test_recto_et_verso_de_la_maquette_sans_bandeau_ni_ifu_dans_le_qr_code(): void
    {
        $this->completerReglagesCarte();
        $user = $this->commercant();
        $this->attribuer($user, Boutiques::boutique('A', 1));
        $carte = Cartes::courante($user, TypeCarte::Commercant);
        $this->assertSame(StatutCarte::Active, $carte->statut);

        $d = app(RenduCarte::class)->donnees($carte);
        $expire = now()->addYears(3)->subDay();
        $this->assertSame(['12345678X', 'OUATTARA Aïcha', 'F', '12/03/1988', 'Bobo-Dioulasso', 'Tissus et pagnes (Faso Dan Fani, wax)', 'Marché central',
            now()->format('d/m/Y'), $expire->format('d/m/Y'), null, 'Ouagadougou', 'OUATTARA Moussa · +226 70 00 00 00', '12345678X-'.$expire->format('my')],
            [$d['numero'], $d['titulaire'], $d['sexe'], $d['ne_le'], $d['lieu_naissance'], $d['secteur'], $d['sites'], $d['etablie'], $d['expire'],
                $d['groupe_sanguin'], $d['residence'], $d['personne_prevenir'], $d['code']]);

        $html = app(RenduCarte::class)->html($d, 'pdf');
        $this->assertStringContainsString('N° de carte (IFU)', $html);
        foreach (['Sexe', 'Né(e) le', 'Secteur d’activité', 'Établie le', 'Expire le', 'Groupe sanguin', 'Lieu de résidence', 'Personne à prévenir', 'N° 12345678X', 'N° IFU · Expiration'] as $texte) {
            $this->assertStringContainsString($texte, $html);
        }
        // Sans consentement : « Non renseigné » ; jamais de code de boutique ni de bandeau tricolore.
        $this->assertStringContainsString('data-champ="groupe_sanguin">Non renseigné', $html);
        $this->assertStringNotContainsString(Boutiques::boutique('A', 1)->code, $html);
        $this->assertStringNotContainsString('class="cr-bande"', $html);
        $this->assertStringNotContainsString('#CE1126; }', $html);
        // QR code : l'adresse de vérification par jeton seulement.
        $this->assertStringNotContainsString('12345678X', $carte->urlVerification());
        $this->assertStringEndsWith($carte->jeton, $carte->urlVerification());
    }

    public function test_groupe_sanguin_seulement_avec_consentement_et_chiffre_en_base(): void
    {
        $user = $this->commercant(['groupe_sanguin' => 'O+']);
        $this->attribuer($user, Boutiques::boutique('A', 1));
        $carte = Cartes::courante($user, TypeCarte::Commercant);
        $this->assertNull($carte->contenu['groupe_sanguin']);

        $user->forceFill(['groupe_sanguin_consenti_le' => now()])->save();
        $mise = app(Cartes::class)->synchroniser($user->fresh(), TypeCarte::Commercant);
        $this->assertSame(['O+', $carte->version + 1], [$mise->contenu['groupe_sanguin'], $mise->version]);
        // Chiffré en base, jamais dans les attributs exposés.
        $this->assertNotSame('O+', DB::table('users')->where('id', $user->id)->value('groupe_sanguin'));
        $this->assertArrayNotHasKey('groupe_sanguin', $user->fresh()->toArray());
    }

    public function test_les_deux_sites_et_une_nouvelle_version_a_chaque_modification(): void
    {
        $this->completerReglagesCarte();
        $user = $this->commercant();
        $this->attribuer($user, Boutiques::boutique('A', 1));
        $carte = Cartes::courante($user, TypeCarte::Commercant);
        Carte::query()->whereKey($carte->id)->update(['contenu' => json_encode([...$carte->contenu, 'emplacements' => [
            ['code' => 'MCE-RC-A-001', 'site' => 'Marché central'], ['code' => 'SAR-RC-B-002', 'site' => 'Marché de Sarfalao']]])]);
        $this->assertSame('Marché central · Marché de Sarfalao', app(RenduCarte::class)->donnees($carte->fresh())['sites']);

        // Personne à prévenir modifiée : nouveau contenu, nouvelle version (fichiers régénérés).
        $carte = app(Cartes::class)->synchroniser($user, TypeCarte::Commercant);
        $version = $carte->version;
        $user->forceFill(['personne_prevenir_nom' => 'OUATTARA Salif'])->save();
        $mise = app(Cartes::class)->synchroniser($user->fresh(), TypeCarte::Commercant);
        $this->assertSame([$version + 1, 'OUATTARA Salif'], [$mise->version, $mise->contenu['personne_prevenir']['nom']]);
    }

    public function test_duree_de_validite_reglable_et_organisme_emetteur_sans_autorite(): void
    {
        $this->completerReglagesCarte();
        $this->assertTrue(ReglagesCarte::prete());
        $parametres = app(ParametresPlateforme::class);
        $parametres->enregistrer('carte', [...$parametres->section('carte'), 'dureeValiditeAnnees' => 5], null);
        $user = $this->commercant();
        $this->attribuer($user, Boutiques::boutique('A', 1));
        $carte = Cartes::courante($user, TypeCarte::Commercant);
        $this->assertSame(now()->addYears(5)->subDay()->toDateString(), $carte->valide_jusqu_au->toDateString());

        $d = app(RenduCarte::class)->donnees($carte);
        $this->assertSame(['Raaga SA', $carte->code_controle], [$d['emetteur'], $d['controle']]);
        $html = app(RenduCarte::class)->html($d, 'pdf');
        foreach (['Carte émise par', 'Carte émise électroniquement, valable sans signature.', 'Code de contrôle', $carte->code_controle] as $texte) {
            $this->assertStringContainsString($texte, $html);
        }
        foreach (['Numéro vert', 'autorité de délivrance', 'Cachet'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
    }

    public function test_seeder_des_parametres_de_la_carte_sans_ecraser_les_reglages(): void
    {
        Storage::fake('local');
        $this->seed(ParametresCarteSeeder::class);
        $this->assertSame(3, (int) plateforme('carte.dureeValiditeAnnees'));
        foreach (array_keys(ParametresCarteSeeder::FILIGRANES) as $champ) {
            $this->assertNotNull(ReglagesCarte::chemin(plateforme('carte.'.$champ)), $champ);
        }
        $this->assertNull(plateforme('carte.filigraneSecondaireRecto'));

        // Réglages du SUPER_ADMIN gardés ; seeder relancé : rien ne change.
        $parametres = app(ParametresPlateforme::class);
        $parametres->enregistrer('carte', [...$parametres->section('carte'), 'dureeValiditeAnnees' => 4], null);
        $avant = $parametres->section('carte');
        $this->seed(ParametresCarteSeeder::class);
        $this->assertSame($avant, app(ParametresPlateforme::class)->section('carte'));
    }
}
