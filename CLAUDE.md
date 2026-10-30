<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

## Inventaires locaux du front-office et du back-office

À la fin de chaque tâche qui modifie une route, une vue, un menu, un composant, un droit, un parcours ou un texte affiché :
lance php artisan raaga:inventaire, puis mets à jour à la main les parties rédigées concernées (textes mot pour mot, états, messages, parcours) dans les deux fichiers d'inventaire.
Ne le fais jamais en production. Ne commite jamais ces fichiers. Signale en une ligne ce que tu as mis à jour.

Fichiers : docs/INVENTAIRE-FRONT-OFFICE.md et docs/INVENTAIRE-BACK-OFFICE.md (ignorés par git). La commande ne réécrit que les parties encadrées par `<!-- AUTO:debut nom -->` et `<!-- AUTO:fin nom -->` ; tout le reste est rédigé à la main. Elle refuse de tourner si APP_ENV n'est pas « local ».

## Consignes de rédaction des documents

### Format (Word .docx, gabarit du rapport de l'atelier)
- A4 portrait, marges de 2,5 cm, pied de page centré « Page X sur Y ».
- Times New Roman 12 pour tout le texte, y compris les listes et les tableaux ; texte justifié, interligne 1,5, 6 pt après chaque paragraphe.
- Titre du document centré, en majuscules, Times New Roman 14 gras, sur deux lignes, suivi d'une ligne centrée en italique (lieu ou structure, et date).
- Grands titres en majuscules, Times New Roman 14 gras ; sous-titres en Times New Roman 12 gras ; intitulés internes en gras, sans numéro.
- Listes à puces simples : chaque élément commence par une minuscule et se termine par « ; », le dernier par un point. Listes numérotées seulement pour des étapes à suivre dans l'ordre.
- Tableaux à bordures simples, pleine largeur, en-tête en gras sur fond gris clair (D9D9D9) répété à chaque page, texte en interligne simple, lignes non coupées entre deux pages.
- Propriétés du fichier : auteur « SONASP – atelier de maintenance », aucune description ni mention d'outil.

### Rédaction
- En français, phrases courtes, ton administratif.
- Le texte doit se lire comme rédigé par l'équipe de l'atelier.

### Supprimer tous les marqueurs de contenu généré par IA
- Aucune mention d'un outil, d'un assistant ou d'une génération automatique, ni dans le texte ni dans les propriétés du fichier.
- Pas d'encadrés « information manquante », « à vérifier », « premier jet », « version de travail », ni de notes sur la façon dont le document a été produit.
- Pas de flèches (→), de tirets doubles (--) ou de tirets longs comme ponctuation.
- Pas d'ouverture du type « Ce document présente… » à chaque section, pas de résumé qui répète un tableau, pas d'excès de gras ou d'étiquettes suivies de deux-points.

### Aucune information critique
- Jamais de mot de passe, d'identifiant, de secret, d'adresse de serveur ou d'adresse IP.
- Ne pas indiquer où se trouvent les secrets, ni quelles fonctions, routes ou écrans ne sont pas protégés.
- Pas de noms techniques de routes, de fichiers, de classes, de variables ou de scripts, ni de versions exactes des bibliothèques.
- Les failles de sécurité sont décrites en termes généraux : « contrôle d'accès par rôle à compléter », « informations de configuration sensibles à retirer du dépôt ».

### Aucun scénario non testé ni ambigu
- Ne citer que les tests réellement exécutés, avec un résultat net : « Réussi » ou « Échec ».
- Jamais « non testé », « non vérifiable », « non mesuré », « à confirmer », « prévu », « hors périmètre » ou « en partie ».
- Statut d'un jalon : « Atteint » ou « Partiellement atteint » uniquement.
- Ne pas décrire une fonction retirée (par exemple la tarification) ni une fonction dont le comportement n'est pas vérifié.

### Cohérence entre les documents
- Les chiffres doivent être identiques dans tous les livrables : 43 constats (8 corrigés, 13 partiellement corrigés, 21 ouverts, 1 conservé à la demande de l'équipe) ; 343 tests serveur exécutés le 18 octobre 2026 (337 réussis, 6 en échec sur la collecte d'or par artisan) ; 4 jalons atteints (J1, J4, J7, J9) et 12 partiellement atteints.
- Si une nouvelle mesure change ces chiffres, signale-le-moi avant de mettre à jour un document.

### Méthode
- Ne génère aucun document sans mon autorisation.
- Ne modifie pas le code du projet quand la demande porte sur un document.
- Ne commite rien sans mon accord.
