# Mises à jour à reporter dans la doc du projet

Textes à reporter mot pour mot dans la doc du projet, section par section, dès que le connecteur répondra. Les sections existantes sont complétées ; « Recette » est une section nouvelle.

---

## Section « Migrations » (à ajouter)

Les noms de migration servent à l'ordre, pas au calendrier. Toute nouvelle migration doit être datée après la plus récente du dossier database/migrations, même si sa date dépasse la date du jour.

Contrôles :

- `php artisan raaga:migrations-ordre` liste les migrations datées avant la plus récente du dossier et échoue s'il y en a ;
- le test OrdreMigrationsTest échoue dans le même cas ;
- avant chaque `php artisan migrate`, un avertissement signale toute migration datée avant la plus récente déjà exécutée, avec la commande à lancer.

Au 05/11/2026, la migration la plus récente est `2026_12_07_000000_nom_commercial`.

---

## Section « Mise en production » (à ajouter)

### Planificateur Laravel (tâche cron)

Le serveur doit lancer le planificateur Laravel chaque minute, sous l'utilisateur du serveur web. Tâche cron à installer une fois pour toutes :

```
* * * * * cd /chemin/vers/raaga && php artisan schedule:run >> /dev/null 2>&1
```

Sans cette tâche, rien de ce qui est planifié ne tourne :

- rappels de formalisation et alerte à l'échéance (`raaga:formalisation`, chaque jour à 08:00) ;
- renouvellement et expiration des cartes (`raaga:cartes`, chaque jour à 06:30) ;
- appels de fonds des actions A (chaque jour à 07:00) ;
- ouverture et clôture des campagnes de boutiques et des tranches (chaque minute).

Contrôle : une tâche planifiée chaque minute enregistre son passage. `php artisan raaga:verifier-production` avertit « Planificateur Laravel inactif (aucun passage depuis 5 minutes) » si aucun passage n'est enregistré depuis plus de 5 minutes, et donne la ligne cron avec le chemin réel de l'application. C'est un avertissement, pas un blocage : au premier déploiement, aucun passage n'existe encore. Relancer le contrôle quelques minutes après avoir installé la tâche cron : l'avertissement disparaît. La ligne cron figure aussi dans les prérequis en tête de `deploy.sh`.

---

## Section « Mise en production de la carte » (à compléter)

### Armoiries

Les armoiries fournies avec le code (avec écusson, sur fond blanc, depuis le 05/11/2026) sont une version provisoire en basse résolution. Déposer la version officielle dans « Paramètres de la plateforme », section Identité. Tant qu'aucun fichier n'y est déposé, `php artisan raaga:verifier-production` avertit « Armoiries par défaut : version provisoire en basse résolution », quelle que soit la taille du fichier fourni.

### Tâches planifiées

Le renouvellement et l'expiration des cartes (`raaga:cartes`) dépendent de la tâche cron du planificateur Laravel (voir « Mise en production »).

### Règles de la carte depuis le 05/11/2026

- titre « CARTE D'IDENTIFICATION RAAGA », le type de carte en petit dessous ;
- sites imprimés sous leurs nouveaux libellés : « Arrondissement 1 Ouagadougou », « Arrondissement 4 Ouagadougou », « Sarfalao Bobo-Dioulasso » ;
- la photo ne masque jamais de texte ; les textes longs passent sur deux ou trois lignes ;
- groupe sanguin imprimé seulement avec le consentement du titulaire et un justificatif validé par l'ADMIN ; sinon, aucune ligne. Le titulaire déclare son groupe et dépose le justificatif dans « Vérifier mon identité », à tout moment ;
- personne morale : nom du gérant, ligne « Entreprise : » avec la raison sociale, numéro IFU de l'entreprise ;
- carte de commerçant : une nouvelle émission (première carte, réémission après perte) exige un IFU saisi et une attestation IFU validée par l'ADMIN. Une carte déjà émise avec l'IFU du compte reste active, et ses mises à jour restent possibles ; tant que l'attestation n'est pas validée, le compte figure dans « À traiter » de l'ADMIN, liste « IFU à valider pour des cartes déjà émises » ;
- ne sont jamais imprimés sur la carte : le RCCM, le nom commercial, le numéro du récépissé de préinscription.

---

## Section « Recette » (nouvelle)

