<?php

namespace App\Http\Controllers\Publique;

use App\Support\Armoiries;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Armoiries de la plateforme (fichier déposé dans les paramètres, sinon le fichier fourni).
 * Un SVG peut contenir du script : la politique de sécurité interdit toute exécution, même
 * si le fichier est ouvert directement.
 */
class ArmoiriesController
{
    public function __invoke(): BinaryFileResponse
    {
        return response()->file(Armoiries::chemin(), [
            'Content-Type' => Armoiries::type(),
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=60',
        ]);
    }
}
