<?php

namespace App\Console\Commands;

use App\Services\Boutiques\AvancesBoutiques;
use App\Services\Paiement\Relances;
use App\Services\Paiement\ServicePaiement;
use Illuminate\Console\Command;

/**
 * Relances automatiques des paiements attendus (souscriptions et cautions) : 24 h avant la fin du délai, puis à
 * l'expiration ; avances des boutiques : 7 jours puis 24 heures avant la date limite, puis passage « avance en
 * retard » (sans autre action). Les réservations échues sont d'abord expirées, pour que la relance d'expiration parte
 * à temps.
 */
class RelancerPaiements extends Command
{
    protected $signature = 'raaga:relancer-paiements';

    protected $description = 'Relance les paiements attendus (24 h avant la fin du délai, puis à l’expiration)';

    public function handle(ServicePaiement $paiements, Relances $relances, AvancesBoutiques $avances): int
    {
        $paiements->expirerReservations();
        $n = $relances->automatiques();
        // Avances dont la date limite est dépassée : dossier « avance en retard », citoyen notifié, aucune autre action.
        $retards = $avances->marquerRetards();

        $this->info("{$n['echeance']} relance(s) avant échéance, {$n['expiration']} relance(s) d'expiration, {$n['complement']} relance(s) de complément, "
            ."{$n['avance']} relance(s) d'avance, {$retards} avance(s) passée(s) en retard.");

        return self::SUCCESS;
    }
}
