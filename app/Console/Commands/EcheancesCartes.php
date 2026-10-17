<?php

namespace App\Console\Commands;

use App\Services\Cartes\Cartes;
use Illuminate\Console\Command;

/** Échéances des cartes Raaga (chaque jour) : renouvellement ACTIONNAIRE, expiration, avertissement avant la fin de la pièce. */
class EcheancesCartes extends Command
{
    protected $signature = 'raaga:cartes';

    protected $description = 'Renouvelle, expire les cartes Raaga échues et avertit 30 jours avant l’expiration de la pièce d’identité';

    public function handle(Cartes $cartes): int
    {
        $bilan = $cartes->echeances();
        $this->info("Cartes renouvelées : {$bilan['renouvelees']} ; expirées : {$bilan['expirees']} ; titulaires avertis : {$bilan['avertis']}.");

        return self::SUCCESS;
    }
}
