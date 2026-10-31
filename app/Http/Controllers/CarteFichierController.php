<?php

namespace App\Http\Controllers;

use App\Enums\TypeCarte;
use App\Models\Carte;
use App\Services\Cartes\FichiersCarte;
use App\Services\Cartes\RenduCarte;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Téléchargement de sa carte par le titulaire : PDF (une page par face, 85,6 × 54 mm) ou image PNG d'une face (recto
 * par défaut, ?face=verso). Réservé au titulaire, pour une carte courante et active. Le fichier de la version courante,
 * généré à l'avance en tâche de fond, est servi tel quel ; rien n'est généré pendant la requête. Fichier pas encore prêt
 * (30/10/2026) : génération demandée, retour sur la carte avec « Votre carte est en cours de préparation » ; génération
 * en échec définitif : « Votre carte n'a pas pu être préparée. Contactez l'assistance. » (relance par le back-office).
 */
class CarteFichierController extends Controller
{
    public function __invoke(Request $request, Carte $carte, string $format, FichiersCarte $fichiers, RenduCarte $rendu): Response|RedirectResponse
    {
        abort_unless($request->user()?->id === $carte->user_id, 403);
        abort_unless($carte->courante && $carte->estValide(), 404);
        $face = $request->query('face') === 'verso' ? 'verso' : 'recto';

        if (! $fichiers->prets($carte)) {
            $fichiers->demander($carte);

            return redirect()->back(fallback: $carte->type === TypeCarte::Personnel ? route('admin.carte') : route('espace.carte', ['type' => strtolower($carte->type->value)]))
                ->with('erreur', __($carte->fichiersEnEchec() ? 'carte.echec' : 'carte.preparation'));
        }

        return response($fichiers->lire($carte, $format === 'pdf' ? 'pdf' : $face), 200, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$rendu->nomFichier($carte, $format, $format === 'png' ? $face : null).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
