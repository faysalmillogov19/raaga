<?php

namespace App\Services\Documents;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Documents PDF officiels (attestations, avis d'appel de fonds…) rendus depuis une vue Blade.
 * L'en-tête commun (documents._entete) reprend l'identité et les mentions légales des paramètres
 * de la plateforme ; les armoiries sont intégrées au fichier (aucune ressource distante).
 */
class GenerateurPdf
{
    /**
     * @param  array<string, mixed>  $donnees
     * @return string contenu binaire du PDF
     */
    public function rendre(string $vue, array $donnees = []): string
    {
        return Pdf::loadView($vue, [...$donnees, 'pdf' => true])
            ->setPaper('a4')
            ->setOption(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
            ->output();
    }
}
