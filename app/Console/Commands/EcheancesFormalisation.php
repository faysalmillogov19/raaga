<?php

namespace App\Console\Commands;

use App\Services\Boutiques\Formalisation;
use Illuminate\Console\Command;

/** Formalisation différée (chaque jour) : rappels à 2 mois, 1 mois et 15 jours de l'échéance ; alerte au back-office à l'échéance. */
class EcheancesFormalisation extends Command
{
    protected $signature = 'raaga:formalisation';

    protected $description = 'Rappels de formalisation (2 mois, 1 mois, 15 jours avant l’échéance) et alerte au back-office à l’échéance';

    public function handle(Formalisation $formalisation): int
    {
        $bilan = $formalisation->echeances();
        $this->info("Rappels envoyés : {$bilan['rappels']} ; alertes au back-office : {$bilan['alertes']}.");

        return self::SUCCESS;
    }
}
