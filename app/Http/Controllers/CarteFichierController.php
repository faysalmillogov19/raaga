<?php

namespace App\Http\Controllers;

use App\Enums\TypeCarte;
use App\Models\Carte;
use App\Services\Cartes\ReglagesCarteManquants;
use App\Services\Cartes\RenduCarte;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Téléchargement de sa carte par le titulaire : PDF (une page par face, 85,6 × 54 mm) ou image PNG d'une face (recto
 * par défaut, ?face=verso), générés depuis l'état courant de la carte (le nom du fichier porte sa version). Réservé au
 * titulaire, pour une carte courante et active. Si un réglage de la carte manque, le titulaire revient sur sa carte avec
 * « Votre carte est en cours de préparation » ; si le rendu échoue pour une autre raison (Chrome indisponible…), avec
 * « réessayez plus tard ou contactez l'assistance ». La cause est inscrite au journal de l'application.
 */
class CarteFichierController extends Controller
{
    public function __invoke(Request $request, Carte $carte, string $format, RenduCarte $rendu): Response|RedirectResponse
    {
        abort_unless($request->user()?->id === $carte->user_id, 403);
        abort_unless($carte->courante && $carte->estValide(), 404);
        $face = $request->query('face') === 'verso' ? 'verso' : 'recto';

        try {
            $contenu = $format === 'pdf' ? $rendu->pdf($carte) : $rendu->png($carte, $face);
        } catch (ReglagesCarteManquants $e) {
            Log::warning('Carte non générée : réglages manquants.', ['carte' => $carte->numero, 'manquants' => $e->manquants]);

            return $this->retour($carte, 'carte.preparation');
        } catch (Throwable $e) {
            Log::error('Carte non générée : échec du rendu.', ['carte' => $carte->numero, 'erreur' => $e->getMessage()]);

            return $this->retour($carte, 'carte.indisponible');
        }

        return response($contenu, 200, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$rendu->nomFichier($carte, $format, $format === 'png' ? $face : null).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Retour sur la carte du titulaire, avec le message. */
    private function retour(Carte $carte, string $message): RedirectResponse
    {
        return redirect()->back(fallback: $carte->type === TypeCarte::Personnel ? route('admin.carte') : route('espace.carte', ['type' => strtolower($carte->type->value)]))
            ->with('erreur', __($message));
    }
}
