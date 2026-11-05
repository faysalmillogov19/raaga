<?php

// Environnement de recette (réunion du 05/11/2026) : bandeau et commandes de la recette.
return [
    'bandeau' => 'ENVIRONNEMENT DE TEST',
    'refusee' => 'Commande réservée à l’environnement de recette (APP_ENV=recette) : refusée ici (:env).',
    'comptes' => [
        'titre' => 'Comptes de recette',
        'colonnes' => ['Profil', 'Identifiant', 'Mot de passe', 'Connexion'],
        'conserve' => 'inchangé',
        'avertissement' => 'Mots de passe affichés une seule fois : transmettez-les aux testeurs par un canal sûr. Relancez avec --nouveaux-mots-de-passe pour en générer d’autres.',
        'citoyen' => 'numéro de téléphone, code reçu par SMS (page « SMS envoyés »)',
        'backoffice' => 'adresse, mot de passe, puis double authentification à la première connexion',
        'nominatif_invalide' => 'Compte nominatif : indiquez --testeur="Nom Prénom" (3 caractères au moins) et --profil parmi :profils.',
    ],
    'reinitialiser' => [
        'confirmation' => 'La recette va être remise à zéro : la base « :base » est effacée puis recréée, les fichiers déposés sont supprimés. Pour confirmer, tapez le nom de la base',
        'annulee' => 'Remise à zéro annulée : le nom saisi ne correspond pas à la base « :base ».',
        'sans_interaction' => 'Remise à zéro refusée sans confirmation : relancez-la sans --no-interaction.',
        'fichiers' => 'Fichiers déposés supprimés.',
        'terminee' => 'Recette remise à zéro.',
    ],
];
