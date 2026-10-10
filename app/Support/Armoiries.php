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

    /**
     * Adresse de l'image, avec une version tirée du fichier : quand les armoiries changent,
     * l'adresse change aussi et aucun navigateur ne garde l'ancienne image en cache.
     */
    public static function url(): string
    {
        $chemin = self::chemin();

        return route('armoiries', ['v' => substr(sha1($chemin.'|'.@filemtime($chemin)), 0, 10)]);
    }

    /** Pour les PDF : image intégrée au document. */
    public static function dataUri(): string
    {
        return 'data:'.self::type().';base64,'.base64_encode((string) file_get_contents(self::chemin()));
    }
}
