<?php

namespace App\Http\Controllers\Publique;

use App\Support\ImagesPlateforme;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Logos de la plateforme (paramètres de la plateforme, sinon fichiers fournis). Comme pour les
 * armoiries, un SVG déposé ne peut exécuter aucun script, même ouvert directement.
 */
class ImagePlateformeController
{
    public function __invoke(Request $request, string $champ): BinaryFileResponse
    {
        abort_unless(ImagesPlateforme::existe($champ) && $champ !== 'armoiries' && ImagesPlateforme::disponible($champ), 404);

        $versionnee = $request->query('v') !== null && str_contains((string) ImagesPlateforme::url($champ), 'v='.$request->query('v'));

        return response()->file(ImagesPlateforme::chemin($champ), [
            'Content-Type' => ImagesPlateforme::type($champ),
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $versionnee ? 'public, max-age=31536000, immutable' : 'no-cache',
        ]);
    }
}
