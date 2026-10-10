<?php

namespace App\Http\Controllers\Publique;

use App\Support\Armoiries;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Armoiries de la plateforme (fichier déposé dans les paramètres, sinon le fichier fourni).
 * Un SVG peut contenir du script : la politique de sécurité interdit toute exécution, même
 * si le fichier est ouvert directement.
 */
class ArmoiriesController
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        // Adresse versionnée (?v=…) : elle change avec le fichier, l'image peut donc rester longtemps en cache.
        $versionnee = $request->query('v') !== null && str_contains(Armoiries::url(), 'v='.$request->query('v'));

        return response()->file(Armoiries::chemin(), [
            'Content-Type' => Armoiries::type(),
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $versionnee ? 'public, max-age=31536000, immutable' : 'no-cache',
        ]);
    }
}
