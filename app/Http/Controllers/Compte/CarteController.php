<?php

namespace App\Http\Controllers\Compte;

use App\Enums\StatutKyc;
use App\Enums\TypeCarte;
use App\Http\Controllers\Controller;
use App\Services\Cartes\Cartes;
use App\Services\Cartes\RenduCarte;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * « Ma carte » du citoyen : carte COMMERÇANT et carte ACTIONNAIRE (onglets), ou « En attente de vérification
 * d'identité » tant que le dossier n'est pas conforme.
 */
class CarteController extends Controller
{
    public function show(Request $request, RenduCarte $rendu, ?string $type = null): View
    {
        $user = $request->user();
        $cartes = collect([TypeCarte::Commercant, TypeCarte::Actionnaire])->mapWithKeys(fn (TypeCarte $t) => [strtolower($t->value) => Cartes::courante($user, $t)]);
        $choisi = $type && $cartes->has($type) ? $type : ($cartes->filter()->keys()->first() ?? 'commercant');

        return view('public.espace.carte', [
            'cartes' => $cartes,
            'choisi' => $choisi,
            'carte' => $cartes[$choisi],
            'qr' => $cartes[$choisi]?->estValide() ? $rendu->qrSvg($cartes[$choisi], __('public.cartes.carte.qr')) : null,
            'kycConforme' => $user->statut_kyc === StatutKyc::Valide,
        ]);
    }
}
