<?php

namespace Database\Seeders;

use App\Services\Plateforme\ParametresPlateforme;
use App\Support\Plateforme\ReglagesCarte;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Paramètres de la carte d'identification (comité restreint du 03/11/2026) : durée de validité de la carte de commerçant (3 ans) et
 * filigranes de la maquette (armoiries et contour du Burkina Faso au recto, silure et chapeau de Saponé au verso), images dans
 * database/seeders/images/carte. S'exécute aussi en production, depuis ReferentielsSeeder. Ne remplit que les réglages
 * vides : ce que le SUPER_ADMIN a saisi ou déposé n'est jamais remplacé.
 */
class ParametresCarteSeeder extends Seeder
{
    public const DUREE_VALIDITE_ANNEES = 3;

    /** Délai de formalisation sans IFU (05/11/2026), en mois. */
    public const DELAI_FORMALISATION_MOIS = 6;

    /**
     * Filigrane => image fournie (images en couleur, converties en monochrome or au rendu ; remplacées le 05/11/2026,
     * contour du Burkina Faso au recto ajouté le même jour).
     */
    public const FILIGRANES = [
        'filigranePrincipalRecto' => 'filigrane-armoiries.png',
        'filigraneSecondaireRecto' => 'filigrane-carte-burkina.png',
        'filigranePrincipalVerso' => 'filigrane-silure.png',
        'filigraneSecondaireVerso' => 'filigrane-chapeau.png',
    ];

    public function run(ParametresPlateforme $parametres): void
    {
        $carte = $parametres->section('carte');
        $complements = [];
        if (blank($carte['dureeValiditeAnnees'] ?? null)) {
            $complements['dureeValiditeAnnees'] = self::DUREE_VALIDITE_ANNEES;
        }
        if (blank($carte['delaiFormalisationMois'] ?? null)) {
            $complements['delaiFormalisationMois'] = self::DELAI_FORMALISATION_MOIS;
        }
        foreach (self::FILIGRANES as $champ => $image) {
            if (ReglagesCarte::chemin($carte[$champ] ?? null) === null) {
                // Même nommage que les fichiers déposés depuis l'administration : nom unique, aucun fichier écrasé.
                $chemin = 'plateforme/'.Str::kebab($champ).'-'.Str::uuid().'.png';
                Storage::disk('local')->put($chemin, (string) file_get_contents(__DIR__.'/images/carte/'.$image));
                $complements[$champ] = $chemin;
            }
        }
        if ($complements !== []) {
            $parametres->enregistrer('carte', [...$carte, ...$complements], null);
        }
    }
}
