<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Mise en forme simple d'un texte saisi au back-office (présentation d'une campagne) : titres (« ## », « ### »), listes
 * à puces (« - » ou « * ») ou numérotées (« 1. »), gras (« **texte** »), italique (« *texte* » ou « _texte_ »),
 * paragraphes séparés par une ligne vide. Tout le texte est échappé AVANT la mise en forme : aucun code HTML ni script
 * saisi n'est jamais interprété ; seules les balises produites ici sortent.
 */
final class MarkdownLeger
{
    public static function html(?string $texte): HtmlString
    {
        $sortie = [];
        $paragraphe = [];
        $liste = null; // ['ul'|'ol', list<string>]

        $fermerParagraphe = function () use (&$paragraphe, &$sortie) {
            if ($paragraphe) {
                $sortie[] = '<p>'.implode('<br>', $paragraphe).'</p>';
                $paragraphe = [];
            }
        };
        $fermerListe = function () use (&$liste, &$sortie) {
            if ($liste) {
                $sortie[] = '<'.$liste[0].'>'.implode('', array_map(fn ($i) => '<li>'.$i.'</li>', $liste[1])).'</'.$liste[0].'>';
                $liste = null;
            }
        };

        foreach (preg_split('/\R/u', trim((string) $texte)) ?: [] as $ligne) {
            $ligne = rtrim($ligne);
            if (trim($ligne) === '') {
                $fermerParagraphe();
                $fermerListe();

                continue;
            }
            if (preg_match('/^\s*(#{1,3})\s+(.+)$/u', $ligne, $m)) {
                $fermerParagraphe();
                $fermerListe();
                // Dans la page, la campagne est un titre de niveau 2 : « # » et « ## » → h3, « ### » → h4.
                $niveau = strlen($m[1]) === 3 ? 'h4' : 'h3';
                $sortie[] = "<{$niveau}>".self::enLigne($m[2])."</{$niveau}>";

                continue;
            }
            $type = match (true) {
                (bool) preg_match('/^\s*[-*•]\s+(.+)$/u', $ligne, $m) => 'ul',
                (bool) preg_match('/^\s*\d+[.)]\s+(.+)$/u', $ligne, $m) => 'ol',
                default => null,
            };
            if ($type) {
                $fermerParagraphe();
                if ($liste && $liste[0] !== $type) {
                    $fermerListe();
                }
                $liste ??= [$type, []];
                $liste[1][] = self::enLigne($m[1]);

                continue;
            }
            $fermerListe();
            $paragraphe[] = self::enLigne(trim($ligne));
        }
        $fermerParagraphe();
        $fermerListe();

        return new HtmlString(implode("\n", $sortie));
    }

    /** Texte d'une ligne : échappé, puis gras et italique. */
    private static function enLigne(string $texte): string
    {
        $texte = e($texte);
        $texte = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $texte);
        $texte = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '<em>$1</em>', $texte);

        return (string) preg_replace('/(?<!\w)_(?!\s)(.+?)(?<!\s)_(?!\w)/u', '<em>$1</em>', $texte);
    }
}
