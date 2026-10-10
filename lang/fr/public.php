<?php

/*
 * Textes des pages publiques. Un fichier par langue (lang/{locale}/public.php)
 * pour préparer le mooré, le dioula et le fulfuldé.
 */

return [

    // Nom, sous-titre, devise, contacts… : paramètres de la plateforme (back-office), jamais ici.
    'site' => [
        'pays' => 'BURKINA FASO',
        'armoiries' => 'Armoiries du Burkina Faso',
        'demonstration' => 'Environnement de démonstration — données fictives',
        'logo_alt' => 'Raaga – Participation populaire, accueil',
        'aller_au_contenu' => 'Aller au contenu',
    ],

    'bandeau' => [
        'en_savoir_plus' => 'En savoir plus',
    ],

    'contact' => [
        'international' => 'Depuis l’étranger',
        'whatsapp' => 'WhatsApp',
        'siege' => 'Siège et accueil',
        'accueil' => 'Accueil du public : :horaires',
        'carte' => 'Voir sur la carte',
    ],

    'maintenance' => [
        'titre' => 'Site en maintenance',
        'fin' => 'Retour prévu le :date.',
        'merci' => 'Merci de votre patience.',
    ],

    'erreurs' => [
        'code' => 'Erreur :code',
        'accueil_alt' => ':nom — retour à l’accueil',
        'retour_accueil' => 'Retour à l’accueil',
        'aide' => 'Aide & FAQ',
        '404' => [
            'titre' => 'Page introuvable',
            'message' => 'La page demandée n’existe pas ou a été déplacée. Vérifiez l’adresse ou repartez de l’accueil.',
        ],
        '403' => [
            'titre' => 'Accès refusé',
            'message' => 'Vous n’avez pas l’autorisation d’accéder à cette page.',
        ],
        '419' => [
            'titre' => 'Session expirée',
            'message' => 'Votre session a expiré. Reconnectez-vous pour continuer.',
        ],
        '500' => [
            'titre' => 'Une erreur est survenue',
            'message' => 'Le service rencontre un problème technique. Réessayez dans quelques instants.',
        ],
        '503' => [
            'message' => 'Le site est momentanément indisponible pour une opération de maintenance.',
        ],
    ],

    'tableau' => [
        'titre' => 'Tableau de bord',
        'bienvenue' => 'Bienvenue, :nom.',
        'aucun_service' => 'Aucun service n’est encore proposé pour ce profil.',
        'guichet_titre' => 'Interface guichet en cours de mise en place',
        'guichet_message' => 'Les outils des agents de guichet (inscription, souscription et encaissement pour le compte d’un citoyen) ne sont pas encore disponibles.',
    ],

    'entete' => [
        'aide' => 'Aide & FAQ',
        'mon_espace' => 'Mon espace',
        'se_deconnecter' => 'Se déconnecter',
        'mon_profil' => 'Mon profil',
        'menu_compte' => 'Menu de mon compte',
        'menu' => 'Menu',
        'ouvrir_menu' => 'Ouvrir le menu',
        'fermer_menu' => 'Fermer le menu',
    ],

    'menu' => [
        'libelle' => 'Menu principal',
        'accueil' => 'Accueil',
        'offre' => 'Comprendre l’offre',
        'souscrire' => 'Souscrire',
        'transparence' => 'Transparence',
        'espace' => 'Mon espace',
        'faq' => 'Aide & FAQ',
    ],

    'fil' => [
        'libelle' => 'Fil d’Ariane',
    ],

    'pied' => [
        'presentation' => 'La souscription populaire ouvre à tous les citoyens burkinabè le capital du marché Raaga.',
        'anti_fraude' => 'Attention aux fraudes :',
        'reseaux' => 'Réseaux sociaux',
        'liens_officiels' => 'Liens officiels',
        'decouvrir' => 'Découvrir',
        'mon_compte' => 'Mon compte',
        'creer_compte' => 'Créer un compte',
        'mon_espace' => 'Mon espace',
        'souscrire' => 'Souscrire',
        'assistance' => 'Assistance',
        'aide' => 'Aide & FAQ',
        'guichets' => 'Guichets agréés',
        'contact' => 'Nous contacter',
        'mentions' => 'Mentions légales',
        'donnees' => 'Données personnelles',
        'accessibilite' => 'Accessibilité',
        'liens_legaux' => 'Informations légales',
    ],

    'jauge' => [
        'titre' => 'Collecte de la :tranche',
        'souscrit' => 'Montant souscrit',
        'objectif' => 'Objectif : :objectif',
        'progression' => 'Progression de la collecte',
        'atteint' => ':pct de l’objectif atteint',
        'souscripteurs' => 'souscripteurs',
        'actions' => 'actions',
        'restants' => 'restants',
        'jours' => ':n j',
        'cloturee' => 'clôturée',
        'suivre' => 'Suivre la collecte en détail',
    ],

    'statut' => [
        'en_attente' => 'En attente',
        'valide' => 'Validé',
        'alloue' => 'Alloué',
    ],

    'accueil' => [
        'titre_page' => 'Accueil',
        'hero' => [
            'surtitre' => ':tranche · ouverte jusqu’au :date',
            'surtitre_fermee' => 'Participation populaire',
            'titre' => 'J’investis à partir de :prix dans Raaga et je participe à ses bénéfices',
            'titre_ferme' => 'Devenez actionnaire du marché Raaga',
            'texte' => 'Tout citoyen burkinabè peut devenir actionnaire du marché Raaga. Chaque action coûte :prix, :plafond. Vous recevez une part des bénéfices selon le nombre d’actions que vous détenez.',
            'sans_plafond' => 'sans plafond',
            'avec_plafond' => 'dans la limite de :plafond actions par personne',
            'texte_ferme' => 'Aucune tranche n’est ouverte à la souscription pour le moment. Les dates de la prochaine tranche seront annoncées sur cette page.',
            'souscrire' => 'Je souscris maintenant',
            'comprendre' => 'Comprendre l’offre',
            'suivre' => 'Suivre la collecte en détail',
        ],
        'chiffres' => [
            'titre' => 'Raaga en chiffres',
            'prix' => 'prix d’une action populaire',
            'aucun_plafond' => 'Aucun plafond',
            'aucun_plafond_detail' => 'de souscription par personne',
            'plafond' => ':n actions',
            'plafond_detail' => 'maximum par personne',
            'boutiques_detail' => 'boutiques dans le futur marché',
            'cout_detail' => 'FCFA de coût total du projet',
        ],
        'etapes' => [
            'surtitre' => 'Comment ça marche',
            'titre' => 'Souscrire en 4 étapes',
            'intro' => 'Depuis votre téléphone ou dans un guichet agréé, en moins de 10 minutes.',
            'liste' => [
                ['titre' => 'Je crée mon compte', 'texte' => 'Avec mon numéro de téléphone, puis je dépose ma CNIB ou mon passeport et un selfie.'],
                ['titre' => 'Je choisis mes actions', 'texte' => 'J’indique le nombre d’actions souhaité et je lis les conditions de la tranche.'],
                ['titre' => 'Je paie', 'texte' => 'Par Orange Money, Moov Money, virement bancaire ou en espèces au guichet.'],
                ['titre' => 'Je deviens actionnaire', 'texte' => 'Après l’allocation, je reçois mon attestation d’actionnaire dans mon espace.'],
            ],
        ],
        'regles' => [
            'surtitre' => 'À savoir avant de souscrire',
            'titre' => 'Deux règles importantes',
            'boutique_titre' => 'Aucun droit à une boutique',
            // Texte avec balises <strong> : affiché sans échappement (contenu maîtrisé).
            'boutique_texte' => 'Être actionnaire de Raaga, quel que soit le nombre d’actions, <strong>ne donne aucun droit ni aucune priorité</strong> pour obtenir une boutique, un magasin ou un emplacement dans le marché. L’attribution des boutiques suit une procédure distincte.',
            'rendement_titre' => 'Un rendement non garanti',
            'rendement_texte' => 'Les citoyens reçoivent une part des <strong>bénéfices réellement distribuables</strong> de Raaga, après charges, entretien, impôts et réserves. Il ne s’agit pas d’un taux d’intérêt fixe : les montants peuvent varier d’une année à l’autre.',
        ],
        'financement' => [
            'surtitre' => 'Le projet',
            'titre' => 'Qui finance Raaga ?',
            'intro' => 'Le projet est financé par :n sources. Ensemble, les commerçants et la population couvrent environ :pct % du coût total.',
            'nombres' => [1 => 'une', 2 => 'deux', 3 => 'trois', 4 => 'quatre', 5 => 'cinq', 6 => 'six'],
            'environ' => '≈ :montant',
            'note' => 'Montants en FCFA. Total : ≈ :total milliards FCFA.',
        ],
        'actualites' => [
            'surtitre' => 'Actualités',
            'titre' => 'Les dernières informations',
            'lire' => 'Lire',
            'lire_sr' => 'Lire l’actualité : :titre',
        ],
        'faq' => [
            'surtitre' => 'Questions fréquentes',
            'titre' => 'Vous vous demandez…',
            'toutes' => 'Toutes les questions',
            'questions' => [
                [
                    'q' => 'Qui peut souscrire ?',
                    'r' => 'Tout citoyen burkinabè muni d’une CNIB ou d’un passeport valide, résidant au Burkina Faso ou membre de la diaspora, selon les publics autorisés par le règlement de la tranche.',
                ],
                [
                    'q' => 'Combien puis-je investir ?',
                    'r' => 'À partir d’une seule action. Le prix de l’action et l’éventuel plafond par personne sont fixés par le règlement de la tranche.',
                ],
                [
                    'q' => 'Quand vais-je recevoir des dividendes ?',
                    'r' => 'Après l’ouverture du marché, lorsque Raaga dégagera des bénéfices distribuables et que l’assemblée générale décidera d’en verser une part. Aucune date ni aucun montant ne sont garantis.',
                ],
            ],
        ],
    ],

    'communs' => [
        'je_souscris' => 'Je souscris',
        'bientot_disponible' => 'Bientôt disponible',
        'telecharger' => 'Télécharger',
        'pdf' => 'PDF',
        'retour_accueil' => 'Retour à l’accueil',
        'besoin_aide' => 'Besoin d’aide ?',
        'numero_vert' => 'Numéro vert gratuit',
        'non_communique' => 'Communiqué prochainement',
        'aucune_tranche' => 'Aucune tranche n’est ouverte pour le moment.',
    ],

    'documents' => [
        'titre' => 'Documents à lire',
        'reglement' => 'Règlement de la tranche',
        'reglement_detail' => 'Généré à partir des paramètres de la tranche',
        'note_information' => 'Note d’information',
        'statuts' => 'Statuts de Raaga SA',
        'guide' => 'Guide du souscripteur',
    ],

    'offre' => [
        'titre' => 'Comprendre l’offre',
        'intro' => 'Tout ce qu’il faut savoir avant de devenir actionnaire du marché Raaga : ce que vous achetez, ce que vous obtenez, et ce que vous n’obtenez pas.',
        'action' => [
            'titre' => 'Qu’est-ce qu’une action populaire ?',
            'texte' => 'Une action est une part du capital de la société Raaga SA, qui construit et exploite le marché Raaga. En achetant des actions, vous devenez copropriétaire de la société.',
            'a_titre' => 'Actions A',
            'a_texte' => 'Détenues par les actionnaires de référence de Raaga SA. Elles ne sont pas proposées au public.',
            'b_titre' => 'Actions B',
            'b_texte' => 'Ouvertes aux citoyens burkinabè dans le cadre de la participation populaire. Ce sont les actions proposées sur cette plateforme.',
        ],
        'obtenez' => [
            'titre' => 'Ce que vous obtenez',
            'liste' => [
                'Une part des bénéfices distribués (dividendes), proportionnelle au nombre d’actions que vous détenez.',
                'Une attestation d’actionnaire, disponible dans votre espace après l’allocation.',
                'Votre inscription au registre officiel des actions B.',
                'Une information régulière sur la collecte, le chantier puis les résultats du marché.',
            ],
        ],
        'obtenez_pas' => [
            'titre' => 'Ce que vous n’obtenez pas',
            'alerte' => 'Lisez attentivement',
            'liste' => [
                'Aucun droit ni aucune priorité pour obtenir une boutique ou un emplacement dans le marché.',
                'Aucun rendement garanti : les dividendes dépendent des bénéfices futurs de Raaga.',
                'Aucune cotation à la BRVM : les actions B ne s’achètent ni ne se vendent en bourse.',
            ],
        ],
        'benefices' => [
            'titre' => 'D’où viendront les bénéfices ?',
            'texte' => 'Une fois le marché ouvert, Raaga SA percevra les loyers et redevances des :boutiques boutiques et des autres services du marché. Après paiement des charges d’exploitation et des remboursements d’emprunts, l’assemblée générale des actionnaires décide chaque année de la part des bénéfices distribuée en dividendes.',
        ],
        'calendrier' => [
            'titre' => 'Calendrier',
            'fait' => 'Terminé',
            'en_cours' => 'En cours',
            'a_venir' => 'À venir',
            'etapes' => [
                'ouverture' => 'Ouverture de la souscription',
                'cloture' => 'Clôture de la souscription',
                'allocation' => 'Répartition des actions et remboursements',
                'attestations' => 'Remise des attestations d’actionnaire',
                'marche' => 'Ouverture du marché',
            ],
            'apres_cloture' => 'Après la clôture',
            'apres_allocation' => 'Après l’allocation',
            'a_annoncer' => 'Date annoncée ultérieurement',
        ],
        'exemple' => [
            'titre' => 'Exemple chiffré',
            'achat' => 'Awa achète :nb actions à :prix l’une : elle paie :total.',
            'dividende' => 'Si, une année, l’assemblée générale décidait de verser un dividende de :dividende par action (montant fictif, pour l’exemple), Awa recevrait :nb × :dividende = :gain.',
            'avertissement' => 'Cet exemple est purement illustratif : aucun montant de dividende n’est promis.',
        ],
        'encart' => [
            'pret' => 'Prêt à souscrire ?',
            'texte' => 'La souscription prend quelques minutes depuis votre téléphone.',
        ],
    ],

    'faq' => [
        'titre' => 'Aide & FAQ',
        'intro' => 'Les réponses aux questions les plus fréquentes sur la souscription, vos droits et la sécurité.',
        'recherche' => 'Rechercher une question',
        'recherche_aide' => 'Saisissez un mot, par exemple « boutique » ou « remboursement ».',
        'aucun_resultat' => 'Aucune question ne correspond à votre recherche.',
        'groupes' => [
            'souscrire' => [
                'titre' => 'Souscrire',
                'questions' => [
                    ['q' => 'Qui peut souscrire aux actions Raaga ?', 'r' => 'Tout citoyen burkinabè muni d’une CNIB ou d’un passeport valide, résidant au Burkina Faso ou membre de la diaspora, selon les publics autorisés par le règlement de la tranche.'],
                    ['q' => 'Combien coûte une action ?', 'r' => 'Le prix d’une action est fixé par le règlement de chaque tranche. Il est affiché sur la page d’accueil et dans le règlement.'],
                    ['q' => 'Comment payer ?', 'r' => 'Par Orange Money, Moov Money, virement bancaire ou en espèces dans un guichet agréé, selon les moyens ouverts pour la tranche.'],
                    ['q' => 'Combien de temps ai-je pour payer ?', 'r' => 'Votre souscription est réservée pendant le délai indiqué dans le règlement de la tranche. Passé ce délai, elle expire automatiquement et vous pouvez en refaire une.'],
                    ['q' => 'Que se passe-t-il si la tranche est sursouscrite ?', 'r' => 'Les actions sont réparties selon la méthode indiquée dans le règlement de la tranche. Les sommes payées pour des actions non attribuées vous sont remboursées.'],
                ],
            ],
            'droits' => [
                'titre' => 'Mes droits',
                'questions' => [
                    ['q' => 'Est-ce que j’obtiens une boutique en achetant des actions ?', 'r' => 'Non. Les actions donnent droit à une part des bénéfices de Raaga SA, jamais à une boutique ni à une priorité d’attribution.'],
                    ['q' => 'Le rendement est-il garanti ?', 'r' => 'Non. Les dividendes dépendent des bénéfices futurs de Raaga et des décisions de l’assemblée générale. Aucun montant n’est promis.'],
                    ['q' => 'Quand recevrai-je mon attestation d’actionnaire ?', 'r' => 'Après la clôture de la tranche et la répartition des actions. Elle sera disponible dans votre espace.'],
                    ['q' => 'Puis-je revendre mes actions ?', 'r' => 'Les actions B ne sont pas cotées en bourse. Les modalités de cession seront précisées par Raaga SA ; elles ne sont pas ouvertes pendant la souscription.'],
                ],
            ],
            'securite' => [
                'titre' => 'Sécurité',
                'questions' => [
                    ['q' => 'Comment reconnaître une arnaque ?', 'r' => 'Raaga ne vous demandera jamais votre code secret Mobile Money, ni votre code de connexion, ni de payer sur un numéro personnel. Ne souscrivez que sur cette plateforme officielle ou dans un guichet agréé. Personne ne peut vous vendre une boutique en échange d’actions.'],
                    ['q' => 'Mon argent est-il protégé pendant la souscription ?', 'r' => 'Les fonds sont versés sur un compte séquestre tenu par une banque agréée. Ils ne sont utilisés qu’après la clôture et la répartition des actions.'],
                    ['q' => 'Que deviennent mes pièces d’identité ?', 'r' => 'Elles servent uniquement à vérifier votre identité. Elles sont conservées de façon sécurisée et ne sont consultables que par l’équipe de conformité.'],
                    ['q' => 'Que faire si je pense avoir été victime d’une fraude ?', 'r' => 'Contactez immédiatement l’assistance Raaga et signalez les faits aux services de police ou de gendarmerie.'],
                ],
            ],
        ],
        'contact' => [
            'titre' => 'Nous contacter',
            'horaires' => 'Horaires',
            'courriel' => 'Courriel',
            'guichets_titre' => 'Trouver un guichet',
            'guichets_texte' => 'La liste des guichets agréés sera publiée à l’ouverture de la souscription.',
        ],
    ],

    'transparence' => [
        'titre' => 'Transparence',
        'intro' => 'Les chiffres de la collecte et l’avancement du projet, publiés et mis à jour régulièrement. Données agrégées uniquement : aucune information personnelle n’est publiée.',
        'maj' => 'Données mises à jour toutes les 5 minutes.',
        'collecte' => 'La collecte en chiffres',
        'moyen' => 'Montant moyen par souscripteur',
        'median' => 'Montant médian par souscripteur',
        'median_aide' => 'La moitié des souscripteurs a investi moins que ce montant.',
        'par_canal' => 'Répartition par moyen de paiement',
        'par_montant' => 'Répartition des souscripteurs par montant investi',
        'souscripteurs' => ':n souscripteurs',
        'montant_fcfa' => 'en FCFA',
        'chantier' => 'Avancement du chantier',
        'chantier_vide' => 'Le suivi du chantier sera publié prochainement.',
        'rapports' => 'Rapports publiés',
        'rapports_vide' => 'Aucun rapport publié pour le moment.',
        'apres' => [
            'titre' => 'Après l’ouverture du marché',
            'intro' => 'Ces indicateurs seront publiés chaque année une fois le marché en activité.',
            'occupation' => 'Taux d’occupation des boutiques',
            'recettes' => 'Recettes annuelles',
            'resultat' => 'Résultat net',
            'dividende' => 'Dividende par action',
        ],
    ],

    'actualite' => [
        'titre_rubrique' => 'Actualités',
        'publie_le' => 'Publié le :date',
        'autres' => 'Autres actualités',
    ],

    'preparation' => [
        'titre_alerte' => 'Bientôt disponible',
        'texte' => 'Cette étape de la plateforme est en cours de mise en service. Elle sera accessible à l’ouverture de la souscription en ligne.',
        'en_attendant' => 'En attendant, vous pouvez lire les conditions de l’offre et les réponses aux questions fréquentes.',
        'pages' => [
            'inscription' => ['titre' => 'Créer mon compte', 'intro' => 'Votre numéro de téléphone, un code de confirmation, votre identité et vos pièces : quatre étapes pour créer votre compte.'],
            'souscrire' => ['titre' => 'Souscrire', 'intro' => 'Choisissez le nombre d’actions, lisez et acceptez les conditions de la tranche.'],
            'paiement' => ['titre' => 'Paiement', 'intro' => 'Payez votre souscription par Mobile Money, virement ou en espèces au guichet.'],
            'espace' => ['titre' => 'Mon espace', 'intro' => 'Vos souscriptions, vos documents et vos dividendes.'],
            'verification' => ['titre' => 'Vérifier mon identité', 'intro' => 'Déposez votre CNIB ou votre passeport et un selfie : votre dossier sera vérifié sous 48 heures.'],
        ],
    ],

    'legal' => [
        'en_redaction' => 'Le contenu de cette page est en cours de rédaction et sera publié prochainement.',
        'mentions' => [
            'editeur' => 'Éditeur',
            'denomination' => 'Dénomination sociale',
            'forme' => 'Forme juridique',
            'capital' => 'Capital social',
            'rccm' => 'RCCM',
            'ifu' => 'IFU',
            'siege' => 'Siège',
            'directeur' => 'Directeur de la publication',
            'hebergement' => 'Hébergement',
            'cil' => 'Déclaration à la CIL',
            'autorite' => 'Autorité de marché',
            'contact' => 'Contact',
        ],
        'donnees' => [
            'responsable' => 'Contact pour vos données personnelles',
            'cil' => 'Référence de la déclaration à la Commission de l’informatique et des libertés (CIL) : :reference.',
        ],
        'pages' => [
            'mentions' => 'Mentions légales',
            'donnees' => 'Données personnelles',
            'accessibilite' => 'Accessibilité',
        ],
    ],

    'otp' => [
        // Modèle de SMS : {variables} remplacées à l'envoi ; un segment [ … ] disparaît si une de ses variables est vide.
        'sms' => 'Votre code de confirmation est {code}. Il expire dans {minutes} minutes. Ne le communiquez à personne.[ Aide : {numeroVert}]',
        'erreurs' => [
            'code_incorrect' => 'Code incorrect ou expiré. Vérifiez le SMS reçu ou demandez un nouveau code.',
            'bloque' => 'Trop de tentatives. Pour votre sécurité, réessayez dans :minutes minutes.',
            'renvoi_trop_tot' => 'Patientez quelques secondes avant de demander un nouveau code.',
            'trop_envois' => 'Trop de codes demandés pour ce numéro. Réessayez dans une heure.',
        ],
    ],

    'compte' => [
        'patientez' => 'Patientez…',
        'telephone' => 'Numéro de téléphone',
        'telephone_aide' => 'Numéro burkinabè à 8 chiffres.',
        'recevoir_code' => 'Recevoir mon code',
        'code_envoye' => 'Saisissez le code envoyé au :telephone',
        'modifier_numero' => 'Modifier le numéro',
        'code' => 'Code de confirmation',
        'chiffre' => 'Chiffre :n sur 6',
        'renvoyer' => 'Renvoyer le code',
        'retour' => 'Retour',
        'besoin_aide' => 'Besoin d’aide ?',
        'numero_vert' => 'Numéro vert',
        'aide_faq' => 'Aide & FAQ',
        'erreurs' => [
            'telephone' => 'Saisissez un numéro de téléphone burkinabè à 8 chiffres, par exemple 70 12 34 56.',
            'code' => 'Saisissez les 6 chiffres du code reçu par SMS.',
        ],
        'connexion' => [
            'titre' => 'Se connecter',
            'intro' => 'Accédez à votre espace souscripteur.',
            'bouton' => 'Se connecter',
            'pas_de_compte' => 'Pas encore de compte ?',
            'creer' => 'Créer un compte',
            'inconnu' => 'Aucun compte n’est associé à ce numéro.',
            'desactive' => 'Ce compte a été désactivé. Contactez l’assistance au numéro vert.',
            'espace_securise' => 'Ce compte se connecte depuis son espace dédié, avec e-mail, mot de passe et code d’authentification.',
        ],
        'profil' => [
            'titre' => 'Mon profil',
            'intro' => 'Mettez à jour vos informations personnelles et votre numéro de téléphone.',
            'identite' => 'Mes informations personnelles',
            'identite_aide' => 'Elles doivent correspondre exactement à votre pièce d’identité.',
            'kyc_attention' => 'Votre identité a été vérifiée (ou est en cours de vérification). Si vous modifiez votre nom, vos prénoms ou votre date de naissance, vous devrez la faire vérifier à nouveau avant de souscrire.',
            'email' => 'Adresse électronique (facultative)',
            'enregistrer' => 'Enregistrer mes informations',
            'enregistre' => 'Vos informations ont été enregistrées.',
            'enregistre_kyc' => 'Vos informations ont été enregistrées. Votre identité a changé : faites-la vérifier à nouveau pour pouvoir souscrire.',
            'aucun_changement' => 'Aucune modification à enregistrer.',
            'telephone_titre' => 'Mon numéro de téléphone',
            'telephone_actuel' => 'Numéro actuel : :telephone',
            'telephone_aide' => 'Il sert à vous connecter. Le nouveau numéro sera confirmé par un code SMS.',
            'nouveau_telephone' => 'Nouveau numéro de téléphone',
            'confirmer_telephone' => 'Confirmer le nouveau numéro',
            'annuler' => 'Annuler le changement',
            'telephone_enregistre' => 'Votre numéro de téléphone a été modifié. Utilisez-le désormais pour vous connecter.',
            'role' => 'Profil du compte : :role. Il ne peut pas être modifié par vous-même.',
            'erreurs' => [
                'email' => 'Saisissez une adresse électronique valide.',
                'email_existe' => 'Cette adresse électronique est déjà utilisée par un autre compte.',
                'meme_numero' => 'C’est déjà votre numéro actuel.',
                'numero_pris' => 'Ce numéro est déjà associé à un autre compte.',
            ],
        ],
        'inscription' => [
            'titre' => 'Créer mon compte',
            'intro' => 'Munissez-vous de votre téléphone et de votre CNIB ou passeport.',
            'etapes' => ['telephone' => 'Téléphone', 'code' => 'Code', 'identite' => 'Identité'],
            'etape_courante' => 'Étape :n sur 3 : :libelle',
            'existe' => 'Ce numéro a déjà un compte.',
            'se_connecter' => 'Se connecter',
            'deja' => 'Vous avez déjà un compte ?',
            'continuer' => 'Continuer',
            'identite_intro' => 'Ces informations doivent correspondre exactement à votre pièce d’identité.',
            'nom' => 'Nom',
            'prenoms' => 'Prénom(s)',
            'date_naissance' => 'Date de naissance',
            'pays_residence' => 'Pays de résidence',
            'diaspora' => 'Burkinabè de la diaspora',
            'region' => 'Région',
            'choisir' => 'Choisissez…',
            'conditions' => 'J’accepte les :cgu et la :donnees',
            'cgu' => 'conditions d’utilisation',
            'politique' => 'politique de protection des données personnelles',
            'bouton' => 'Créer mon compte',
            'succes_titre' => 'Votre compte est créé',
            'succes_texte' => 'Pour pouvoir souscrire, il vous reste à faire vérifier votre identité (CNIB ou passeport + selfie).',
            'verifier' => 'Vérifier mon identité maintenant',
            'plus_tard' => 'Plus tard',
            'erreurs' => [
                'nom' => 'Saisissez votre nom tel qu’il figure sur votre pièce d’identité.',
                'prenoms' => 'Saisissez votre ou vos prénoms tels qu’ils figurent sur votre pièce d’identité.',
                'date_naissance' => 'Saisissez une date de naissance valide.',
                'majeur' => 'Vous devez avoir au moins :age ans pour créer un compte.',
                'pays' => 'Choisissez votre pays de résidence.',
                'region' => 'Choisissez votre région.',
                'conditions' => 'Vous devez accepter les conditions d’utilisation pour créer votre compte.',
                'session' => 'Votre vérification a expiré. Recommencez à partir de votre numéro de téléphone.',
            ],
        ],
    ],

    'auth' => [
        'afficher' => 'Afficher',
        'masquer' => 'Masquer',
        'email' => 'Adresse électronique',
        'mot_de_passe' => 'Mot de passe',
        'nouveau_mot_de_passe' => 'Nouveau mot de passe',
        'confirmation' => 'Confirmez le mot de passe',
        'aide_mot_de_passe' => 'Au moins 8 caractères.',
        'retour_connexion' => 'Retour à la connexion',
        'connexion' => [
            'titre' => 'Se connecter',
            'intro' => 'Accédez à votre espace personnel : souscriptions, documents et dividendes.',
            'se_souvenir' => 'Rester connecté sur cet appareil',
            'oubli' => 'Mot de passe oublié ?',
            'bouton' => 'Se connecter',
            'pas_de_compte' => 'Pas encore de compte ?',
            'creer' => 'Créer mon compte',
        ],
        'inscription' => [
            'titre' => 'Créer mon compte',
            'intro' => 'Quelques informations suffisent pour ouvrir votre espace.',
            'nom' => 'Nom et prénom(s)',
            'profil' => 'Je suis',
            'bouton' => 'Créer mon compte',
            'deja' => 'Déjà inscrit ?',
            'connexion' => 'Se connecter',
        ],
        'oubli' => [
            'titre' => 'Mot de passe oublié',
            'intro' => 'Indiquez votre adresse électronique : nous vous enverrons un lien pour choisir un nouveau mot de passe.',
            'bouton' => 'Recevoir le lien',
        ],
        'reinitialisation' => [
            'titre' => 'Choisir un nouveau mot de passe',
            'intro' => 'Saisissez votre adresse électronique et votre nouveau mot de passe.',
            'bouton' => 'Enregistrer le mot de passe',
        ],
        'verification' => [
            'titre' => 'Vérifiez votre adresse électronique',
            'intro' => 'Merci pour votre inscription. Cliquez sur le lien que nous venons de vous envoyer par courriel pour activer votre compte.',
            'texte' => 'Vous n’avez rien reçu ? Vérifiez vos courriers indésirables, ou demandez un nouvel envoi.',
            'envoye' => 'Un nouveau lien de vérification a été envoyé à votre adresse électronique.',
            'renvoyer' => 'Renvoyer le lien',
            'deconnexion' => 'Se déconnecter',
        ],
        'confirmation_mdp' => [
            'titre' => 'Confirmez votre mot de passe',
            'intro' => 'Cette partie de l’espace est protégée. Saisissez à nouveau votre mot de passe pour continuer.',
            'bouton' => 'Confirmer',
        ],
    ],

];
