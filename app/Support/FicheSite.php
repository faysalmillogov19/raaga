<?php

namespace App\Support;

use App\Enums\PhaseSite;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * Règles de la fiche d'un site, communes au formulaire et à l'import Excel. Montants entiers en FCFA ;
 * livraison prévue au mois près (AAAA-MM) ; la part attendue des occupants ne dépasse pas le coût total.
 */
final class FicheSite
{
    /**
     * @param  array<string, mixed>|null  $donnees  ligne importée (sinon la requête) : sert à comparer la part au coût
     * @return array<string, list<mixed>>
     */
    public static function regles(?Site $site = null, bool $codeUnique = true, ?array $donnees = null): array
    {
        $cout = fn () => $donnees === null ? request()->input('cout_total') : ($donnees['cout_total'] ?? null);

        return [
            'code' => ['required', 'string', 'regex:'.Site::FORMAT_CODE, ...($codeUnique ? [Rule::unique('sites', 'code')->ignore($site?->id)] : [])],
            'nom' => ['required', 'string', 'max:150'],
            'ville' => ['required', 'string', 'max:80'],
            'arrondissement' => ['nullable', 'string', 'max:40'],
            'region' => ['nullable', Rule::in(config('raaga.inscription.regions'))],
            'adresse' => ['nullable', 'string', 'max:300'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'maitre_ouvrage' => ['nullable', 'string', 'max:200'],
            'maitre_oeuvre' => ['nullable', 'string', 'max:200'],
            'phase' => ['nullable', Rule::enum(PhaseSite::class)],
            'livraison_prevue' => ['nullable', 'date_format:Y-m'],
            'cout_total' => ['nullable', 'integer', 'min:0', 'max:999999999999999'],
            'part_occupants' => ['nullable', 'integer', 'min:0', 'max:999999999999999',
                fn (string $attribut, mixed $valeur, \Closure $echec) => self::partValide($valeur, $cout()) ?: $echec(__('admin.sites.erreurs.part_cout'))],
            'source_complement' => ['nullable', 'string', 'max:200'],
            'reference_plans' => ['nullable', 'string', 'max:200'],
            'version_plans' => ['nullable', 'string', 'max:60'],
            'source_donnees' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public static function partValide(mixed $part, mixed $cout): bool
    {
        return ! is_numeric($part) || ! is_numeric($cout) || (int) $part <= (int) $cout;
    }

    /** Données validées → attributs du modèle (livraison au premier jour du mois). @return array<string, mixed> */
    public static function attributs(array $donnees): array
    {
        if (array_key_exists('livraison_prevue', $donnees)) {
            $donnees['livraison_prevue'] = $donnees['livraison_prevue'] ? CarbonImmutable::createFromFormat('!Y-m', $donnees['livraison_prevue'])->startOfMonth() : null;
        }

        return $donnees;
    }
}
