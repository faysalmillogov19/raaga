<?php

namespace App\Http\Controllers;

use App\Models\Carte;
use App\Services\Cartes\RenduCarte;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Téléchargement de sa carte par le titulaire : PDF (format carte bancaire) ou image PNG, générés depuis l'état courant
 * de la carte (le nom du fichier porte sa version). Réservé au titulaire, pour une carte courante et active.
 */
class CarteFichierController extends Controller
{
    public function __invoke(Request $request, Carte $carte, string $format, RenduCarte $rendu): Response
    {
        abort_unless($request->user()?->id === $carte->user_id, 403);
        abort_unless($carte->courante && $carte->estValide(), 404);

        return response($format === 'pdf' ? $rendu->pdf($carte) : $rendu->png($carte), 200, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$rendu->nomFichier($carte, $format).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
