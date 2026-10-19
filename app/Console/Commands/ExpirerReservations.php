<?php

namespace App\Console\Commands;

use App\Services\Boutiques\ListesAttente;
use App\Services\Paiement\ServicePaiement;
use Illuminate\Console\Command;

/**
 * Expiration des souscriptions RÉSERVÉES non payées dans le délai de la tranche (valeur + durée, en secondes), et des
 * boutiques pré-réservées dont la caution n'est pas payée ; puis chaque boutique libre qui a une liste d'attente passe
 * au premier inscrit qui peut être retenu.
 */
class ExpirerReservations extends Command
{
    protected $signature = 'raaga:expirer-reservations';

    protected $description = 'Expire les réservations de souscription non payées dans le délai';

    public function handle(ServicePaiement $paiements, ListesAttente $listes): int
    {
        $nombre = $paiements->expirerReservations();
        $retenus = $listes->reevaluer();

        $this->info("{$nombre} réservation(s) expirée(s). {$retenus} attributaire(s) retenu(s) sur liste d'attente.");

        return self::SUCCESS;
    }
}
