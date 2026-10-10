<?php

namespace App\Support;

/**
 * Numéros de téléphone burkinabè : 8 chiffres, indicatif +226.
 * Stockage : « +22670123456 ». Affichage : « +226 70 12 34 56 ».
 */
final class Telephone
{
    public const INDICATIF = '+226';

    /** 8 chiffres, mobile (commence par 5, 6 ou 7). */
    public const MOTIF_LOCAL = '/^[567]\d{7}$/';

    /**
     * « 70 12 34 56 », « 70-12-34-56 », « +226 70123456 » → « 70123456 ».
     */
    public static function chiffresLocaux(?string $saisie): string
    {
        $chiffres = preg_replace('/\D/', '', (string) $saisie);

        if (strlen($chiffres) === 11 && str_starts_with($chiffres, '226')) {
            $chiffres = substr($chiffres, 3);
        }

        return $chiffres;
    }

    public static function estValide(?string $saisie): bool
    {
        return (bool) preg_match(self::MOTIF_LOCAL, self::chiffresLocaux($saisie));
    }

    public static function normaliser(string $saisie): string
    {
        return self::INDICATIF.self::chiffresLocaux($saisie);
    }

    /**
     * « +22670123456 » → « 70 12 34 56 ».
     */
    public static function formaterLocal(string $telephone): string
    {
        return trim(chunk_split(self::chiffresLocaux($telephone), 2, ' '));
    }

    /**
     * « +22670123456 » → « +226 70 12 34 56 ».
     */
    public static function formater(string $telephone): string
    {
        return self::INDICATIF.' '.self::formaterLocal($telephone);
    }

    /**
     * Pour les journaux (aucune donnée personnelle en clair) : « +226 70 •• •• 56 ».
     */
    public static function masquer(string $telephone): string
    {
        $local = self::chiffresLocaux($telephone);

        return self::INDICATIF.' '.substr($local, 0, 2).' •• •• '.substr($local, -2);
    }
}
