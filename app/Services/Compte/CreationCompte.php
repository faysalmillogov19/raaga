<?php

namespace App\Services\Compte;

use App\Enums\DocumentAccepte;
use App\Enums\Role as RoleEnum;
use App\Enums\StatutKyc;
use App\Enums\TypeTitulaire;
use App\Models\Acceptation;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Création d'un compte citoyen, en ligne (inscription) ou au guichet (par un agent, après confirmation du numéro par le
 * code SMS reçu par la personne présente) : mêmes champs, mêmes règles, conditions d'utilisation acceptées et tracées.
 */
class CreationCompte
{
    public const CHAMPS_IDENTITE = ['type_titulaire', 'raison_sociale', 'forme_morale', 'nom', 'prenoms', 'date_naissance', 'pays_residence', 'region'];

    /** Règles de l'identité saisie à la création du compte (conditions d'utilisation comprises). */
    public static function regles(): array
    {
        $age = (int) config('raaga.inscription.age_minimum');

        return [
            // Personne physique, ou personne morale (raison sociale, forme) tenue par son représentant légal.
            'type_titulaire' => ['required', Rule::enum(TypeTitulaire::class)],
            'raison_sociale' => ['nullable', 'required_if:type_titulaire,MORALE', 'string', 'min:2', 'max:200'],
            'forme_morale' => ['nullable', 'required_if:type_titulaire,MORALE', Rule::in(TypeTitulaire::FORMES)],
            'nom' => ['required', 'string', 'max:100'],
            'prenoms' => ['required', 'string', 'max:150'],
            'date_naissance' => ['required', 'date_format:Y-m-d', 'after:1900-01-01', 'before_or_equal:'.now()->subYears($age)->toDateString()],
            'pays_residence' => ['required', Rule::in(array_keys(config('raaga.inscription.pays')))],
            'region' => ['nullable', 'required_if:pays_residence,BF', Rule::in(config('raaga.inscription.regions'))],
            'conditions' => ['accepted'],
        ];
    }

    /** Messages des règles ci-dessus. */
    public static function messages(): array
    {
        $messages = __('public.compte.inscription.erreurs');
        $titulaires = __('public.titulaires.erreurs');

        return [
            'type_titulaire.*' => $titulaires['type'],
            'raison_sociale.*' => $titulaires['raison_sociale'],
            'forme_morale.*' => $titulaires['forme'],
            'nom.*' => $messages['nom'],
            'prenoms.*' => $messages['prenoms'],
            'date_naissance.before_or_equal' => __('public.compte.inscription.erreurs.majeur', ['age' => (int) config('raaga.inscription.age_minimum')]),
            'date_naissance.*' => $messages['date_naissance'],
            'pays_residence.*' => $messages['pays'],
            'region.*' => $messages['region'],
            'conditions.*' => $messages['conditions'],
        ];
    }

    /**
     * @param  array<string, mixed>  $donnees  identité validée par regles()
     * @param  User|null  $agent  agent de guichet qui crée le compte pour la personne présente (null : inscription en ligne)
     */
    public function creer(array $donnees, string $telephone, ?string $ip, ?string $userAgent, ?User $agent = null): User
    {
        return DB::transaction(function () use ($donnees, $telephone, $ip, $userAgent, $agent) {
            $nom = mb_strtoupper(trim($donnees['nom']), 'UTF-8');
            $prenoms = trim($donnees['prenoms']);
            $morale = $donnees['type_titulaire'] === TypeTitulaire::Morale->value;

            $user = User::create([
                'name' => $prenoms.' '.$nom,
                'nom' => $nom,
                'prenoms' => $prenoms,
                'telephone' => $telephone,
                'date_naissance' => $donnees['date_naissance'],
                'nationalite' => 'BF',
                'pays_residence' => $donnees['pays_residence'],
                'region' => $donnees['pays_residence'] === 'BF' ? $donnees['region'] : null,
                'role_id' => Role::pour(RoleEnum::Citoyen)->id,
                'statut_kyc' => StatutKyc::NonSoumis,
                'type_titulaire' => $donnees['type_titulaire'],
                'raison_sociale' => $morale ? trim($donnees['raison_sociale']) : null,
                'forme_morale' => $morale ? $donnees['forme_morale'] : null,
            ]);

            Acceptation::create([
                'user_id' => $user->id,
                'document' => DocumentAccepte::ConditionsUtilisation,
                'version_document' => config('raaga.inscription.version_conditions'),
                'accepte_le' => now(),
                'ip' => $ip,
                'user_agent' => substr((string) $userAgent, 0, 500),
            ]);

            // Journal d'audit : aucune donnée personnelle, seulement la nature du compte (et l'agent, au guichet).
            AuditLog::create([
                'acteur_id' => $agent?->id ?? $user->id,
                'action' => 'compte.cree',
                'entite_type' => 'User',
                'entite_id' => (string) $user->id,
                'nouvelle_valeur' => array_filter(['role' => RoleEnum::Citoyen->value, 'diaspora' => $donnees['pays_residence'] !== 'BF',
                    'titulaire' => $donnees['type_titulaire'], 'guichet' => $agent !== null ?: null], fn ($v) => $v !== null),
                'ip' => $ip,
                'created_at' => now(),
            ]);

            return $user;
        });
    }
}
