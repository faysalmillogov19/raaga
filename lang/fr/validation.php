<?php

/*
 * Messages de validation en français. Les règles absentes retombent sur l'anglais (APP_FALLBACK_LOCALE).
 */

return [

    'accepted' => 'Vous devez accepter :attribute.',
    'after' => ':Attribute doit être une date postérieure au :date.',
    'after_or_equal' => ':Attribute doit être une date postérieure ou égale au :date.',
    'array' => ':Attribute doit être une liste.',
    'before' => ':Attribute doit être une date antérieure au :date.',
    'before_or_equal' => ':Attribute doit être une date antérieure ou égale au :date.',
    'between' => [
        'array' => ':Attribute doit contenir entre :min et :max éléments.',
        'file' => ':Attribute doit peser entre :min et :max kilo-octets.',
        'numeric' => ':Attribute doit être compris entre :min et :max.',
        'string' => ':Attribute doit contenir entre :min et :max caractères.',
    ],
    'boolean' => ':Attribute doit être vrai ou faux.',
    'confirmed' => 'La confirmation de :attribute ne correspond pas.',
    'current_password' => 'Le mot de passe est incorrect.',
    'date' => ':Attribute n’est pas une date valide.',
    'different' => ':Attribute et :other doivent être différents.',
    'digits' => ':Attribute doit contenir :digits chiffres.',
    'digits_between' => ':Attribute doit contenir entre :min et :max chiffres.',
    'email' => ':Attribute doit être une adresse électronique valide.',
    'enum' => 'La valeur choisie pour :attribute n’est pas valide.',
    'exists' => 'La valeur choisie pour :attribute n’est pas valide.',
    'file' => ':Attribute doit être un fichier.',
    'image' => ':Attribute doit être une image.',
    'in' => 'La valeur choisie pour :attribute n’est pas valide.',
    'integer' => ':Attribute doit être un nombre entier.',
    'lowercase' => ':Attribute doit être en minuscules.',
    'max' => [
        'array' => ':Attribute ne peut pas contenir plus de :max éléments.',
        'file' => ':Attribute ne peut pas dépasser :max kilo-octets.',
        'numeric' => ':Attribute ne peut pas être supérieur à :max.',
        'string' => ':Attribute ne peut pas dépasser :max caractères.',
    ],
    'mimes' => ':Attribute doit être un fichier de type : :values.',
    'min' => [
        'array' => ':Attribute doit contenir au moins :min éléments.',
        'file' => ':Attribute doit peser au moins :min kilo-octets.',
        'numeric' => ':Attribute doit être supérieur ou égal à :min.',
        'string' => ':Attribute doit contenir au moins :min caractères.',
    ],
    'numeric' => ':Attribute doit être un nombre.',
    'password' => [
        'letters' => ':Attribute doit contenir au moins une lettre.',
        'mixed' => ':Attribute doit contenir au moins une majuscule et une minuscule.',
        'numbers' => ':Attribute doit contenir au moins un chiffre.',
        'symbols' => ':Attribute doit contenir au moins un symbole.',
        'uncompromised' => 'Ce :attribute est apparu dans une fuite de données. Choisissez-en un autre.',
    ],
    'regex' => 'Le format de :attribute n’est pas valide.',
    'required' => 'Le champ « :attribute » est obligatoire.',
    'same' => ':Attribute et :other doivent être identiques.',
    'size' => [
        'array' => ':Attribute doit contenir :size éléments.',
        'file' => ':Attribute doit peser :size kilo-octets.',
        'numeric' => ':Attribute doit être égal à :size.',
        'string' => ':Attribute doit contenir :size caractères.',
    ],
    'string' => ':Attribute doit être une chaîne de caractères.',
    'unique' => 'Cette valeur de :attribute est déjà utilisée.',

    'custom' => [
        'email' => [
            'unique' => 'Un compte existe déjà avec cette adresse électronique.',
        ],
    ],

    'attributes' => [
        'name' => 'nom et prénom(s)',
        'email' => 'adresse électronique',
        'password' => 'mot de passe',
        'password_confirmation' => 'confirmation du mot de passe',
        'current_password' => 'mot de passe actuel',
        'role' => 'profil',
        'telephone' => 'téléphone',
        'nom' => 'nom',
        'prenoms' => 'prénom(s)',
        'date_naissance' => 'date de naissance',
    ],

];
