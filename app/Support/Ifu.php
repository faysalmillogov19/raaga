<?php

namespace App\Support;

use App\Models\User;
use App\Services\Audit\Journal;
use Closure;

/**
 * Numéro IFU (identifiant financier unique) : 8 chiffres suivis d'une lettre, un seul compte par numéro (comité
 * restreint du 03/11/2026). Demandé à la création du compte d'une personne morale, et à la première réservation d'une
 * boutique pour une personne physique, avec l'attestation que le VALIDATEUR vérifie comme les autres pièces. Il sert de
 * numéro à la carte de commerçant. Toute modification est inscrite au journal.
 */
final class Ifu
{
    public const FORMAT = '/^\d{8}[A-Z]$/';

    /** Majuscules, sans espace, tiret ni le mot « IFU » (« IFU 0000-4742 u » donne « 00004742U »). */
    public static function normaliser(?string $ifu): string
    {
        $ifu = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $ifu)) ?? '';

        return str_starts_with($ifu, 'IFU') && strlen($ifu) > 9 ? substr($ifu, 3) : $ifu;
    }

    /**
     * Règles du champ : présence (« required », « nullable », « required_if:… »), format une fois normalisé, et numéro
     * propre à ce compte.
     *
     * @return list<mixed>
     */
    public static function regles(?User $titulaire = null, string $presence = 'required'): array
    {
        return [...explode('|', $presence), 'string', 'max:30', function (string $attribut, mixed $valeur, Closure $echec) use ($titulaire) {
            $ifu = self::normaliser((string) $valeur);
            if (! preg_match(self::FORMAT, $ifu)) {
                $echec(__('public.compte.inscription.erreurs.ifu'));

                return;
            }
            if (User::query()->where('ifu', $ifu)->when($titulaire, fn ($q) => $q->whereKeyNot($titulaire->id))->exists()) {
                $echec(__('public.compte.inscription.erreurs.ifu_existe'));
            }
        }];
    }

    /** Enregistre l'IFU du compte s'il change, avec l'ancienne et la nouvelle valeur au journal. */
    public static function enregistrer(User $user, string $ifu, ?User $acteur, string $origine): void
    {
        $ifu = self::normaliser($ifu);
        if ($ifu === '' || $ifu === $user->ifu) {
            return;
        }
        $avant = $user->ifu;
        $user->forceFill(['ifu' => $ifu])->save();
        app(Journal::class)->ecrire($acteur ?? $user, 'ifu.modifie', 'User', $user->id, ['ifu' => $avant], ['ifu' => $ifu, 'origine' => $origine]);
    }
}
