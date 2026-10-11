<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Compte\VerificationController;
use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Services\Audit\Journal;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Consultation d'une pièce d'identité par le VALIDATEUR (droit kyc.voir_pieces, session back-office
 * complète). L'écran de traitement KYC viendra avec le lot KYC ; chaque consultation est journalisée.
 */
class PiecesKycController extends Controller
{
    public function __invoke(Request $request, KycDocument $document, Journal $journal): StreamedResponse
    {
        $journal->ecrire($request->user(), 'kyc.piece_consultee', 'KycDocument', $document->id);

        return VerificationController::servir($document);
    }
}
