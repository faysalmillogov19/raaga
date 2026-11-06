<?php

namespace App\Support;

use App\Enums\TypeDocumentKyc;
use Closure;

/**
 * NIP (numéro d'identification personnel) de la CNIB et de la CIB-AES (06/11/2026) : 17 chiffres, saisis à la place du
 * numéro de la pièce ; espaces, points et tirets acceptés à la saisie puis retirés. Le passeport garde son numéro
 * (5 à 40 caractères). Les numéros déjà enregistrés restent valables : le NIP est exigé à chaque nouvelle saisie.
 */
final class Nip
{
    public const LONGUEUR = 17;

    /** Pièces dont le numéro saisi est le NIP. */
    public const PIECES = [TypeDocumentKyc::Cnib, TypeDocumentKyc::CibAes];

    public static function porteNip(?TypeDocumentKyc $type): bool
    {
        return in_array($type, self::PIECES, true);
    }

    public static function normaliser(string $saisie): string
    {
        return preg_replace('/[\s.\-]/u', '', $saisie) ?? '';
    }

    public static function valide(?string $saisie): bool
    {
        return $saisie !== null && preg_match('/^\d{'.self::LONGUEUR.'}$/', self::normaliser($saisie)) === 1;
    }

    /** Contrôle du numéro saisi selon le type de pièce (null : type inconnu, contrôle générique seul). */
    public static function regle(?TypeDocumentKyc $type): Closure
    {
        return function (string $attribut, mixed $valeur, Closure $echec) use ($type) {
            if (self::porteNip($type) && filled($valeur) && ! self::valide((string) $valeur)) {
                $echec(self::message($type));
            }
        };
    }

    public static function message(TypeDocumentKyc $type): string
    {
        return __('public.nip.erreur', ['piece' => __('public.nip.pieces.'.$type->value)]);
    }

    /**
     * Textes du champ selon le type de pièce choisi (libellé, texte indicatif, message du navigateur), pour les vues.
     *
     * @return array{libelles: array<string, string>, indications: array<string, string>, erreurs: array<string, string>}
     */
    public static function textes(): array
    {
        $textes = ['libelles' => [], 'indications' => [], 'erreurs' => []];
        foreach (self::PIECES as $type) {
            $piece = __('public.nip.pieces.'.$type->value);
            $textes['libelles'][$type->value] = __('public.nip.libelle', ['piece' => $piece]);
            $textes['indications'][$type->value] = __('public.nip.indication', ['piece' => $piece]);
            $textes['erreurs'][$type->value] = self::message($type);
        }

        return $textes;
    }
}
