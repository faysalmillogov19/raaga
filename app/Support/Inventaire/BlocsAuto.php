<?php

namespace App\Support\Inventaire;

/**
 * Parties automatiques d'un fichier d'inventaire, encadrées par <!-- AUTO:debut nom --> et <!-- AUTO:fin nom -->.
 * Seul ce qui se trouve entre deux balises d'un même nom est remplacé ; tout le reste (parties rédigées) est conservé
 * à l'identique. Bloc absent : ajouté en fin de fichier (l'en-tête, lui, se place sous le titre).
 */
final class BlocsAuto
{
    public static function debut(string $nom): string
    {
        return "<!-- AUTO:debut {$nom} -->";
    }

    public static function fin(string $nom): string
    {
        return "<!-- AUTO:fin {$nom} -->";
    }

    /** Bloc complet, balises comprises. */
    public static function bloc(string $nom, string $contenu): string
    {
        return self::debut($nom)."\n".trim($contenu, "\n")."\n".self::fin($nom);
    }

    /**
     * @param  array<string, string>  $blocs  nom => contenu (sans les balises)
     */
    public static function remplacer(string $document, array $blocs, string $titreAnnexes = '## Annexes générées automatiquement'): string
    {
        $ajouts = [];
        foreach ($blocs as $nom => $contenu) {
            $motif = '/'.preg_quote(self::debut($nom), '/').'.*?'.preg_quote(self::fin($nom), '/').'/s';
            $nouveau = self::bloc($nom, $contenu);
            if (preg_match($motif, $document)) {
                $document = (string) preg_replace_callback($motif, fn () => $nouveau, $document, 1);
            } elseif ($nom === 'entete') {
                $document = self::sousLeTitre($document, $nouveau);
            } else {
                $ajouts[] = $nouveau;
            }
        }
        if ($ajouts !== []) {
            $document = rtrim($document)."\n\n".(str_contains($document, $titreAnnexes) ? '' : $titreAnnexes."\n\n").implode("\n\n", $ajouts)."\n";
        }

        return $document;
    }

    /** Insère le bloc sous la première ligne de titre (« # … »), ou en tête s'il n'y a pas de titre. */
    private static function sousLeTitre(string $document, string $bloc): string
    {
        if (preg_match('/^# .*$/m', $document, $m, PREG_OFFSET_CAPTURE)) {
            $position = $m[0][1] + strlen($m[0][0]);

            return substr($document, 0, $position)."\n\n".$bloc."\n".substr($document, $position);
        }

        return $bloc."\n\n".$document;
    }
}
