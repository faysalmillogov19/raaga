<?php

namespace App\Services\Paiement;

use App\Enums\StatutCandidatureBoutique;
use App\Enums\StatutSouscription;
use App\Models\CandidatureBoutique;
use App\Models\Relance;
use App\Models\Souscription;
use App\Models\User;
use App\Notifications\RelancePaiement;
use App\Services\Audit\Journal;
use Illuminate\Validation\ValidationException;

/**
 * Relances des souscripteurs et des commerçants dont le paiement est attendu : automatiques (24 h avant la fin du
 * délai de paiement, puis à l'expiration) ou manuelles par le CONTRÔLEUR DES PAIEMENTS, depuis le dossier. Canaux :
 * notification et e-mail (si le compte en a un) ; le SMS viendra avec un fournisseur configuré. Chaque relance est
 * tracée (date, canal, auteur ou « automatique »).
 */
class Relances
{
    /** Relance d'expiration : seulement pour les délais échus depuis moins de 7 jours (jamais de vieux dossiers). */
    private const FENETRE_EXPIRATION_JOURS = 7;

    public function __construct(private readonly Journal $journal) {}

    public function relancer(Souscription|CandidatureBoutique $objet, string $type, ?User $auteur = null, ?string $message = null): Relance
    {
        if ($type === Relance::MANUELLE && ! $objet->attendPaiement()) {
            throw ValidationException::withMessages(['relance' => __('admin.paiements.erreurs.relance_impossible')]);
        }

        $destinataire = $objet->user;
        $canaux = $destinataire->email ? ['notification', 'email'] : ['notification'];
        $destinataire->notify(new RelancePaiement($objet, $type, $message));

        $relance = Relance::create([
            ...($objet instanceof Souscription ? ['souscription_id' => $objet->id] : ['candidature_boutique_id' => $objet->id]),
            'user_id' => $destinataire->id, 'type' => $type, 'canaux' => $canaux, 'auteur_id' => $auteur?->id,
            'message' => $message, 'created_at' => now(),
        ]);
        $this->journal->ecrire($auteur, 'paiement.relance', class_basename($objet), $objet->id, null,
            ['reference' => $objet->reference, 'type' => $type, 'canaux' => $canaux]);

        return $relance;
    }

    /**
     * Tâche planifiée : relance « échéance » des réservations qui expirent dans les 24 h, relance « expiration » de
     * celles qui viennent d'expirer ; une seule de chaque type par dossier.
     *
     * @return array{echeance: int, expiration: int}
     */
    public function automatiques(): array
    {
        $compteurs = ['echeance' => 0, 'expiration' => 0];
        foreach ([[Souscription::class, StatutSouscription::Reservee, StatutSouscription::Expiree, 'souscription_id'],
            [CandidatureBoutique::class, StatutCandidatureBoutique::Reservee, StatutCandidatureBoutique::Expiree, 'candidature_boutique_id']] as [$classe, $reservee, $expiree, $cle]) {
            $sans = fn (string $type) => fn ($q) => $q->whereNotIn('id', Relance::query()->where('type', $type)->whereNotNull($cle)->select($cle));

            $classe::query()->where('statut', $reservee)->whereBetween('expire_le', [now(), now()->addDay()])
                ->where($sans(Relance::ECHEANCE))->with('user')->orderBy('id')
                ->each(function ($objet) use (&$compteurs) {
                    $this->relancer($objet, Relance::ECHEANCE);
                    $compteurs['echeance']++;
                });

            $classe::query()->where('statut', $expiree)->where('expire_le', '>=', now()->subDays(self::FENETRE_EXPIRATION_JOURS))
                ->where($sans(Relance::EXPIRATION))->with('user')->orderBy('id')
                ->each(function ($objet) use (&$compteurs) {
                    $this->relancer($objet, Relance::EXPIRATION);
                    $compteurs['expiration']++;
                });
        }

        return $compteurs;
    }
}