La recette du comité technique se déroule sur un serveur séparé de la production : base, stockage et domaine propres.

### Configuration

- copier `.env.recette.example` en `.env` sur le serveur de recette, puis générer la clé et les mots de passe sur place, jamais dans le dépôt ;
- `APP_ENV=recette` : bandeau rouge « ENVIRONNEMENT DE TEST » en haut de toutes les pages (jamais en local ni en production), paiements par le simulateur, SMS écrits dans les journaux et lisibles sur la page « SMS envoyés » (`/recette/sms`) ;
- `RAAGA_SMS_MOT_DE_PASSE_RECETTE` : mot de passe commun des testeurs pour la page « SMS envoyés », choisi sur place et gardé dans `.env` seulement. Vide : la page est fermée. Cinq essais par minute. La page ne donne aucun accès au back-office ;
- la double authentification du back-office est obligatoire, comme en production ;
- les comptes de démonstration (mot de passe = adresse électronique) sont refusés en recette.

### Commandes

- `php artisan raaga:recette-comptes` : crée un compte par profil du back-office (adresses `@recette.raaga.test`) et trois comptes citoyens (personne physique avec IFU, personne physique sans IFU, personne morale). Les mots de passe sont générés et affichés une seule fois. Options : `--nouveaux-mots-de-passe` (régénère ceux du back-office), `--testeur="Nom Prénoms" --profil=ADMIN` (compte nominatif, pour que le journal identifie chaque testeur) ;
- `php artisan raaga:recette-reinitialiser` : remet la recette à zéro (base recréée, fichiers déposés supprimés, référentiels rechargés, comptes de recette régénérés). La commande demande de taper le nom de la base pour confirmer, et refuse de tourner sans interaction. Option `--simulation` : recrée aussi les citoyens de la simulation, un par étape des parcours ;
- `php artisan raaga:simulation` est admise en local et en recette, jamais en production.

`raaga:recette-comptes` et `raaga:recette-reinitialiser` refusent de tourner hors de la recette.

### Guide des testeurs

`docs/guide-recette.md` : 19 scénarios (S1 à S19), avec les comptes à utiliser, les étapes et le résultat attendu. L'adresse de signalement des anomalies reste à compléter.

---

## Section « Livraisons » (à ajouter : réunion du 05/11/2026)

- **Carte** : titre et type, libellés des sites, armoiries avec écusson, groupe sanguin justifié, carte de personne morale (gérant et entreprise) ;
- **Recette** : environnement `APP_ENV=recette`, bandeau, comptes de recette, page « SMS envoyés » protégée, remise à zéro, guide de recette ;
- **Recherche des boutiques** : « Total à l'entrée (caution + avance) » et « Loyer mensuel » côte à côte ; filtres par budget (sur le total à l'entrée), zone, niveau et surface ; coût minimum et maximum par zone ; nombre de personnes en liste d'attente, sans aucun nom ; « Montant de la 1re tranche » affiché quand l'échéancier existera ;
- **Personne morale, gérant, RCCM** : le titulaire du compte est le gérant (fonction et adresse électronique obligatoires) ; RCCM obligatoire pour une personne morale (numéro, date d'immatriculation, greffe, justificatif), facultatif pour une personne physique dans « Vérifier mon identité » ; liste des greffes gérée par le SUPER_ADMIN, avec « Autre (préciser) » ; l'ADMIN consulte les greffes saisis en « Autre » ;
- **Formalisation différée** : une personne physique peut réserver une boutique sans IFU ; elle reçoit un récépissé de préinscription (numéro RP-AAAA-000123, QR code de vérification) et dispose de 6 mois (réglage « Délai de formalisation sans IFU ») pour déposer son IFU et son attestation ; rappels par SMS à 2 mois, 1 mois et 15 jours de l'échéance ; à l'échéance, alerte seule au back-office, en attendant la décision du comité ; le récépissé atteste seulement la réservation et le délai, il ne remplace aucune pièce officielle ;
- **Nom commercial** : facultatif, saisi dans « Mon profil » une fois l'IFU validé ; affiché sur la fiche de la boutique au back-office, dans le dossier d'identité, au guichet et dans « Mes boutiques » ; jamais sur la carte.

Suite complète des tests au 05/11/2026 : 890 réussis sur 890.
