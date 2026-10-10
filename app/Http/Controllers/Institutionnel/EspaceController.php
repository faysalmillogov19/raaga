<?php

namespace App\Http\Controllers\Institutionnel;

use App\Enums\StatutAppelFonds;
use App\Enums\StatutDocumentKyc;
use App\Enums\TypeDocumentKyb;
use App\Models\AppelFonds;
use App\Models\LigneRegistreA;
use App\Services\Institutionnels\RegistreA;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tableau de bord, contact et rubriques à venir de l'espace institutionnel.
 */
class EspaceController extends Controleur
{
    public function tableau(): View
    {
        $institution = $this->institution();
        $convention = $institution->conventions()->whereIn('statut', ['CONFIRMEE'])->first();
        $actions = (int) LigneRegistreA::query()->where('institution_id', $institution->id)->sum('nb_actions');
        $repartition = RegistreA::repartitionCapital();
        $prochain = AppelFonds::query()->where('institution_id', $institution->id)
            ->whereNot('statut', StatutAppelFonds::Recu)->orderBy('echeance')->first();
        $pieces = $institution->piecesEnVigueur();

        return view('institutionnel.tableau', [
            'institution' => $institution,
            'convention' => $convention,
            'actions' => $actions,
            'engage' => $convention?->montant_engage ?? 0,
            'paye' => $convention?->montantRecu() ?? 0,
            'prochain' => $prochain,
            // Part du capital : actions A détenues sur le total des actions inscrites (A + B).
            'part' => $repartition['total'] > 0 ? $actions * 100 / $repartition['total'] : 0,
            'piecesValidees' => $pieces->where('statut', StatutDocumentKyc::Valide)->count(),
            'piecesTotal' => count(TypeDocumentKyb::cases()),
        ]);
    }

    public function contact(): View
    {
        return view('institutionnel.contact', ['institution' => $this->institution()]);
    }

    /** Rubriques prévues pour plus tard (dividendes, informations du projet, assemblées générales). */
    public function aVenir(Request $request): View
    {
        $rubrique = $request->route()->defaults['rubrique'];

        return view('institutionnel.a-venir', ['rubrique' => $rubrique]);
    }
}
