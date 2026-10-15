<?php

namespace App\Console\Commands;

use App\Services\Securite\ComptesDemo;
use Illuminate\Console\Command;

/**
 * Vérifie qu'aucun compte de démonstration (mot de passe = adresse e-mail, ou adresse @raaga.test) n'est actif ;
 * avec --desactiver, désactive ceux qui existent (jamais de suppression, trace au journal d'audit).
 */
class ComptesDemoCommande extends Command
{
    protected $signature = 'raaga:comptes-demo {--desactiver : Désactiver les comptes trouvés}';

    protected $description = 'Vérifie et, avec --desactiver, désactive les comptes de démonstration encore actifs.';

    public function handle(ComptesDemo $comptes): int
    {
        $actifs = $comptes->actifs();
        if ($actifs->isEmpty()) {
            $this->info('Aucun compte de démonstration actif.');

            return self::SUCCESS;
        }

        $this->table(['Id', 'E-mail', 'Rôle'], $actifs->map(fn ($u) => [$u->id, $u->email, $u->codeRole()?->label()])->all());
        if (! $this->option('desactiver')) {
            $this->error($actifs->count().' compte(s) de démonstration actif(s). Relancez avec --desactiver pour les désactiver.');

            return self::FAILURE;
        }

        $actifs->each(fn ($u) => $comptes->desactiver($u));
        $this->info($actifs->count().' compte(s) de démonstration désactivé(s).');

        return self::SUCCESS;
    }
}
