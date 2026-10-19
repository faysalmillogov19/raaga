<?php

namespace App\Console\Commands;

use App\Services\Souscriptions\EcheancesTranches;
use Illuminate\Console\Command;

/** Ouverture et clôture automatiques des tranches à leurs dates. */
class EcheancesTranchesCommande extends Command
{
    protected $signature = 'raaga:tranches';

    protected $description = 'Ouvre les tranches confirmées à leur date d’ouverture et clôture celles dont la date de clôture est passée';

    public function handle(EcheancesTranches $echeances): int
    {
        $bilan = $echeances->appliquer();

        $this->info("{$bilan['ouvertes']} tranche(s) ouverte(s), {$bilan['cloturees']} tranche(s) clôturée(s).");

        return self::SUCCESS;
    }
}
