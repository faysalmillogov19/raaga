<?php

namespace App\Console\Commands;

use App\Services\Paiement\Relances;
use App\Services\Paiement\ServicePaiement;
use Illuminate\Console\Command;

/**
 * Relances automatiques des paiements attendus (souscriptions et cautions) : 24 h avant la fin du délai, puis à
 * l'expiration. Les réservations échues sont d'abord expirées, pour que la relance d'expiration parte à temps.
 */
class RelancerPaiements extends Command
{
    protected $signature = 'raaga:relancer-paiements';

    protected $description = 'Relance les paiements attendus (24 h avant la fin du délai, puis à l’expiration)';

    public function handle(ServicePaiement $paiements, Relances $relances): int
    {
        $paiements->expirerReservations();
        $n = $relances->automatiques();

        $this->info("{$n['echeance']} relance(s) avant échéance, {$n['expiration']} relance(s) d'expiration, {$n['complement']} relance(s) de complément.");

        return self::SUCCESS;
    }
}
