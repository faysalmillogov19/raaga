<?php

return [

    /*
    | Mode démonstration des pages publiques : sans tranche ouverte en base, les pages
    | affichent des données fictives (aucun bandeau ne le signale).
    */
    'demo' => (bool) env('RAAGA_DEMO', false),

    /*
    | Identité, contacts, mentions légales, chiffres du projet, messages de service :
    | paramètres de la plateforme, saisis dans le back-office (/admin/plateforme) et lus en base.
    */

    /*
    | Documents publics (PDF déposés dans public/documents/). Un fichier absent est affiché
    | « bientôt disponible ». Le règlement de la tranche sera généré depuis ses paramètres.
    */
    'documents' => [
        'reglement' => null,
        'note_information' => 'documents/note-information.pdf',
        'statuts' => 'documents/statuts-raaga-sa.pdf',
        'guide' => 'documents/guide-du-souscripteur.pdf',
    ],

    // Rapports publiés sur la page Transparence : [['titre' => …, 'date' => 'AAAA-MM-JJ', 'fichier' => 'documents/…pdf'], …]
    'rapports' => [],

    /*
    | Back-office (/admin) : sécurité des sessions et des connexions.
    */
    'backoffice' => [
        'session_heures' => 8,
        'inactivite_minutes' => 30,
        'echecs_max' => 5,
        'verrouillage_minutes' => 15,
        'mot_de_passe_min' => 12,
        'invitation_heures' => 48,

        // Dispense de TOTP pour les comptes de démonstration non enrôlés. N'a d'effet qu'en
        // environnement « local » : en production, la double authentification est toujours exigée.
        'totp_facultatif_en_local' => (bool) env('RAAGA_TOTP_FACULTATIF_EN_LOCAL', false),
    ],

    /*
    | Dividendes. Frais d'un versement par canal (fixe FCFA + points de base du montant versé) :
    | valeurs provisoires, à remplacer par le référentiel des canaux de paiement.
    */
    /*
    | Compte séquestre recevant les virements des appels de fonds (actions A). Provisoire en
    | attendant le référentiel des canaux de paiement : null = « communiqué par votre interlocuteur ».
    */
    /*
    | Paiement des souscriptions. null = simulateur de recette hors production (refusé en production).
    | « simule » explicitement en production : démarrage refusé (App\Support\VerrouEnvironnement).
    */
    /*
    | Périmètre de la phase : la phase 1 (décision du 13/10/2026) ne porte que sur les boutiques et leurs
    | cautions. true = parcours publics des actions visibles (phase 2).
    */
    'phase' => [
        'actions_publiques' => (bool) env('RAAGA_ACTIONS_PUBLIQUES', false),
    ],

    'sms' => [
        // « log » : aucun envoi, le message est écrit dans les journaux (seul pilote disponible).
        'pilote' => env('RAAGA_SMS_PILOTE', 'log'),
        // Page « SMS de recette » (/recette/sms) : jamais en production ; false pour la couper ailleurs.
        'journal_recette' => env('RAAGA_SMS_JOURNAL_RECETTE'),
        'fichier_recette' => storage_path('logs/sms-recette.jsonl'),
    ],

    'paiement' => [
        'fournisseur' => env('RAAGA_PAIEMENT_FOURNISSEUR'),
    ],

    'compte_sequestre' => [
        'titulaire' => env('RAAGA_SEQUESTRE_TITULAIRE'),
        'banque' => env('RAAGA_SEQUESTRE_BANQUE'),
        'numero' => env('RAAGA_SEQUESTRE_NUMERO'),
    ],

    /*
    | Investisseurs institutionnels : rappels des appels de fonds (jours avant l'échéance).
    | L'objectif des actions A vient du plan de financement (paramètres de la plateforme).
    */
    'institutionnels' => [
        'rappels_jours' => [15, 3, 0],
    ],

    'dividendes' => [
        'frais_versement' => [
            'ORANGE_MONEY' => ['fixe' => 0, 'pb' => 100],
            'MOOV_MONEY' => ['fixe' => 0, 'pb' => 100],
            'VIREMENT' => ['fixe' => 500, 'pb' => 0],
        ],
    ],

    /*
    | Création de compte citoyen.
    */
    'inscription' => [
        'age_minimum' => 18,

        // Version des conditions d'utilisation acceptées (tracée dans la table acceptations).
        'version_conditions' => '2026-10',

        // Régions administratives (découpage en 13 régions — à mettre à jour si le découpage officiel change).
        'regions' => [
            'Boucle du Mouhoun', 'Cascades', 'Centre', 'Centre-Est', 'Centre-Nord', 'Centre-Ouest',
            'Centre-Sud', 'Est', 'Hauts-Bassins', 'Nord', 'Plateau-Central', 'Sahel', 'Sud-Ouest',
        ],

        // Pays de résidence proposés (code ISO 3166-1 alpha-2 => nom). ZZ = autre pays.
        'pays' => [
            'BF' => 'Burkina Faso',
            'CI' => 'Côte d’Ivoire',
            'ML' => 'Mali',
            'GH' => 'Ghana',
            'NE' => 'Niger',
            'TG' => 'Togo',
            'BJ' => 'Bénin',
            'SN' => 'Sénégal',
            'GA' => 'Gabon',
            'CM' => 'Cameroun',
            'NG' => 'Nigeria',
            'MA' => 'Maroc',
            'FR' => 'France',
            'BE' => 'Belgique',
            'DE' => 'Allemagne',
            'IT' => 'Italie',
            'ES' => 'Espagne',
            'CH' => 'Suisse',
            'GB' => 'Royaume-Uni',
            'US' => 'États-Unis',
            'CA' => 'Canada',
            'CN' => 'Chine',
            'SA' => 'Arabie saoudite',
            'AE' => 'Émirats arabes unis',
            'ZZ' => 'Autre pays',
        ],
    ],

];
