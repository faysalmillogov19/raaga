<?php

namespace Tests\Support;

use App\Enums\DecisionParametrage;
use App\Enums\StatutBoutique;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutGrilleTarifaire;
use App\Enums\StatutSite;
use App\Enums\StatutTypeBoutique;
use App\Models\Boutique;
use App\Models\CampagneBoutiques;
use App\Models\GrilleTarifaire;
use App\Models\GrilleTarifaireVersion;
use App\Models\ParametrageDecision;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use App\Services\Boutiques\ReservationsBoutiques;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Campagne de réservation prête pour les tests : site confirmé « MCE », boutiques réservables (niveau RC, blocs A et
 * B), grille tarifaire confirmée (loyer 5 000 FCFA/m², caution 12 mois, avance 3 mois), campagne ouverte.
 */
final class Boutiques
{
    /** Loyer au m² de la grille : petite boutique de 9 m² → 45 000 FCFA/mois, caution 540 000, avance 135 000. */
    public const LOYER_M2 = 5_000;

    public static function campagneOuverte(array $attributs = [], int $petites = 4): CampagneBoutiques
    {
        $auteur = User::factory()->create();
        $site = Site::query()->where('code', 'MCE')->first() ?? Site::create(['statut' => StatutSite::Confirme, 'code' => 'MCE', 'nom' => 'Marché central',
            'region' => 'Centre', 'commune' => 'Ouagadougou', 'adresse' => 'Avenue de la Nation', 'version' => 1, 'cree_par' => $auteur->id]);
        $petite = TypeBoutique::query()->firstOrCreate(['libelle' => 'Petite boutique'], ['description' => '9 m²', 'statut' => StatutTypeBoutique::Valide, 'actif' => true, 'attribuable' => true]);
        $grande = TypeBoutique::query()->firstOrCreate(['libelle' => 'Grande boutique'], ['description' => '24 m²', 'statut' => StatutTypeBoutique::Valide, 'actif' => true, 'attribuable' => true]);

        if (! $site->boutiques()->exists()) {
            foreach (range(1, $petites) as $n) {
                $site->boutiques()->create(['numero' => $n, 'niveau' => 'RC', 'bloc' => 'A', 'surface' => 9, 'type_boutique_id' => $petite->id, 'statut' => StatutBoutique::Disponible]);
            }
            $site->boutiques()->create(['numero' => 1, 'niveau' => 'RC', 'bloc' => 'B', 'surface' => 24, 'type_boutique_id' => $grande->id, 'statut' => StatutBoutique::Disponible]);
        }
        self::grilleConfirmee($site, [$petite->id, $grande->id], $auteur);

        return CampagneBoutiques::create([
            'statut' => StatutCampagneBoutiques::Ouverte, 'nom' => 'Réservation 2027', 'description' => 'Marché central.',
            'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addMonth(), 'delai_paiement_heures' => 72,
            'canaux_actifs' => ['ORANGE_MONEY', 'VIREMENT'], 'max_boutiques_par_personne' => 1, 'sites' => [$site->id],
            'version' => 2, 'cree_par' => $auteur->id, ...$attributs,
        ]);
    }

    /** Grille confirmée (version figée et décision de confirmation), sans passer par les écrans. */
    public static function grilleConfirmee(Site $site, array $typeIds, User $auteur): GrilleTarifaire
    {
        $grille = GrilleTarifaire::query()->firstOrCreate(['site_id' => $site->id], ['statut' => StatutGrilleTarifaire::Confirme, 'version' => 0, 'cree_par' => $auteur->id]);
        if ($grille->confirmee() !== null) {
            return $grille;
        }
        $lignes = [];
        foreach ($typeIds as $typeId) {
            $lignes[GrilleTarifaire::cle('RC', $typeId)] = ['unite_loyer' => 'M2', 'loyer_m2' => self::LOYER_M2, 'mois_caution' => 12, 'mois_avance' => 3];
        }
        $numero = (int) $grille->versions()->max('numero') + 1;
        GrilleTarifaireVersion::create(['grille_tarifaire_id' => $grille->id, 'numero' => $numero, 'parametres' => ['grille' => '', 'lignes' => $lignes],
            'auteur_id' => $auteur->id, 'created_at' => now()]);
        ParametrageDecision::create(['objet_type' => $grille->typeParametrage(), 'objet_id' => $grille->id, 'version' => $numero,
            'decision' => DecisionParametrage::Confirme, 'decideur_id' => User::factory()->create()->id, 'created_at' => now()]);

        return $grille;
    }

    /** Première boutique réservable d'un bloc du site de la campagne. */
    public static function boutique(string $bloc = 'A', int $numero = 1): Boutique
    {
        return Boutique::query()->where('bloc', $bloc)->where('numero', $numero)->firstOrFail();
    }

    /** Réservation par le formulaire public (pièce jointe factice, engagements cochés). */
    public static function reserver(TestCase $test, User $user, Boutique $boutique, string $numeroPiece = 'B1234567', string $type = 'CNIB'): TestResponse
    {
        Storage::fake('local');

        return $test->actingAs($user)->post(route('boutiques.reserver', $boutique->code), [
            'type_piece' => $type, 'numero_piece' => $numeroPiece, 'piece' => UploadedFile::fake()->image('piece.jpg'),
            'engagements' => array_map(fn ($d) => $d->value, ReservationsBoutiques::ENGAGEMENTS),
        ]);
    }
}
