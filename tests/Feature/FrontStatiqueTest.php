<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Front sans npm ni Vite : fichiers statiques de public/ (CSS figé, JavaScript natif, bibliothèques copiées avec leur
 * licence). Aucune classe utilitaire employée dans les vues, app/Support ou les contrôleurs ne doit manquer au fichier
 * figé public/css/app.css (ni à admin.css ou raaga.css) : sans compilation, elle n'aurait aucun effet.
 */
class FrontStatiqueTest extends TestCase
{
    /** Familles de classes utilitaires Tailwind (préfixe suivi d'une valeur). */
    private const FAMILLES = '-?(?:m|mx|my|mt|mr|mb|ml|p|px|py|pt|pr|pb|pl|gap|gap-x|gap-y|space-x|space-y|w|h|size|min-w|min-h|max-w|max-h'
        .'|inset|inset-x|inset-y|top|right|bottom|left|z|order|basis|col-span|col-start|col-end|row-span|grid-cols|grid-rows'
        .'|translate-x|translate-y|rotate|scale|skew-x|skew-y|tracking|leading|indent|opacity|duration|delay|ease|text|bg|from|via|to'
        .'|border|border-[trblxy]|border-[se]|rounded|rounded-[trblse]{1,2}|ring|ring-offset|outline|outline-offset|fill|stroke|decoration'
        .'|underline-offset|divide|divide-[xy]|placeholder|accent|caret|shadow|font|list|align|whitespace|break|overflow|overflow-[xy]'
        .'|object|cursor|select|pointer-events|items|justify|justify-items|justify-self|self|content|place-items|place-content|place-self'
        .'|flex|grow|shrink|transition|line-clamp|aspect|columns|auto-cols|auto-rows|grid-flow|snap|scroll-m[trblxy]?|scroll-p[trblxy]?'
        .'|touch|will-change|origin|mix-blend|backdrop-blur|blur|drop-shadow|appearance|resize|float|clear|box|table|sr|animate)-.+';

    /** Classes utilitaires sans valeur. */
    private const MOTS = 'flex|grid|inline-flex|inline-grid|block|inline-block|inline|hidden|contents|table|table-row|table-cell|flow-root'
        .'|sr-only|not-sr-only|static|fixed|absolute|relative|sticky|visible|invisible|collapse|truncate|uppercase|lowercase|capitalize'
        .'|normal-case|italic|not-italic|underline|overline|no-underline|line-through|antialiased|subpixel-antialiased|container|grow|shrink'
        .'|rounded|border|shadow|ring|outline|transition|transform|isolate|ordinal|tabular-nums|break-words|break-all|resize|filter|blur'
        .'|truncate|whitespace-nowrap|grayscale|invert|sepia';

    /** Variantes Tailwind (préfixes « variante: »). */
    private const VARIANTES = 'tab|sm|md|lg|xl|2xl|max-tab|max-sm|max-md|max-lg|hover|focus|focus-visible|focus-within|active|visited|disabled'
        .'|enabled|checked|invalid|required|placeholder|first|last|odd|even|only|empty|open|before|after|marker|selection|file|print'
        .'|motion-safe|motion-reduce|dark|ltr|rtl|group-[\\w-]+|peer-[\\w-]+|aria-[\\w-]+|data-[\\w-]+|has-[\\w-]+|not-[\\w-]+|\\[[^\\]]+\\]';

    public function test_ni_npm_ni_vite(): void
    {
        foreach (['package.json', 'package-lock.json', 'vite.config.js', 'tailwind.config.js', 'postcss.config.js', 'node_modules', 'public/build'] as $chemin) {
            $this->assertFileDoesNotExist(base_path($chemin), "{$chemin} : le projet n'utilise plus npm ni Vite.");
        }

        foreach ([resource_path('views'), app_path()] as $dossier) {
            foreach (File::allFiles($dossier) as $fichier) {
                $this->assertDoesNotMatchRegularExpression('/@vite\b|Vite::|vite\(/', $fichier->getContents(), "{$fichier->getRelativePathname()} : @vite interdit, utiliser <x-assets />.");
            }
        }
        $this->assertStringNotContainsString('npm', File::get(base_path('deploy.sh')));
    }

    public function test_les_fichiers_statiques_sont_servis_avec_leur_version(): void
    {
        foreach (['css/app.css', 'css/admin.css', 'css/raaga.css', 'js/app.js', 'vendor/alpinejs/3.17.4/alpine.min.js', 'vendor/alpinejs/3.17.4/LICENSE.md'] as $fichier) {
            $this->assertFileExists(public_path($fichier));
        }
        $this->assertStringStartsWith("/*!\n * Fichier figé, ne pas modifier.", File::get(public_path('css/app.css')));
        $this->assertStringContainsString('MIT License', File::get(public_path('vendor/alpinejs/3.17.4/LICENSE.md')));

        $page = $this->get('/')->assertOk();
        foreach (['css/app.css', 'css/raaga.css', 'js/app.js', 'vendor/alpinejs/3.17.4/alpine.min.js'] as $fichier) {
            $page->assertSee(asset($fichier).'?v='.filemtime(public_path($fichier)), false);
        }
        // raaga.css (nouveaux styles) est chargé après le fichier figé ; app.js avant Alpine, qui émet alpine:init.
        $page->assertSeeInOrder(['css/app.css?v=', 'css/raaga.css?v=', 'js/app.js?v=', 'alpinejs/3.17.4/alpine.min.js?v='], false);
    }

