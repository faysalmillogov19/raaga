# Guide de recette de la plateforme Raaga

*Tests du comité technique Raaga, novembre 2026*

## Avant de commencer

La recette se déroule sur une plateforme de test, séparée de la production. Un bandeau rouge « ENVIRONNEMENT DE TEST » s'affiche en haut de chaque page. Si ce bandeau n'apparaît pas, arrêtez le test et prévenez l'équipe technique de la plateforme Raaga.

Les données saisies sont fictives. Aucun paiement réel n'est effectué : un simulateur propose de valider ou de refuser chaque paiement. Aucun SMS n'est envoyé : les messages s'affichent sur la page « SMS envoyés » de la plateforme de test.

Chaque testeur reçoit de l'équipe technique de la plateforme Raaga, en main propre ou par un canal sûr, la liste des comptes de test :

- un compte du back-office à son nom, pour le profil qu'il teste : super-administrateur, administrateur, validateur, régulateur, gestionnaire des sites, contrôleur des paiements ou agent de guichet. Le journal de la plateforme identifie ainsi chaque testeur ;
- trois comptes citoyens : une personne physique avec IFU, une personne physique sans IFU, une personne morale.

Les comptes du back-office demandent une double authentification. À la première connexion, scannez le QR code avec une application d'authentification, puis conservez les codes de secours. Les citoyens se connectent avec leur numéro de téléphone et le code affiché sur la page « SMS envoyés ». Cette page demande le mot de passe commun remis aux testeurs ; elle ne donne aucun accès au back-office.

L'équipe technique de la plateforme Raaga peut remettre la plateforme de test à zéro sur demande. Toutes les données saisies sont alors effacées et de nouveaux comptes sont remis aux testeurs.

## Comment signaler une anomalie

Une anomalie est un écart entre le résultat obtenu et le résultat attendu. Pour chaque anomalie, remplissez une fiche avec :

- le numéro du scénario ;
- le profil utilisé ;
- la date et l'heure ;
- les étapes suivies ;
- le résultat attendu et le résultat obtenu ;
- une capture d'écran.

Envoyez la fiche, avec sa capture d'écran, à l'équipe technique de la plateforme Raaga, à l'adresse : **[à compléter]**. Une anomalie qui bloque la suite des tests est signalée le jour même.

## Scénarios

Chaque scénario se termine par le résultat attendu. Notez pour chacun « Réussi » ou « Échec ».

### S1. Connexion d'un citoyen

Profil : citoyen, personne physique avec IFU.

1. Ouvrez la page de connexion et saisissez le numéro de téléphone.
2. Ouvrez la page « SMS envoyés » et relevez le code reçu.
3. Saisissez le code.

Résultat attendu : l'espace « Mon espace » s'ouvre au nom du citoyen.

### S2. Connexion au back-office

Profil : chacun des sept comptes du back-office.

1. Ouvrez la page de connexion du back-office.
2. Saisissez l'adresse et le mot de passe.
3. À la première connexion, enrôlez l'application d'authentification et conservez les codes de secours.
4. Aux connexions suivantes, saisissez le code à six chiffres.

Résultat attendu : le tableau de bord s'ouvre avec le menu propre au profil. Un code faux est refusé.

### S3. Création d'un compte citoyen

Profil : visiteur non connecté.

1. Créez un compte de personne physique avec un nouveau numéro de téléphone.
2. Recommencez avec une personne morale : raison sociale, forme juridique, IFU de l'entreprise.

Résultat attendu : chaque compte est créé et confirmé par un code SMS. L'IFU d'une personne morale est demandé dès la création.

### S4. Vérification d'identité

Profil : citoyen sans pièce validée.

1. Ouvrez « Vérifier mon identité ».
2. Déposez le recto et le verso de la pièce, le selfie, le numéro et la date d'expiration de la pièce.

Résultat attendu : le dossier passe « en cours de vérification ». Une pièce illisible ou d'un format refusé affiche un message clair.

### S5. Traitement d'un dossier d'identité

Profil : administrateur.

1. Ouvrez le dossier déposé au scénario S4.
2. Marquez-le conforme en saisissant la date d'expiration lue sur la pièce.
3. Sur un autre dossier, marquez-le douteux avec un motif.

Résultat attendu : le dossier conforme passe « validé ». Le dossier douteux devient une proposition d'annulation, que le validateur confirme ou rejette.

### S6. Groupe sanguin et justificatif

Profil : citoyen, puis administrateur.

1. Dans « Vérifier mon identité », rubrique « Groupe sanguin », choisissez un groupe, cochez l'accord et déposez un justificatif.
2. En administrateur, ouvrez le dossier et validez le justificatif.
3. En citoyen, changez de groupe sans déposer de nouveau justificatif.

Résultat attendu : le groupe n'est imprimé sur la carte qu'après la validation du justificatif. Après le changement de groupe, il n'est plus imprimé tant qu'un nouveau justificatif n'est pas validé.

### S7. Recherche et réservation d'une boutique

Profil : citoyen, personne physique avec IFU.

1. Ouvrez le plan des boutiques et choisissez un site, un niveau et un bloc.
2. Ouvrez une boutique disponible et lisez son récapitulatif : surface, loyer, caution, avance.
3. Remplissez la réservation et confirmez.

Résultat attendu : la boutique est pré-réservée au nom du citoyen. Les informations déjà connues ne sont pas redemandées. Une troisième boutique est refusée avec le message « Vous ne pouvez pas être attributaire de plus de deux boutiques. ».

### S8. Liste d'attente

Profil : deux citoyens.

1. Le premier citoyen réserve une boutique libre.
2. Le second ouvre la même boutique et s'inscrit.

