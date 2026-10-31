<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StatutCarte;
use App\Enums\TypeCarte;
use App\Http\Controllers\Controller;
use App\Services\Cartes\Cartes;
use App\Services\Cartes\FichiersCarte;
use App\Services\Cartes\PhotosPersonnel;
use App\Services\Cartes\RenduCarte;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * « Ma carte » du personnel (back-office) : carte PERSONNEL émise par le SUPER_ADMIN ; photo déposée par le titulaire et
 * validée par l'ADMIN avant de figurer sur la carte.
 */
class MaCarteController extends Controller
{
    public function show(Request $request, RenduCarte $rendu, FichiersCarte $fichiers): View
    {
        $carte = Cartes::courante($request->user(), TypeCarte::Personnel);
        // Fichiers absents : préparés avant le premier téléchargement.
        if ($carte) {
            $fichiers->demander($carte);
        }

        return view('admin.carte', [
            'carte' => $carte,
            'qr' => $carte?->estValide() ? $rendu->qrSvg($carte, __('public.cartes.carte.qr')) : null,
            'faces' => $carte && $carte->statut !== StatutCarte::EnAttente ? $rendu->ecran($carte) : null,
            'photo' => PhotosPersonnel::derniere($request->user()),
        ]);
    }

    public function deposerPhoto(Request $request, PhotosPersonnel $photos): RedirectResponse
    {
        $fichier = $request->validate(
            ['photo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'max:5120']],
            ['photo.required' => __('admin.cartes.erreurs.photo'), 'photo.max' => __('admin.cartes.erreurs.photo_taille'), 'photo.*' => __('admin.cartes.erreurs.photo_format')],
        )['photo'];
        $photos->deposer($request->user(), $fichier);

        return redirect()->route('admin.carte')->avecSucces('admin.cartes.messages.photo_deposee');
    }
}
