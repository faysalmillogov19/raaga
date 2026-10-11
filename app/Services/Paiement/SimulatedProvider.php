<?php

namespace App\Services\Paiement;

use App\Contracts\PaymentProvider;
use App\Models\Paiement;
use App\Support\VerrouEnvironnement;

/**
 * Simulateur de recette : aucune somme n'est débitée. L'issue (succès / échec) est choisie à l'écran
 * en recette. Inutilisable en production : la construction échoue (même verrou que les données de démonstration).
 */
class SimulatedProvider implements PaymentProvider
{
    public function __construct()
    {
        VerrouEnvironnement::exigerHorsProduction('paiement simulé');
    }

    public function estSimule(): bool
    {
        return true;
    }

    public function initier(Paiement $paiement): void
    {
        $paiement->forceFill([
            'reference_fournisseur' => 'SIMU-'.$paiement->reference,
            'metadonnees' => [...(array) $paiement->metadonnees, 'simule' => true],
        ])->save();
    }
}
