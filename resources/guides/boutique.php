<?php

/*
 * Guide « Obtenir une boutique » (rubrique Guides, 10/11/2026). Contenu rédigé ici, relu comme le code ; les captures
 * sont refaites par « php artisan raaga:guides » (poste de développement) et la page statique régénérée.
 * Chaque étape nomme l'écran concerné (nom de route, contrôlé par les tests) et ses captures (public/guides/boutique/).
 */

return [
    'slug' => 'boutique',
    'titre' => 'Obtenir une boutique du marché Raaga',
    'resume' => 'Du compte citoyen à la carte de commerçant : créer son compte, faire vérifier son identité, préréserver une boutique, payer la caution et l’avance, se formaliser avec le récépissé, puis recevoir sa carte.',
    'public' => 'Personne physique, sans IFU ni RCCM au départ.',
    'etapes' => [
        [
            'id' => 'compte',
            'titre' => 'Créer votre compte',
            'ecran' => 'inscription',
            'gestes' => [
                'Saisissez votre numéro de téléphone, puis le code reçu par SMS.',
                'Indiquez que vous êtes une personne physique, puis vos nom, prénoms et date de naissance, tels qu’inscrits sur votre pièce d’identité.',
                'Tapez les premières lettres de votre commune de résidence et choisissez-la dans les suggestions.',
            ],
            'resultat' => 'Votre compte est créé. Vous êtes connecté à votre espace.',
            'captures' => [
                ['fichier' => 'compte-telephone', 'legende' => 'Votre numéro de téléphone.'],
                ['fichier' => 'compte-code', 'legende' => 'Le code reçu par SMS.'],
                ['fichier' => 'compte-identite', 'legende' => 'Votre identité ; la commune se choisit dans les suggestions.'],
            ],
        ],
        [
            'id' => 'recherche',
            'titre' => 'Trouver une boutique',
            'ecran' => 'boutiques',
            'gestes' => [
                'Ouvrez « Boutiques » et choisissez un site.',
                'Comparez le coût à l’entrée (caution + avance) de chaque zone.',
                'Filtrez selon votre budget, la zone, le niveau ou la surface, puis ouvrez une boutique.',
            ],
            'resultat' => 'Chaque boutique affiche le total à l’entrée, le loyer mensuel et le nombre de personnes déjà en liste d’attente.',
            'captures' => [
                ['fichier' => 'recherche', 'legende' => 'Recherche selon votre budget.'],
            ],
        ],
        [
            'id' => 'identite-avant',
            'titre' => 'Commencer par la vérification d’identité',
            'ecran' => 'boutiques.boutique',
            'gestes' => [
                'Sur la fiche de la boutique, lisez le récapitulatif des montants.',
                'Choisissez « Vérifier mon identité » : la préréservation n’est possible qu’après votre demande de vérification.',
            ],
            'resultat' => 'Une fois votre demande envoyée, vous revenez directement sur cette boutique.',
            'captures' => [
                ['fichier' => 'identite-avant', 'legende' => 'Fiche de la boutique : vérifiez d’abord votre identité.'],
            ],
        ],
        [
            'id' => 'verification',
            'titre' => 'Déposer votre demande de vérification d’identité',
            'ecran' => 'espace.verification',
            'gestes' => [
                'Choisissez votre pièce : CNIB, CIB-AES ou passeport. Déposez le recto, le verso et un selfie.',
                'Saisissez le NIP de votre CNIB (17 chiffres), la date d’expiration, votre sexe et votre lieu de naissance.',
                'À la question « Votre activité est-elle déjà formalisée ? », répondez « Oui » si vous avez votre IFU et votre RCCM : ils vous sont alors demandés avec leurs justificatifs. Sinon, répondez « Non, pas encore ».',
            ],
            'resultat' => 'Votre dossier passe « en attente de vérification ». Avec « Non, pas encore », la démarche à suivre s’affiche : Raaga vous délivrera un récépissé à la préréservation.',
            'captures' => [
                ['fichier' => 'verification-pieces', 'legende' => 'Votre pièce d’identité et votre selfie.'],
                ['fichier' => 'formalisation-oui', 'legende' => 'Activité formalisée : IFU et RCCM demandés.'],
                ['fichier' => 'formalisation-non', 'legende' => 'Pas encore formalisée : la démarche à suivre.'],
            ],
        ],
        [
            'id' => 'prereservation',
            'titre' => 'Préréserver la boutique',
            'ecran' => 'boutiques.boutique',
            'gestes' => [
                'Votre pièce d’identité est reprise de votre compte : rien à redéposer.',
                'Choisissez votre activité principale et indiquez la personne à prévenir.',
                'Cochez les trois engagements et confirmez.',
            ],
            'resultat' => 'La boutique est préréservée à votre nom pendant le délai de paiement de la caution, affiché sur la page de la campagne : la page de paiement de la caution s’ouvre.',
            'captures' => [
                ['fichier' => 'prereservation-formulaire', 'legende' => 'Le formulaire de préréservation.'],
                ['fichier' => 'caution-a-payer', 'legende' => 'Préréservée : la caution est à payer dans le délai.'],
            ],
        ],
        [
            'id' => 'caution',
            'titre' => 'Payer la caution',
            'ecran' => 'boutiques.reservation.show',
            'gestes' => [
                'Choisissez votre moyen de paiement, par exemple Orange Money.',
                'Validez la demande de paiement sur votre téléphone. Saisissez votre code secret uniquement sur votre téléphone.',
            ],
            'resultat' => 'La boutique est « Réservée : caution payée ». Le reçu de la caution se télécharge en PDF.',
            'captures' => [
                ['fichier' => 'caution-operateur', 'legende' => 'En attente de votre validation sur le téléphone.'],
                ['fichier' => 'caution-payee', 'legende' => 'Caution payée : la boutique est réservée.'],
            ],
        ],
        [
            'id' => 'avance',
            'titre' => 'Payer l’avance',
            'ecran' => 'boutiques.avance.show',
            'gestes' => [
                'Ouvrez votre réservation et payez l’avance de loyer, comme la caution.',
            ],
            'resultat' => 'La boutique est « Réservée : avance payée ». Elle vous sera attribuée à la signature du contrat.',
            'captures' => [
                ['fichier' => 'avance-payee', 'legende' => 'Avance payée : les deux reçus se téléchargent.'],
            ],
        ],
        [
            'id' => 'recepisse',
            'titre' => 'Votre récépissé de préinscription',
            'ecran' => 'espace',
            'gestes' => [
                'Dans « Mon espace › Mes boutiques », téléchargez votre récépissé de préinscription.',
                'Présentez-le avec votre pièce d’identité pour demander votre IFU et votre RCCM selon la procédure allégée.',
            ],
            'resultat' => 'Le bloc « Dossier en cours de formalisation » rappelle ce qu’il vous reste à déposer et la date limite.',
            'captures' => [
                ['fichier' => 'mes-boutiques-recepisse', 'legende' => 'Mes boutiques : dossier en cours de formalisation et récépissé.'],
            ],
        ],
        [
            'id' => 'formalisation',
            'titre' => 'Déposer votre IFU et votre RCCM',
            'ecran' => 'espace.verification',
            'gestes' => [
                'Dans « Vérifier mon identité », rubrique « Votre IFU », indiquez votre numéro IFU et joignez votre attestation.',
                'Rubrique « Registre du commerce (RCCM) » : numéro, date d’immatriculation, greffe et extrait du registre.',
            ],
            'resultat' => 'Raaga vérifie vos justificatifs. Votre carte de commerçant est émise une fois votre IFU vérifié.',
            'captures' => [
                ['fichier' => 'votre-ifu', 'legende' => 'Votre IFU et son attestation.'],
                ['fichier' => 'votre-rccm', 'legende' => 'Votre registre du commerce.'],
            ],
        ],
        [
            'id' => 'attribution',
            'titre' => 'La boutique vous est attribuée',
            'ecran' => 'espace',
            'gestes' => [
                'Raaga vérifie votre identité, puis vous attribue la boutique à la signature du contrat.',
            ],
            'resultat' => 'Dans « Mes boutiques », la boutique porte le statut « Attribuée ». Une notification annonce votre carte de commerçant.',
            'captures' => [
                ['fichier' => 'boutique-attribuee', 'legende' => 'Mes boutiques : boutique attribuée.'],
            ],
        ],
        [
            'id' => 'carte',
            'titre' => 'Votre carte de commerçant Raaga',
            'ecran' => 'espace.carte',
            'gestes' => [
                'Ouvrez « Ma carte Raaga », onglet « Commerçant ».',
                'Affichez-la en plein écran ou téléchargez-la en PDF ou en image.',
            ],
            'resultat' => 'Votre carte porte votre numéro Raaga (votre IFU), votre activité, votre site et un QR code de vérification.',
            'captures' => [
                ['fichier' => 'carte', 'legende' => 'La carte de commerçant, recto et verso.'],
            ],
        ],
    ],
    'aSavoir' => [
        'Deux boutiques au plus par personne, toutes campagnes confondues.',
        'Une boutique déjà préréservée accepte des inscriptions en liste d’attente : si la caution n’est pas payée dans le délai, la boutique est proposée au suivant, prévenu par SMS.',
        'Sans IFU ou sans RCCM, vous disposez de :mois mois après votre première préréservation pour les déposer. Des rappels vous sont envoyés par SMS.',
    ],
];