    public function test_aucune_classe_utilitaire_absente_du_fichier_fige(): void
    {
        $definies = $this->classesDefinies();
        $this->assertGreaterThan(1000, count($definies));

        $absentes = [];
        foreach ($this->classesEmployees() as $classe => $fichier) {
            if ($this->estUtilitaire($classe) && ! isset($definies[$classe])) {
                $absentes[] = "{$classe} ({$fichier})";
            }
        }

        $this->assertSame([], $absentes, "Classes utilitaires absentes de public/css/app.css (fichier figé) : sans effet.\n"
            .'Écrire le style en CSS classique dans public/css/raaga.css, ou employer une classe déjà présente.');
    }

    /** @return array<string, true> classes des sélecteurs de app.css, admin.css et raaga.css, échappements retirés */
    private function classesDefinies(): array
    {
        $classes = [];
        foreach (['css/app.css', 'css/admin.css', 'css/raaga.css'] as $fichier) {
            $css = preg_replace('#/\*.*?\*/#s', '', File::get(public_path($fichier)));
            // Sélecteurs seulement (pas les déclarations, où « .5rem » n'est pas une classe).
            preg_match_all('/([^{}]+)\{/', $css, $blocs);
            foreach ($blocs[1] as $selecteur) {
                preg_match_all('/\.((?:\\\\[0-9a-fA-F]{1,6} ?|\\\\.|[\w-])+)/u', $selecteur, $m);
                foreach ($m[1] as $echappe) {
                    $classes[$this->desechapper($echappe)] = true;
                }
            }
        }

        return $classes;
    }

    private function desechapper(string $classe): string
    {
        $classe = preg_replace_callback('/\\\\([0-9a-fA-F]{1,6}) ?/', fn ($m) => mb_chr(hexdec($m[1])), $classe);

        return preg_replace('/\\\\(.)/u', '$1', $classe);
    }

    /** @return array<string, string> classe => premier fichier où elle apparaît */
    private function classesEmployees(): array
    {
        $classes = [];
        $ajouter = function (string $liste, string $fichier) use (&$classes) {
            // Échos et directives Blade retirés : seules les classes écrites en clair comptent.
            $liste = preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}|@\w+(\s*\((?:[^()]|\([^()]*\))*\))?/s', ' ', $liste);
            foreach (preg_split('/\s+/', trim($liste)) as $classe) {
                if ($classe !== '' && preg_match('/^[!\-]?[\w\-:.\/\[\]%#(),]+$/u', $classe) && ! str_contains($classe, '$')) {
                    $classes[$classe] ??= $fichier;
                }
            }
        };

        foreach (File::allFiles(resource_path('views')) as $fichier) {
            $nom = 'resources/views/'.str_replace('\\', '/', $fichier->getRelativePathname());
            // Courriels : styles intégrés au message, sans lien avec public/css.
            if (str_starts_with($nom, 'resources/views/vendor/mail/')) {
                continue;
            }
            $source = $fichier->getContents();
            // class="…", class='…' (y compris sur les composants <x-…>).
            preg_match_all('/(?<![\w:-])class\s*=\s*"([^"]*)"|(?<![\w:-])class\s*=\s*\'([^\']*)\'/', $source, $m);
            foreach ([...$m[1], ...$m[2]] as $liste) {
                $ajouter($liste, $nom);
            }
            // :class="…", x-bind:class="…", @class([...]) : chaînes entre apostrophes.
            preg_match_all('/(?::class|x-bind:class)\s*=\s*"([^"]*)"|@class\s*\((\[(?:[^\[\]]|\[[^\[\]]*\])*\])\)/s', $source, $m);
            foreach ([...$m[1], ...$m[2]] as $expression) {
                preg_match_all('/\'([^\']*)\'/', $expression, $chaines);
                foreach ($chaines[1] as $liste) {
                    $ajouter($liste, $nom);
                }
            }
        }

        // Classes déclarées en PHP (colonnes des tableaux, menus…) : chaînes composées uniquement de classes.
        foreach ([app_path('Support'), app_path('Http/Controllers')] as $dossier) {
            foreach (File::allFiles($dossier) as $fichier) {
                $nom = 'app/'.str_replace('\\', '/', substr($fichier->getPathname(), strlen(app_path()) + 1));
                preg_match_all('/\'([^\'\\\\]*)\'/', $fichier->getContents(), $m);
                foreach ($m[1] as $chaine) {
                    $jetons = preg_split('/\s+/', trim($chaine));
                    if ($chaine !== '' && $jetons !== [] && collect($jetons)->every(fn ($j) => $this->estUtilitaire($j))) {
                        $ajouter($chaine, $nom);
                    }
                }
            }
        }

        return $classes;
    }

    private function estUtilitaire(string $classe): bool
    {
        // Variantes (tab:, hover:, focus:…) : chaque préfixe doit en être une, le reste une classe utilitaire.
        if (str_contains($classe, ':') && ! str_starts_with($classe, '[')) {
            $parties = preg_split('/:(?![^\[]*\])/', $classe);
            $base = array_pop($parties);

            return $parties !== [] && collect($parties)->every(fn ($v) => preg_match('/^(?:'.self::VARIANTES.')$/', $v)) && $this->estUtilitaire($base);
        }
        $base = ltrim($classe, '!');

        // Valeur arbitraire (min-h-[calc(…)], w-[42px]) ou propriété arbitraire ([mask-type:luminance]).
        return (bool) preg_match('/^(?:'.self::FAMILLES.'|'.self::MOTS.')$|^-?[a-z][\w-]*-\[[^\]]+\]$|^\[[a-z-]+:[^\]]+\]$/', $base);
    }
}
