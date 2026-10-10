<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Support\ATraiter;
use App\Support\MenuBackoffice;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tableau de bord et sections du back-office pas encore livrées. L'accès est déjà protégé
 * par le droit de la section (middleware « can:… » sur la route).
 */
class SectionController extends Controller
{
    /** Sections de droits rattachées à chaque entrée du menu. */
    private const DROITS_PAR_SECTION = [
        'tableau' => ['tableau_de_bord'],
        'demandes' => ['demandes'],
        'contenus' => ['contenus', 'textes_legaux'],
        'approbations' => ['approbations'],
        'securite' => ['securite', 'connexions'],
        'tranches' => ['tranches'],
        'dividendes' => ['dividendes'],
        'simulations' => ['simulations', 'allocation'],
        'kyc' => ['kyc'],
        'conformite' => ['conformite'],
        'souscriptions' => ['souscriptions'],
        'remboursements' => ['remboursements'],
        'registre' => ['registre'],
        'audit' => ['audit'],
        'regulation' => ['rapports'],
        'observations' => ['observations'],
        'utilisateurs' => ['utilisateurs'],
        'referentiels' => ['referentiels'],
    ];

    public function tableau(Request $request): View
    {
        return view('admin.tableau', [
            'menu' => MenuBackoffice::pour($request->user()),
            'aTraiter' => ATraiter::pour($request->user()),
        ]);
    }

    /**
     * Paramètres en lecture seule (SUPER_ADMIN) : liens vers les écrans de consultation.
     */
    public function parametres(): View
    {
        return view('admin.parametres');
    }

    public function show(Request $request): View
    {
        $cle = $request->route()->defaults['section'];
        $entree = MenuBackoffice::entree($cle);

        $droits = array_values(array_filter(
            Droit::cases(),
            fn (Droit $d) => in_array($d->section(), self::DROITS_PAR_SECTION[$cle], true) && $request->user()->can($d->value),
        ));

        return view('admin.section', [
            'cle' => $cle,
            'etape' => $entree['etape'],
            'module' => $entree['module'] ?? null,
            'droits' => $droits,
            'lectureSeule' => collect($droits)->doesntContain(fn (Droit $d) => $d->ecriture()),
        ]);
    }
}
