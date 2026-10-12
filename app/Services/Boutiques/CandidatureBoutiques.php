<?php

namespace App\Services\Boutiques;

use App\Enums\DocumentAccepte;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutCandidatureBoutique;
use App\Models\Acceptation;
use App\Models\CampagneBoutiques;
use App\Models\CandidatureBoutique;
use App\Models\User;
use App\Services\Audit\Journal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Candidature d'un citoyen aux boutiques d'une campagne ouverte : une candidature active par personne,
 * identité vérifiée, quatre engagements acceptés et horodatés, caution figée (statut RÉSERVÉE).
 * Indépendante des actions : aucune donnée de souscription n'intervient.
 */
class CandidatureBoutiques
{
    public const ENGAGEMENTS = [
        DocumentAccepte::TirageSansGarantie,
        DocumentAccepte::CautionSansLienActions,
        DocumentAccepte::ReglementCampagneBoutiques,
        DocumentAccepte::OrigineLiciteFonds,
    ];

    public function __construct(private readonly Journal $journal) {}

    public static function active(CampagneBoutiques $campagne, User $user): ?CandidatureBoutique
    {
        return CandidatureBoutique::query()->where('campagne_boutiques_id', $campagne->id)->where('user_id', $user->id)
            ->whereIn('statut', StatutCandidatureBoutique::actifs())->latest('id')->first();
    }

    /** Candidatures ouvertes : campagne au statut OUVERTE et dans sa période. */
    public static function accepteCandidatures(CampagneBoutiques $campagne): bool
    {
        return $campagne->statut === StatutCampagneBoutiques::Ouverte
            && now()->between($campagne->date_ouverture, $campagne->date_cloture);
    }

    /**
     * @param  list<string>  $engagements  valeurs DocumentAccepte cochées
     *
     * @throws ValidationException
     */
    public function candidater(CampagneBoutiques $campagne, User $user, int $categorieId, array $engagements, string $ip, string $userAgent): CandidatureBoutique
    {
        return DB::transaction(function () use ($campagne, $user, $categorieId, $engagements, $ip, $userAgent) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $erreur = fn (string $champ, string $cle) => throw ValidationException::withMessages([$champ => __('public.boutiques.erreurs.'.$cle)]);

            if (! self::accepteCandidatures($campagne)) {
                $erreur('categorie', 'fermee');
            }
            if (! $user->kycValide()) {
                $erreur('identite', 'kyc');
            }
            if (self::active($campagne, $user)) {
                $erreur('categorie', 'deja');
            }
            $categorie = $campagne->categories()->whereKey($categorieId)->first() ?? $erreur('categorie', 'categorie');
            if (array_diff(array_map(fn (DocumentAccepte $d) => $d->value, self::ENGAGEMENTS), $engagements)) {
                $erreur('engagements', 'engagements');
            }

            $candidature = CandidatureBoutique::create([
                'reference' => $this->reference(),
                'user_id' => $user->id,
                'campagne_boutiques_id' => $campagne->id,
                'categorie_boutique_id' => $categorie->id,
                'caution' => $categorie->caution,
                'statut' => StatutCandidatureBoutique::Reservee,
                'expire_le' => now()->addHours($campagne->delai_paiement_heures),
            ]);

            foreach (self::ENGAGEMENTS as $document) {
                Acceptation::create([
                    'user_id' => $user->id,
                    'candidature_boutique_id' => $candidature->id,
                    'document' => $document,
                    'version_document' => $document === DocumentAccepte::ReglementCampagneBoutiques ? (string) $campagne->version : config('raaga.inscription.version_conditions'),
                    'accepte_le' => now(),
                    'ip' => $ip,
                    'user_agent' => substr($userAgent, 0, 500),
                ]);
            }

            $this->journal->ecrire($user, 'candidature_boutique.reservee', 'CandidatureBoutique', $candidature->id, null, [
                'reference' => $candidature->reference, 'campagne' => $campagne->id, 'categorie' => $categorie->id, 'caution' => $categorie->caution,
            ]);

            return $candidature;
        });
    }

    /** « BTQ-7K3M9Q2X » : sans 0/O ni 1/I/L, faciles à confondre au téléphone. */
    private function reference(): string
    {
        do {
            $reference = 'BTQ-'.strtr(Str::upper(Str::random(8)), ['0' => '7', 'O' => 'P', 'I' => 'J', 'L' => 'M', '1' => '3']);
        } while (CandidatureBoutique::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
