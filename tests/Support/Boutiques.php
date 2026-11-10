<?php

namespace Tests\Support;

use App\Enums\DecisionParametrage;
use App\Enums\StatutBoutique;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutGrilleTarifaire;
use App\Enums\StatutKyc;
use App\Enums\StatutSite;
use App\Enums\StatutTypeBoutique;
use App\Enums\TypeDocumentKyc;
use App\Models\Boutique;
use App\Models\CampagneBoutiques;
use App\Models\GrilleTarifaire;
use App\Models\GrilleTarifaireVersion;
use App\Models\KycDocument;
use App\Models\ParametrageDecision;
use App\Models\ReservationBoutique;
use App\Models\SecteurActivite;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use App\Services\Boutiques\ReservationsBoutiques;
use App\Services\Kyc\VerificationIdentite;
use App\Support\Nip;
use Database\Seeders\SecteursActiviteSeeder;
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
            'ville' => 'Ouagadougou', 'adresse' => 'Avenue de la Nation', 'version' => 1, 'cree_par' => $auteur->id]);
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
            'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addMonth(),
            'canaux_actifs' => ['ORANGE_MONEY', 'VIREMENT'], 'sites' => [$site->id], 'types' => [$petite->id, $grande->id], 'limites' => [$petite->id => 1, $grande->id => 1],
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

    /**
     * Demande de vérification d'identité faite, condition de toute préréservation (10/11/2026) : sans elle, dossier complet
     * déposé (pièce du type et du numéro donnés, CNIB pour une personne morale), identité en attente de vérification.
     */
    public static function demandeIdentite(User $user, string $numeroPiece = 'B1234567', string $type = 'CNIB'): void
    {
        if (app(VerificationIdentite::class)->demandeFaite($user->fresh())) {
            return;
        }
        $piece = TypeDocumentKyc::tryFrom($type);
        $piece = in_array($piece, TypeDocumentKyc::piecesIdentite(), true) ? $piece : TypeDocumentKyc::Cnib;
        $numero = Nip::porteNip($piece) && ! Nip::valide($numeroPiece) ? self::nip($numeroPiece) : $numeroPiece;
        DossierKyc::complet($user, ReservationBoutique::normaliserPiece($numero), null, $piece);
        if ($user->statut_kyc !== StatutKyc::Valide) {
            $user->forceFill(['statut_kyc' => StatutKyc::EnAttente])->saveQuietly();
        }
    }

    /**
     * NIP de test (17 chiffres) tiré d'un ancien numéro de pièce (06/11/2026) : lettres et chiffres gardés (majuscules,
     * sans espaces ni tirets), chaque lettre remplacée par deux chiffres. Deux écritures du même numéro donnent le même NIP.
     */
    public static function nip(string $numero): string
    {
        $chiffres = preg_replace_callback('/[A-Z]/', fn (array $m) => (string) (ord($m[0]) - 55), ReservationBoutique::normaliserPiece($numero));

        return str_pad(substr($chiffres, -Nip::LONGUEUR), Nip::LONGUEUR, '0', STR_PAD_LEFT);
    }

    /**
     * Réservation par le formulaire public (pièce jointe factice, engagements cochés). CNIB ou CIB-AES : numéro converti en
     * NIP (Boutiques::nip) s'il n'en est pas déjà un.
     */
    public static function reserver(TestCase $test, User $user, Boutique $boutique, string $numeroPiece = 'B1234567', string $type = 'CNIB'): TestResponse
    {
        Storage::fake('local');
        self::ifuVerifie($user);
        if (Nip::porteNip(TypeDocumentKyc::tryFrom($type)) && ! Nip::valide($numeroPiece)) {
            $numeroPiece = self::nip($numeroPiece);
        }
        self::demandeIdentite($user, $numeroPiece, $type);

        return $test->actingAs($user)->post(route('boutiques.reserver', $boutique->code), [
            'type_piece' => $type, 'numero_piece' => $numeroPiece, 'piece' => UploadedFile::fake()->image('piece.jpg'),
            ...self::donneesCommercant(),
        ]);
    }

    /**
     * Champs de la candidature autres que la pièce : engagements, activité, sexe et lieu de naissance, personne à
     * prévenir (sans groupe sanguin).
     *
     * @return array<string, mixed>
     */
    public static function donneesCommercant(): array
    {
        return [
            'engagements' => array_map(fn ($d) => $d->value, ReservationsBoutiques::ENGAGEMENTS), 'secteur_activite_id' => self::secteur(),
            'sexe' => 'F', 'lieu_naissance' => 'Ouagadougou', 'personne_prevenir_nom' => 'OUEDRAOGO Awa', 'personne_prevenir_telephone' => '70 00 00 01',
        ];
    }

    /**
     * IFU déjà donné et attestation déjà validée : la candidature ne les redemande pas (parcours de l'IFU testé à part,
     * dans IfuCommercantTest).
     */
    public static function ifuVerifie(User $user): void
    {
        if ($user->estPersonneMorale()) {
            return;
        }
        if (blank($user->ifu)) {
            $user->forceFill(['ifu' => sprintf('%08d', $user->id).'K'])->save();
        }
        if (! KycDocument::query()->where('user_id', $user->id)->where('type', TypeDocumentKyc::Ifu)->exists()) {
            KycDocument::create(['user_id' => $user->id, 'type' => TypeDocumentKyc::Ifu, 'chemin_fichier' => 'kyc/'.$user->id.'/ifu.pdf', 'nom_original' => 'ifu.pdf',
                'mime' => 'application/pdf', 'taille' => 1000, 'empreinte_sha256' => str_repeat('0', 64), 'statut' => StatutDocumentKyc::Valide,
                'televerse_par' => $user->id, 'numero' => $user->ifu]);
        }
    }

    /** Activité principale envoyée avec une candidature (référentiel des secteurs créé au besoin). */
    public static function secteur(string $code = 'EPICERIE'): int
    {
        if (! SecteurActivite::query()->exists()) {
            app(SecteursActiviteSeeder::class)->setContainer(app())->__invoke();
        }

        return (int) SecteurActivite::query()->where('code', $code)->value('id');
    }
}
