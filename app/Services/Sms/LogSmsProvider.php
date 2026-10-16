<?php

namespace App\Services\Sms;

use App\Contracts\SmsProvider;
use App\Support\Telephone;
use App\Support\VerrouEnvironnement;
use Illuminate\Support\Facades\Log;

/**
 * Aucun fournisseur SMS branché : aucun SMS n'est envoyé. Développement et recette : le message s'affiche dans la
 * console du serveur (php artisan serve) et dans le journal, avec un numéro masqué, et il est ajouté au journal de
 * recette lu par la page /recette/sms. Production : l'envoi est ignoré et tracé en avertissement (« SMS non envoyé »),
 * numéro masqué, sans jamais lever d'erreur.
 */
class LogSmsProvider implements SmsProvider
{
    /** Taille au-delà de laquelle le journal de recette ne garde que ses derniers messages. */
    private const TAILLE_MAX = 512 * 1024;

    private const GARDES = 200;

    public function envoyer(string $telephone, string $message, ?string $expediteur = null): void
    {
        $destinataire = ($expediteur ? "{$expediteur} → " : '').Telephone::masquer($telephone);
        if (app()->environment('production')) {
            Log::warning('[SMS non envoyé : aucun fournisseur SMS configuré] '.$destinataire.' : '.$message);

            return;
        }

        $ligne = '[SMS de développement] '.$destinataire.' : '.$message;

        error_log($ligne);
        Log::info($ligne);

        if (VerrouEnvironnement::journalSmsRecette()) {
            $this->journaliser($telephone, $message, $expediteur);
        }
    }

    /**
     * @return list<array{date: string, telephone: string, expediteur: ?string, message: string}> du plus récent au plus ancien
     */
    public static function recents(int $nombre = 50): array
    {
        $fichier = config('raaga.sms.fichier_recette');
        if (! is_file($fichier)) {
            return [];
        }

        $lignes = array_slice(file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -$nombre);

        return array_values(array_reverse(array_filter(array_map(fn (string $l) => json_decode($l, true), $lignes))));
    }

    private function journaliser(string $telephone, string $message, ?string $expediteur): void
    {
        $fichier = config('raaga.sms.fichier_recette');
        @mkdir(dirname($fichier), 0775, true);

        file_put_contents($fichier, json_encode([
            'date' => now()->toIso8601String(),
            'telephone' => Telephone::masquer($telephone),
            'expediteur' => $expediteur,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);

        // Journal borné : seuls les derniers messages sont conservés.
        if (filesize($fichier) > self::TAILLE_MAX) {
            $garder = array_slice(file($fichier) ?: [], -self::GARDES);
            file_put_contents($fichier, implode('', $garder), LOCK_EX);
        }
    }
}
