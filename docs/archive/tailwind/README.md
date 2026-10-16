# Archive : sources Tailwind de public/css/app.css

Archive en lecture seule. Rien n'est chargé ni compilé depuis ce dossier : le site sert uniquement
`public/css/app.css` (fichier figé), `public/css/admin.css` et `public/css/raaga.css`.

## Contenu

| Fichier | Rôle |
|---|---|
| `app.css` | Source Tailwind de la dernière compilation (directives `@tailwind`, `@layer`, `@apply`, styles de la charte). Ancien `resources/css/app.css`. |
| `tailwind.config.js` | Configuration : point de rupture `tab:` (760 px), couleurs de la charte par variables `--charte-*`, couleurs `adm-*` du back-office, safelist élargie. |
| `postcss.config.js` | PostCSS : Tailwind puis Autoprefixer. |
| `package.json`, `package-lock.json` | Dépendances npm au moment de la compilation (versions exactes dans le lock). |

## Versions exactes

- Tailwind CSS 3.4.19
- @tailwindcss/forms 0.5.11
- PostCSS 8.5.29
- Autoprefixer 10.6.1
- Node.js 22.12.0

## Commande utilisée

Le 15/10/2026, depuis la racine du projet (fichiers de configuration à la racine, `app.css` dans `resources/css/`) :

```sh
npx tailwindcss --postcss -i resources/css/app.css -o public/css/app.css
```

Sortie non minifiée : 179 105 octets. Tailwind a lu les classes dans les chemins `content` de la configuration
(vues Blade, vues compilées, contrôleurs, `app/Support`, pagination de Laravel), auxquels s'ajoute la safelist :

- espacements (`m*`, `p*`, `gap*`, `space-*`) de 0 à 16, avec la variante `tab:` ;
- `flex` et `grid` (affichage, direction, alignements, `grid-cols-*`, `col-span-*`), avec `tab:` ;
- tailles de texte (`text-xs` à `text-4xl`), graisses, alignements, avec `tab:` ;
- couleurs de la charte (`vert`, `vert-fonce`, `encre`…) et `adm-*` pour `text-`, `bg-`, `border-`, avec `hover:` et `focus:`.

## Depuis

`public/css/app.css` ne se modifie plus : les nouveaux styles s'écrivent en CSS classique dans `public/css/raaga.css`.
Le test `tests/Feature/FrontStatiqueTest.php` signale toute classe utilitaire employée dans une vue, `app/Support` ou un
contrôleur qui serait absente du fichier figé.

Pour régénérer un jour le fichier (décision à prendre explicitement) : recopier ces fichiers à la racine et dans
`resources/css/`, installer les versions ci-dessus (`npm ci`), relancer la commande, puis vérifier le rendu avant/après.
