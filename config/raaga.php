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
    | Deux démarches sur le site public (investir, obtenir une boutique). Les paramètres « présentation des actions
    | visible » et « souscription d'actions ouverte » sont décidés dans le back-office (SUPER_ADMIN, confirmation du
    | VALIDATEUR) ; ces valeurs ne servent que tant qu'aucune décision n'est confirmée (fermé par défaut).
    */
    /*
    | RAAGA Invest : nom de la seule partie investissement (menu « Investir », pages, titre du portail) ; le reste de la
    | plateforme garde son nom (paramètres de la plateforme). Routes du portail : voir le gabarit public.
    */
    'invest' => [
        'nom' => 'RAAGA Invest',
        // Pages d'information sur les actions, souscription et son paiement (les cautions ont leurs propres routes).
        'routes' => ['offre', 'transparence', 'actualites.*', 'souscrire', 'souscrire.*', 'souscription.*', 'paiement.*', 'invest.*'],
    ],

    'phase' => [
        'presentation_actions' => (bool) env('RAAGA_PRESENTATION_ACTIONS', false),
        'souscription_actions' => (bool) env('RAAGA_SOUSCRIPTION_ACTIONS', false),
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
        // Délais de paiement (caution, complément, avance) : ParametresPaiements::DELAIS, puis la valeur confirmée.
    ],

    /*
    | Comptes de destination séparés (App\Enums\CompteDestination) : fonds d'investissement (souscriptions d'actions,
    | appels de fonds des actions A) et fonds de location (caution, avance, loyers des boutiques). Jamais de mélange.
    | Le compte d'investissement reprend les variables RAAGA_SEQUESTRE_* existantes. Les clés et identifiants des
    | opérateurs de paiement (par compte) restent ici, dans le .env : jamais dans la base.
    */
    'compte_sequestre' => [
        'titulaire' => env('RAAGA_SEQUESTRE_TITULAIRE'),
        'banque' => env('RAAGA_SEQUESTRE_BANQUE'),
        'numero' => env('RAAGA_SEQUESTRE_NUMERO'),
    ],
    'compte_location' => [
        'titulaire' => env('RAAGA_COMPTE_LOCATION_TITULAIRE'),
        'banque' => env('RAAGA_COMPTE_LOCATION_BANQUE'),
        'numero' => env('RAAGA_COMPTE_LOCATION_NUMERO'),
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
    | Référentiel des boutiques : import et export Excel (.xlsx), onglet « Locaux ».
    */
    'referentiel_boutiques' => [
        // Fichiers importés, conservés hors du dossier public (disque « local » : storage/app/private).
        'disque' => 'local',
        'dossier' => 'imports/boutiques',
        'taille_max_ko' => 5120,
        'lignes_max' => 5000,
        // Lignes du modèle préparées avec la formule du code.
        'lignes_modele' => 500,
    ],

    /*
    | Référentiel des sites : import et export Excel (.xlsx), onglets « Sites » et « Capacités ».
    */
    'referentiel_sites' => [
        'disque' => 'local',
        'dossier' => 'imports/sites',
        'taille_max_ko' => 5120,
        'lignes_max' => 5000,
        // Lignes du modèle préparées avec les formules (comme le fichier de référence : 30 sites, 300 capacités).
        'lignes_modele_sites' => 30,
        'lignes_modele_capacites' => 300,
    ],

    /*
    | Grille tarifaire des boutiques (par site, niveau et type) : valeurs proposées au RÉGULATEUR, qui les ajuste
    | avant de soumettre la grille au VALIDATEUR. Loyer mensuel = surface × loyer au m² ; caution = loyer × mois de
    | caution ; avance = loyer × mois d'avance (montants arrondis au franc).
    */
    'grille_tarifaire' => [
        'mois_caution' => 12,
        // Mois d'avance par niveau (code du niveau, table « niveaux »).
        'mois_avance_par_niveau' => ['RC' => 60, 'R1' => 36, 'R2' => 24, 'R3' => 12, 'SS' => 12],
        // Mois d'avance par type (code TypeBoutique), prioritaire sur le niveau : les magasins, quel que soit le niveau.
        'mois_avance_par_type' => ['MAG' => 12],
        // Unité du loyer proposée par type (code TypeBoutique) ; « m2 » par défaut. Le RÉGULATEUR la choisit sur chaque ligne.
        'unite_par_type' => ['ETAL' => 'UNITE', 'POSTE' => 'UNITE', 'CF' => 'REDEVANCE'],
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

    /*
    | Cartes d'accès Raaga : durée du contrat d'une boutique (validité de la carte COMMERÇANT = signature du contrat le
    | plus récent + cette durée), validités des cartes ACTIONNAIRE (renouvelée tant qu'il détient des actions) et
    | PERSONNEL, avertissement avant l'expiration de la pièce d'identité. Clé des portiques : vérification sans données.
    */
    'cartes' => [
        'duree_contrat_annees' => (int) env('RAAGA_DUREE_CONTRAT_ANNEES', 5),
        'validite_actionnaire_mois' => 12,
        'validite_personnel_mois' => 12,
        'avertissement_piece_jours' => 30,
        'cle_portiques' => env('RAAGA_CLE_PORTIQUES'),
        // Vérifications par minute et par adresse IP, sur /c/{jeton} et /portiques/cartes/{jeton} (au-delà : 429).
        'tentatives_par_minute' => (int) env('RAAGA_CARTES_TENTATIVES', 30),
    ],

];