Résultat attendu : le second voit le nombre de personnes déjà inscrites, sans aucun nom, et obtient un rang dans la liste d'attente.

### S9. Paiement de la caution

Profil : citoyen dont la boutique est pré-réservée.

1. Ouvrez la réservation et payez la caution par un moyen de paiement mobile.
2. Sur le simulateur, choisissez « échec », puis recommencez et choisissez « succès ».

Résultat attendu : l'échec laisse la caution à payer. Le succès passe la boutique « réservée » et délivre un reçu téléchargeable.

### S10. Paiement par virement avec preuve

Profil : citoyen, puis contrôleur des paiements.

1. Choisissez le virement, puis déposez une preuve de versement.
2. En contrôleur des paiements, marquez la preuve reçue, incomplète ou rejetée.

Résultat attendu : chaque décision met à jour le statut du paiement, visible du citoyen dans « Mon espace ».

### S11. Avance et attribution

Profil : citoyen, puis validateur.

1. Payez l'avance de la boutique réservée.
2. En validateur, ouvrez « Attributions à faire », saisissez la date du contrat et attribuez.

Résultat attendu : la boutique passe « attribuée » et la carte de commerçant est émise.

### S12. Carte de commerçant

Profil : citoyen attributaire.

1. Ouvrez la carte dans « Mon espace » et téléchargez le PDF.
2. Scannez le QR code avec un téléphone.

Résultat attendu : la carte porte l'intitulé « CARTE D'IDENTIFICATION RAAGA », le type de carte, le numéro IFU, la photo, le site et les dates. Aucun texte n'est masqué par la photo. Le QR code ouvre la page de vérification, qui confirme que la carte est valide.

### S13. Guichet

Profil : agent de guichet.

1. Recherchez la carte du scénario S12 par son numéro, puis par son code de contrôle.
2. Vérifiez la pièce d'identité du titulaire et imprimez la carte.

Résultat attendu : la carte est retrouvée par les deux recherches. L'impression n'est possible qu'après la case de vérification de la pièce.

### S14. Campagne et grille tarifaire

Profil : régulateur, puis validateur.

1. En régulateur, saisissez une grille tarifaire et soumettez-la.
2. En validateur, confirmez-la, puis rejetez une autre saisie avec un motif.
3. En régulateur, préparez une campagne de boutiques et soumettez-la.

Résultat attendu : rien n'est en vigueur sans la confirmation du validateur. Un rejet revient au régulateur avec son motif.

### S15. Paramètres d'administration

Profil : super-administrateur.

1. Ajoutez un secteur d'activité, renommez-le, puis désactivez-le.
2. Ajoutez un guichet agréé, puis désactivez-le.

Résultat attendu : le secteur désactivé n'est plus proposé à la réservation. Le guichet désactivé disparaît de la page publique « Trouver un guichet ». Aucun autre profil n'accède à ces écrans.

### S16. Droits des profils

Profil : chacun des comptes du back-office.

1. Pour chaque profil, parcourez le menu.
2. Tentez d'ouvrir un écran réservé à un autre profil en saisissant son adresse.

Résultat attendu : chaque profil ne voit que ses rubriques. L'accès à un écran d'un autre profil est refusé.

### S17. Recherche d'une boutique par budget

Profil : visiteur ou citoyen.

1. Ouvrez la page « Obtenir une boutique » et relevez, dans le tableau « Coût à l'entrée (caution + avance) par zone », le minimum et le maximum d'une zone.
2. Dans « Rechercher une boutique », saisissez un budget maximum compris entre ces deux montants, choisissez la zone, puis lancez la recherche.
3. Ajoutez un niveau et une surface minimum, puis relancez.
4. Choisissez « Effacer les filtres ».

Résultat attendu : seules les boutiques dont le total à l'entrée ne dépasse pas le budget sont listées, de la moins chère à la plus chère. Chaque boutique affiche côte à côte le total à l'entrée et le loyer mensuel, ainsi que le nombre de personnes en liste d'attente, sans aucun nom. Les montants sont ceux de la grille tarifaire confirmée. Après « Effacer les filtres », le plan s'affiche sans liste de résultats.

### S18. Réservation sans IFU et récépissé de préinscription

Profil : citoyen, personne physique sans IFU, puis agent de guichet et administrateur.

1. Réservez une boutique sans renseigner de numéro IFU.
2. Dans « Mon espace › Mes boutiques », lisez le bloc « Dossier en cours de formalisation » et téléchargez le récépissé.
3. Scannez le QR code du récépissé avec un téléphone.
4. En agent de guichet, recherchez le récépissé par son numéro et imprimez-le.
5. Déposez ensuite l'IFU et son attestation dans « Vérifier mon identité » ; en administrateur, validez le dossier.

Résultat attendu : la réservation est acceptée sans IFU. Le récépissé porte un numéro propre, la boutique, le site, la date et la date limite de formalisation. Le QR code ouvre une page « Récépissé authentique » sans nom ni numéro de pièce. La carte de commerçant n'est émise qu'après la validation de l'IFU.

### S19. Greffes du RCCM

Profil : citoyen, administrateur, puis super-administrateur.

1. En citoyen, dans « Vérifier mon identité », déclarez un RCCM en choisissant « Autre (préciser) » et en saisissant un greffe absent de la liste.
2. En administrateur, ouvrez « Greffes du RCCM ».
3. En super-administrateur, ouvrez le même écran et choisissez « Ajouter à la liste » pour ce greffe.

Résultat attendu : l'administrateur voit le greffe saisi en « Autre » sans pouvoir modifier la liste. Après l'ajout, le greffe figure dans la liste et le compte du citoyen y est rattaché.
