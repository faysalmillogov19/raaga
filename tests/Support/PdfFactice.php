<?php

namespace Tests\Support;

use App\Services\Documents\GenerateurPdf;

/**
 * Doublure rapide du générateur PDF : la vue Blade est rendue comme pour le vrai document (erreurs de gabarit
 * détectées), puis un PDF minimal est rendu à la place de la mise en page DomPDF. Un test produit un vrai PDF.
 */
class PdfFactice extends GenerateurPdf
{
    public function rendre(string $vue, array $donnees = []): string
    {
        view($vue, [...$donnees, 'pdf' => true])->render();

        return "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\n"
            ."trailer << /Root 1 0 R >>\n%%EOF\n";
    }
}
