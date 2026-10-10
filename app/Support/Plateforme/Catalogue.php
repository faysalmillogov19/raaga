<?php

namespace App\Support\Plateforme;

use App\Enums\Droit;
use App\Support\Telephone;
use Illuminate\Validation\Rule;

/**
 * Catalogue des paramètres de la plateforme : 8 sections, leurs champs, leur type, le droit qui
 * permet de les modifier. Il pilote le formulaire, la validation serveur, la normalisation et les
 * valeurs par défaut (un champ inconnu reste vide et n'est jamais affiché sur le site).
 */
final class Catalogue
{
    /** Ordre d'affichage des sections et sections modifiables par l'ADMIN (les autres : SUPER_ADMIN seul). */
    public const SECTIONS = [
        'identite' => false,
        'contacts' => true,
        'siege' => true,
        'contacts_specialises' => false,
        'mentions' => false,
        'reseaux' => true,
        'projet' => false,
        'messages' => true,
    ];

    /** Champs dont la modification demande une confirmation (affichés partout : site, SMS, PDF). */
    public const SENSIBLES = ['numeroVert', 'emailAssistance', 'urlSiteOfficiel', 'contactFraude'];

    public const JOURS = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];

    public const RESEAUX = ['facebook', 'x', 'linkedin', 'youtube', 'tiktok', 'whatsapp_channel'];

    public const COULEURS = ['vert', 'jaune', 'gris'];

    public const TYPES_BANDEAU = ['information', 'alerte', 'maintenance'];

    /** Taille maximale des armoiries (Ko). */
    public const ARMOIRIES_MAX_KO = 500;

    /**
     * Champs par section : nom => [type, longueur maximale éventuelle].
     *
     * @return array<string, array<string, array{0: string, 1?: int}>>
     */
    public static function champs(): array
    {
        return [
            'identite' => [
                'nomPlateforme' => ['texte', 80],
                'sousTitre' => ['texte', 160],
                'mentionTutelle' => ['texte', 160],
                'deviseNationale' => ['texte', 120],
                'armoiries' => ['image'],
                'expediteurSms' => ['expediteur'],
                'urlSiteOfficiel' => ['url'],
            ],
            'contacts' => [
                'numeroVert' => ['tel_bf'],
                'telephoneInternational' => ['tel_intl'],
                'whatsapp' => ['tel_intl'],
                'emailAssistance' => ['email'],
                'horairesCentreAppels' => ['horaires'],
                'messageHorsHoraires' => ['texte_long', 200],
            ],
            'siege' => [
                'adresseSiege' => ['texte_long', 300],
                'boitePostale' => ['texte', 80],
                'horairesAccueil' => ['horaires'],
                'coordonneesGps' => ['gps'],
                'lienCarte' => ['url'],
            ],
            'contacts_specialises' => [
                'contactFraude' => ['contact'],
                'contactDonneesPersonnelles' => ['contact'],
                'contactPresse' => ['contact'],
                'contactRelationsActionnaires' => ['contact'],
            ],
            'mentions' => [
                'denominationSociale' => ['texte', 120],
                'formeJuridique' => ['texte', 80],
                'capitalSocial' => ['entier'],
                'rccm' => ['texte', 60],
                'ifu' => ['texte', 40],
                'directeurPublication' => ['personne'],
                'hebergeur' => ['hebergeur'],
                'referenceCil' => ['texte', 120],
                'referenceAutoriteMarche' => ['texte', 160],
            ],
            'reseaux' => [
                'reseauxSociaux' => ['reseaux'],
                'liensInstitutionnels' => ['liens'],
            ],
            'projet' => [
                'nombreBoutiques' => ['entier'],
                'coutTotalProjet' => ['entier'],
                'repartitionFinancement' => ['financement'],
                'localisationMarche' => ['texte', 200],
                'dateOuverturePrevisionnelle' => ['mois_annee'],
            ],
            'messages' => [
                'bandeauAnnonce' => ['bandeau'],
                'modeMaintenance' => ['maintenance'],
                'messageAntiFraude' => ['texte_long', 250],
            ],
        ];
    }

    public static function existe(string $section): bool
    {
        return array_key_exists($section, self::SECTIONS);
    }

    /**
     * Droit requis pour modifier la section (le SUPER_ADMIN détient les deux).
     */
    public static function droitModification(string $section): Droit
    {
        return self::SECTIONS[$section] ? Droit::PlateformeModifier : Droit::PlateformeModifierTout;
    }

    public static function type(string $section, string $champ): string
    {
        return self::champs()[$section][$champ][0];
    }

    /**
     * Valeurs vides d'une section (structure complète, aucune valeur inventée).
     *
     * @return array<string, mixed>
     */
    public static function vide(string $section): array
    {
        $valeurs = [];
        foreach (self::champs()[$section] as $champ => [$type]) {
            $valeurs[$champ] = match ($type) {
                'horaires' => ['jours' => [], 'debut' => null, 'fin' => null],
                'gps' => ['latitude' => null, 'longitude' => null],
                'contact' => ['libelle' => null, 'nom' => null, 'telephone' => null, 'email' => null, 'afficher' => false],
                'personne' => ['nom' => null, 'fonction' => null],
                'hebergeur' => ['nom' => null, 'adresse' => null, 'contact' => null],
                'reseaux', 'liens', 'financement' => [],
                'mois_annee' => ['mois' => null, 'annee' => null],
                'bandeau' => ['texte' => null, 'type' => 'information', 'debut' => null, 'fin' => null, 'lien' => null],
                'maintenance' => ['actif' => false, 'message' => null, 'finPrevue' => null],
                default => null,
            };
        }

        return $valeurs;
    }

    /**
     * Règles de validation (serveur) d'une section, sur les noms de champs du formulaire.
     *
     * @return array<string, mixed>
     */
    public static function regles(string $section): array
    {
        $regles = [];
        $https = 'regex:#^https://#i';

        foreach (self::champs()[$section] as $champ => $definition) {
            [$type, $max] = $definition + [1 => 255];

            $regles += match ($type) {
                'texte', 'texte_long' => [$champ => ['nullable', 'string', "max:{$max}"]],
                'url' => [$champ => ['nullable', 'url:https', $https, 'max:255']],
                'email' => [$champ => ['nullable', 'email', 'max:255']],
                'tel_bf' => [$champ => ['nullable', 'regex:/^(\+?226)?\s*(\d\s*){8}$/']],
                'tel_intl' => [$champ => ['nullable', 'regex:/^\+\s*[1-9](\s*\d){7,14}$/']],
                'expediteur' => [$champ => ['nullable', 'regex:/^[A-Za-z0-9]{1,11}$/']],
                'image' => [$champ => ['nullable', 'file', 'mimes:svg,png', 'max:'.self::ARMOIRIES_MAX_KO]],
                'entier' => [$champ => ['nullable', 'integer', 'min:0', 'max:9000000000000000']],
                'horaires' => [
                    "{$champ}.jours" => ['nullable', 'array'],
                    "{$champ}.jours.*" => [Rule::in(self::JOURS)],
                    "{$champ}.debut" => ['nullable', 'date_format:H:i', "required_with:{$champ}.fin"],
                    "{$champ}.fin" => ['nullable', 'date_format:H:i', "required_with:{$champ}.debut", "after:{$champ}.debut"],
                ],
                'gps' => [
                    "{$champ}.latitude" => ['nullable', 'numeric', 'between:-90,90', "required_with:{$champ}.longitude"],
                    "{$champ}.longitude" => ['nullable', 'numeric', 'between:-180,180', "required_with:{$champ}.latitude"],
                ],
                'contact' => [
                    "{$champ}.libelle" => ['nullable', 'string', 'max:80'],
                    "{$champ}.nom" => ['nullable', 'string', 'max:120'],
                    "{$champ}.telephone" => ['nullable', 'regex:/^\+?(\s*\d){8,15}$/'],
                    "{$champ}.email" => ['nullable', 'email', 'max:255'],
                    "{$champ}.afficher" => ['boolean'],
                ],
                'personne' => [
                    "{$champ}.nom" => ['nullable', 'string', 'max:120'],
                    "{$champ}.fonction" => ['nullable', 'string', 'max:120'],
                ],
                'hebergeur' => [
                    "{$champ}.nom" => ['nullable', 'string', 'max:120'],
                    "{$champ}.adresse" => ['nullable', 'string', 'max:300'],
                    "{$champ}.contact" => ['nullable', 'string', 'max:160'],
                ],
                'reseaux' => [
                    $champ => ['nullable', 'array', 'max:20'],
                    "{$champ}.*.reseau" => ['required', Rule::in(self::RESEAUX)],
                    "{$champ}.*.url" => ['required', 'url:https', $https, 'max:255'],
                    "{$champ}.*.afficher" => ['boolean'],
                    "{$champ}.*.ordre" => ['nullable', 'integer', 'min:0', 'max:999'],
                ],
                'liens' => [
                    $champ => ['nullable', 'array', 'max:20'],
                    "{$champ}.*.libelle" => ['required', 'string', 'max:120'],
                    "{$champ}.*.url" => ['required', 'url:https', $https, 'max:255'],
                    "{$champ}.*.ordre" => ['nullable', 'integer', 'min:0', 'max:999'],
                ],
                'financement' => [
                    $champ => ['nullable', 'array', 'max:12'],
                    "{$champ}.*.libelle" => ['required', 'string', 'max:80'],
                    "{$champ}.*.montant" => ['required', 'integer', 'min:1'],
                    "{$champ}.*.couleur" => ['required', Rule::in(self::COULEURS)],
                    "{$champ}.*.approximatif" => ['boolean'],
                    "{$champ}.*.ordre" => ['nullable', 'integer', 'min:0', 'max:999'],
                ],
                'mois_annee' => [
                    "{$champ}.mois" => ['nullable', 'integer', 'between:1,12'],
                    "{$champ}.annee" => ['nullable', 'integer', 'between:2020,2100', "required_with:{$champ}.mois"],
                ],
                'bandeau' => [
                    "{$champ}.texte" => ['nullable', 'string', 'max:200'],
                    "{$champ}.type" => ['required', Rule::in(self::TYPES_BANDEAU)],
                    "{$champ}.debut" => ['nullable', 'date'],
                    "{$champ}.fin" => ['nullable', 'date', 'after:'.$champ.'.debut'],
                    "{$champ}.lien" => ['nullable', 'url:https', $https, 'max:255'],
                ],
                'maintenance' => [
                    "{$champ}.actif" => ['boolean'],
                    "{$champ}.message" => ['nullable', 'string', 'max:300', "required_if_accepted:{$champ}.actif"],
                    "{$champ}.finPrevue" => ['nullable', 'date'],
                ],
                default => [],
            };
        }

        return $regles;
    }

    /**
     * Valeurs canoniques à enregistrer : textes nettoyés, vide → null, téléphones au format
     * international, listes triées par ordre et sans ligne vide. Le fichier des armoiries est traité à part.
     *
     * @param  array<string, mixed>  $saisie
     * @param  array<string, mixed>  $actuelles
     * @return array<string, mixed>
     */
    public static function normaliser(string $section, array $saisie, array $actuelles): array
    {
        $texte = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;
        $entier = fn ($v) => $v === null || $v === '' ? null : (int) $v;
        $intl = fn ($v) => ($v = preg_replace('/\s+/', '', (string) $v)) === '' ? null : $v;
        $ordonner = fn (array $lignes) => array_values(collect($lignes)->sortBy('ordre')->values()
            ->map(fn (array $l, int $i) => [...$l, 'ordre' => $i + 1])->all());

        $valeurs = [];
        foreach (self::champs()[$section] as $champ => [$type]) {
            $v = $saisie[$champ] ?? null;

            $valeurs[$champ] = match ($type) {
                'texte', 'texte_long', 'url', 'email' => $texte($v),
                'expediteur' => $texte($v),
                'tel_bf' => ($c = Telephone::chiffresLocaux((string) $v)) === '' ? null : '+226'.$c,
                'tel_intl' => $intl($v),
                'image' => $actuelles[$champ] ?? null,
                'entier' => $entier($v),
                'horaires' => [
                    'jours' => array_values(array_intersect(self::JOURS, (array) ($v['jours'] ?? []))),
                    'debut' => $texte($v['debut'] ?? null),
                    'fin' => $texte($v['fin'] ?? null),
                ],
                'gps' => [
                    'latitude' => ($v['latitude'] ?? '') === '' ? null : (float) $v['latitude'],
                    'longitude' => ($v['longitude'] ?? '') === '' ? null : (float) $v['longitude'],
                ],
                'contact' => [
                    'libelle' => $texte($v['libelle'] ?? null),
                    'nom' => $texte($v['nom'] ?? null),
                    'telephone' => $intl($v['telephone'] ?? null),
                    'email' => $texte($v['email'] ?? null),
                    'afficher' => (bool) ($v['afficher'] ?? false),
                ],
                'personne' => ['nom' => $texte($v['nom'] ?? null), 'fonction' => $texte($v['fonction'] ?? null)],
                'hebergeur' => [
                    'nom' => $texte($v['nom'] ?? null),
                    'adresse' => $texte($v['adresse'] ?? null),
                    'contact' => $texte($v['contact'] ?? null),
                ],
                'reseaux' => $ordonner(array_map(fn (array $l) => [
                    'reseau' => $l['reseau'],
                    'url' => trim($l['url']),
                    'afficher' => (bool) ($l['afficher'] ?? false),
                    'ordre' => (int) ($l['ordre'] ?? 999),
                ], array_values((array) $v))),
                'liens' => $ordonner(array_map(fn (array $l) => [
                    'libelle' => trim($l['libelle']),
                    'url' => trim($l['url']),
                    'ordre' => (int) ($l['ordre'] ?? 999),
                ], array_values((array) $v))),
                'financement' => $ordonner(array_map(fn (array $l) => [
                    'libelle' => trim($l['libelle']),
                    'montant' => (int) $l['montant'],
                    'couleur' => $l['couleur'],
                    'approximatif' => (bool) ($l['approximatif'] ?? false),
                    'ordre' => (int) ($l['ordre'] ?? 999),
                ], array_values((array) $v))),
                'mois_annee' => ['mois' => $entier($v['mois'] ?? null), 'annee' => $entier($v['annee'] ?? null)],
                'bandeau' => [
                    'texte' => $texte($v['texte'] ?? null),
                    'type' => $v['type'] ?? 'information',
                    'debut' => $texte($v['debut'] ?? null),
                    'fin' => $texte($v['fin'] ?? null),
                    'lien' => $texte($v['lien'] ?? null),
                ],
                'maintenance' => [
                    'actif' => (bool) ($v['actif'] ?? false),
                    'message' => $texte($v['message'] ?? null),
                    'finPrevue' => $texte($v['finPrevue'] ?? null),
                ],
                default => $v,
            };
        }

        return $valeurs;
    }
}
