<?php

namespace App\Http\Controllers\Publique;

use App\Support\Diapositives;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Image d'une diapositive du carrousel d'accueil (WebP), à la largeur demandée : 640, 1280 ou 1920 px. */
class DiapositiveController
{
    public function __invoke(Request $request, string $image, int $largeur): BinaryFileResponse
    {
        $base = 'plateforme/diapositive-'.$image;
        $chemin = Diapositives::chemin($base, $largeur);
        abort_unless($chemin !== null, 404);
        $versionnee = $request->query('v') !== null && str_contains(Diapositives::url($base, $largeur), 'v='.$request->query('v'));

        return response()->file($chemin, [
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $versionnee ? 'public, max-age=31536000, immutable' : 'no-cache',
        ]);
    }
}
