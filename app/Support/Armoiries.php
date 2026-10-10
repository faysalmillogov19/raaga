<?php

namespace App\Support;

/**
 * Armoiries de la plateforme : raccourci vers ImagesPlateforme (champ « armoiries »).
 */
final class Armoiries
{
    public static function chemin(): string
    {
        return ImagesPlateforme::chemin('armoiries');
    }

    public static function type(): string
    {
        return ImagesPlateforme::type('armoiries');
    }

    public static function url(): string
    {
        return ImagesPlateforme::url('armoiries');
    }

    /** Pour les PDF : image intégrée au document. */
    public static function dataUri(): string
    {
        return ImagesPlateforme::dataUri('armoiries');
    }
}
