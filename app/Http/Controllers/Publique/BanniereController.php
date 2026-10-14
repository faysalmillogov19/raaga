<?php

namespace App\Http\Controllers\Publique;

use App\Support\BanniereAccueil;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Image de la bannière d'accueil (WebP), à la largeur demandée : 640, 1280 ou 1920 px. */
class BanniereController
{
    public function __invoke(Request $request, int $largeur): BinaryFileResponse
    {
        $chemin = BanniereAccueil::chemin($largeur);
        abort_unless($chemin && is_file($chemin), 404);
        $versionnee = $request->query('v') !== null && str_contains(BanniereAccueil::url($largeur), 'v='.$request->query('v'));

        return response()->file($chemin, [
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $versionnee ? 'public, max-age=31536000, immutable' : 'no-cache',
        ]);
    }
}
