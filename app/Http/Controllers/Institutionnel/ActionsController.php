<?php

namespace App\Http\Controllers\Institutionnel;

use App\Models\LigneRegistreA;
use App\Services\Institutionnels\RegistreA;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * « Mes actions » : lignes du registre A de l'institution et attestations d'actionnaire.
 */
class ActionsController extends Controleur
{
    public function index(): View
    {
        return view('institutionnel.actions', [
            'lignes' => LigneRegistreA::query()->where('institution_id', $this->institution()->id)->with('convention')->orderBy('id')->get(),
        ]);
    }

    public function attestation(LigneRegistreA $ligne): StreamedResponse
    {
        $this->verifierAppartenance($ligne);

        return Storage::disk(RegistreA::DISQUE)->response($ligne->attestation_chemin, 'attestation-'.$ligne->numero_certificat.'.pdf', ['Content-Type' => 'application/pdf']);
    }
}
