<?php

namespace App\Http\Controllers;

use App\Enums\Droit;
use App\Models\Recepisse;
use App\Services\Boutiques\Formalisation;
use App\Services\Documents\GenerateurPdf;
use App\Support\QrPng;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Récépissé de préinscription Raaga (réunion du 05/11/2026) : PDF téléchargeable par son titulaire dans « Mon espace »,
 * imprimable au guichet (agent de guichet, ADMIN), vérifiable par tous grâce au QR code (page publique : numéro,
 * boutique, site, dates, état de la formalisation ; comme pour les cartes, aucune donnée personnelle).
 */
class RecepissesController extends Controller
{
    /** Page publique ouverte par le QR code. */
    public function verifier(string $jeton): View
    {
        $recepisse = Recepisse::query()->where('jeton', $jeton)->with(['user', 'boutique.site'])->first();
        abort_if($recepisse === null, 404);

        return view('public.recepisses.verifier', ['recepisse' => $recepisse, 'etat' => self::etat($recepisse)]);
    }

    /** PDF du titulaire (Mon espace). */
    public function pdf(Request $request, string $numero, GenerateurPdf $pdf): Response
    {
        $recepisse = Recepisse::query()->where('numero', $numero)->firstOrFail();
        abort_unless($recepisse->user_id === $request->user()->id, 403);

        return $this->rendre($recepisse, $pdf);
    }

    /** PDF imprimé au guichet. */
    public function guichet(string $numero, GenerateurPdf $pdf): Response
    {
        Gate::authorize(Droit::CartesVerifier->value);

        return $this->rendre(Recepisse::query()->where('numero', $numero)->firstOrFail(), $pdf);
    }

    /** État de la formalisation affiché : formalisé, en cours, délai dépassé. */
    public static function etat(Recepisse $recepisse): string
    {
        return match (true) {
            Formalisation::formalise($recepisse->user) => 'formalise',
            $recepisse->echeance_le->lt(today()) => 'echu',
            default => 'en_cours',
        };
    }

    private function rendre(Recepisse $recepisse, GenerateurPdf $pdf): Response
    {
        $recepisse->load(['user', 'boutique.site', 'boutique.type']);

        return response($pdf->rendre('documents.recepisse-preinscription', [
            'recepisse' => $recepisse,
            'qr' => QrPng::dataUri($recepisse->urlVerification()),
        ]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="recepisse-preinscription-'.$recepisse->numero.'.pdf"',
        ]);
    }
}
