<?php

namespace App\Support;

use App\Services\Plateforme\ParametresPlateforme;
use Illuminate\Support\Facades\Storage;

/**
 * Fichier des armoiries de la plateforme : celui déposé dans les paramètres, sinon le fichier fourni.
 */
final class Armoiries
{
    public static function chemin(): string
    {
        $depose = app(ParametresPlateforme::class)->get('identite.armoiries');
        $disque = Storage::disk('local');

        return $depose && $disque->exists($depose) ? $disque->path($depose) : public_path('armoiries.svg');
    }

    public static function type(): string
    {
        return str_ends_with(self::chemin(), '.png') ? 'image/png' : 'image/svg+xml';
    }

    /** Pour les PDF : image intégrée au document. */
    public static function dataUri(): string
    {
        return 'data:'.self::type().';base64,'.base64_encode((string) file_get_contents(self::chemin()));
    }
}
