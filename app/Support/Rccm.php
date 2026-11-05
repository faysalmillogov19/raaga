<?php

namespace App\Support;

use App\Models\Greffe;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * Registre du commerce et du crédit mobilier (réunion du 05/11/2026) : numéro, date d'immatriculation, greffe choisi
 * dans la liste du SUPER_ADMIN (« Autre » : précision en texte, visible de l'ADMIN), et un
 * justificatif versé au dossier d'identité. Obligatoire pour une personne morale, facultatif pour une personne
 * physique ; jamais imprimé sur la carte.
 */
final class Rccm
{
    /** Numéro tel qu'inscrit au registre (par exemple BF OUA 2020 B 12345) : majuscules, espaces simples. */
    public static function normaliser(?string $numero): ?string
    {
        $numero = preg_replace('/\s+/u', ' ', mb_strtoupper(trim((string) $numero), 'UTF-8'));

        return $numero === '' ? null : $numero;
    }

    /**
     * Règles des trois champs. $presence : « required » (personne morale), « nullable » (facultatif : les trois
     * ensemble ou aucun), ou une règle required_if.
     *
     * @return array<string, list<mixed>>
     */
    public static function regles(string $presence = 'nullable', ?User $titulaire = null): array
    {
        $ensemble = $presence === 'nullable' ? ['required_with:rccm,rccm_immatricule_le,rccm_greffe_id'] : [];

        return [
            'rccm' => [...explode('|', $presence), ...$ensemble, 'nullable', 'string', 'min:5', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9 .\/-]*$/',
                Rule::unique('users', 'rccm')->ignore($titulaire?->id)],
            'rccm_immatricule_le' => [...explode('|', $presence), ...$ensemble, 'nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before_or_equal:today'],
            'rccm_greffe_id' => [...explode('|', $presence), ...$ensemble, 'nullable', 'integer', Rule::exists('greffes', 'id')->where('actif', true)],
            // « Autre (préciser) » : la précision est obligatoire.
            'rccm_greffe_autre' => [Rule::requiredIf(fn () => Greffe::idAutre() !== null && (int) request()->input('rccm_greffe_id') === Greffe::idAutre()),
                'nullable', 'string', 'min:3', 'max:120'],
        ];
    }

    /** Précision d'« Autre (préciser) » à garder ; rien pour un greffe de la liste. @param array<string, mixed> $donnees */
    public static function precisionAutre(array $donnees): ?string
    {
        $autre = trim((string) ($donnees['rccm_greffe_autre'] ?? ''));

        return (int) ($donnees['rccm_greffe_id'] ?? 0) === Greffe::idAutre() && $autre !== '' ? $autre : null;
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        $e = __('public.rccm.erreurs');

        return [
            'rccm.unique' => $e['existe'],
            'rccm.*' => $e['numero'],
            'rccm_immatricule_le.*' => $e['date'],
            'rccm_greffe_id.*' => $e['greffe'],
            'rccm_greffe_autre.*' => $e['greffe_autre'],
        ];
    }
}
