<?php

namespace App\Console\Commands;

use App\Services\Paiement\ServicePaiement;
use Illuminate\Console\Command;

/** Expiration des souscriptions RÉSERVÉES non payées dans le délai de la tranche (valeur + durée, en secondes). */
class ExpirerReservations extends Command
{
    protected $signature = 'raaga:expirer-reservations';

    protected $description = 'Expire les réservations de souscription non payées dans le délai';

    public function handle(ServicePaiement $paiements): int
    {
        $nombre = $paiements->expirerReservations();

        $this->info("{$nombre} réservation(s) expirée(s).");

        return self::SUCCESS;
    }
}
