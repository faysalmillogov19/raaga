<?php

namespace App\Jobs;

use App\Enums\StatutCarte;
use App\Models\Carte;
use App\Services\Cartes\FichiersCarte;
use App\Services\Cartes\ReglagesCarteManquants;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Génération en tâche de fond des fichiers d'une version de la carte (PNG recto, PNG verso, PDF) par Chrome. Lancé à
 * l'émission, à chaque nouvelle version, et par le téléchargement ou l'impression au guichet si les fichiers manquent.
 * Un seul job par carte et par version. Trois essais, puis la cause est inscrite au journal avec le numéro de la carte
 * et sur la carte (« n'a pas pu être préparée » pour le titulaire, alerte et relance au back-office).
 * Réglage de la carte manquant : pas de nouvel essai (rien ne changerait), avertissement au journal ; le prochain
 * téléchargement relance la génération.
 */
class GenererFichiersCarte implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Attente avant le 2e et le 3e essai (secondes). */
    public array $backoff = [30, 120];

    public int $timeout = 180;

    /** Durée maximale du verrou d'unicité (secondes), si le worker s'arrête en cours de route. */
    public int $uniqueFor = 900;

    public function __construct(public int $carteId, public int $version) {}

    public function uniqueId(): string
    {
        return $this->carteId.'-v'.$this->version;
    }

    public function handle(FichiersCarte $fichiers): void
    {
        $carte = Carte::query()->find($this->carteId);
        // Carte remplacée, révoquée, suspendue ou passée à une autre version (un autre job s'en charge) : rien à faire.
        if (! $carte || $carte->version !== $this->version || ! $carte->courante || $carte->statut !== StatutCarte::Active || $fichiers->prets($carte)) {
            return;
        }

        try {
            $fichiers->generer($carte);
        } catch (ReglagesCarteManquants $e) {
            Log::warning('Fichiers de la carte non générés : réglages manquants.', ['carte' => $carte->numero, 'version' => $this->version, 'manquants' => $e->manquants]);
        }
    }

    public function failed(Throwable $e): void
    {
        if ($carte = Carte::query()->find($this->carteId)) {
            app(FichiersCarte::class)->marquerEchec($carte, $this->version, $e);
        }
        Log::error('Fichiers de la carte non générés après '.$this->tries.' essais.', [
            'carte' => Carte::query()->whereKey($this->carteId)->value('numero') ?? $this->carteId,
            'version' => $this->version,
            'erreur' => $e->getMessage(),
        ]);
    }
}
