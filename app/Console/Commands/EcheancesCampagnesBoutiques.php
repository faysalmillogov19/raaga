<?php

namespace App\Console\Commands;

use App\Services\Boutiques\EcheancesCampagnes;
use Illuminate\Console\Command;

/** Ouverture et clôture automatiques des campagnes de boutiques à leurs dates. */
class EcheancesCampagnesBoutiques extends Command
{
    protected $signature = 'raaga:campagnes-boutiques';

    protected $description = 'Ouvre les campagnes de boutiques confirmées à leur date d’ouverture et clôture celles dont la date de clôture est passée';

    public function handle(EcheancesCampagnes $echeances): int
    {
        $bilan = $echeances->appliquer();

        $this->info("{$bilan['ouvertes']} campagne(s) ouverte(s), {$bilan['cloturees']} campagne(s) clôturée(s).");

        return self::SUCCESS;
    }
}
