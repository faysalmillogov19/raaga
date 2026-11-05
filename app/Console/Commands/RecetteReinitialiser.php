<?php

namespace App\Console\Commands;

use App\Support\GardeFouBase;
use App\Support\Recette;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Remise à zéro de la recette (réunion du 05/11/2026), uniquement avec APP_ENV=recette : après saisie du nom de la base,
 * la base est effacée puis recréée (migrations, référentiels), les fichiers déposés sont supprimés, les comptes de
 * recette sont recréés avec de nouveaux mots de passe ; avec --simulation, les citoyens de la simulation aussi. Refusée
 * sans interaction. Les commandes appelées ici ne repassent pas par le garde-fou : la confirmation est faite une fois.
 */
class RecetteReinitialiser extends Command
{
    protected $signature = 'raaga:recette-reinitialiser {--simulation : Recrée aussi les citoyens de la simulation (un par étape des parcours)}';

    protected $description = 'Remet la recette à zéro : base recréée, fichiers déposés supprimés, comptes de recette régénérés';

    /** Dossiers du disque privé effacés : pièces d'identité, cartes, preuves de paiement, fichiers déposés dans les paramètres. */
    public const DOSSIERS = ['kyc', 'cartes', 'paiements', 'plateforme', 'imports'];

    public function handle(): int
    {
        if (! Recette::active()) {
            $this->error(__('recette.refusee', ['env' => app()->environment()]));

            return self::FAILURE;
        }
        $base = GardeFouBase::baseCourante();
        try {
            GardeFouBase::confirmerNomBase($this->input, $this->output, __('recette.reinitialiser.confirmation', ['base' => $base]),
                __('recette.reinitialiser.annulee', ['base' => $base]), __('recette.reinitialiser.sans_interaction'), fn (string $question) => $this->ask($question));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->call('migrate:fresh', ['--force' => true]);
        $disque = Storage::disk('local');
        foreach (self::DOSSIERS as $dossier) {
            $disque->deleteDirectory($dossier);
        }
        $this->info(__('recette.reinitialiser.fichiers'));
        Cache::flush();
        $this->call('db:seed', ['--force' => true]);
        $this->call('raaga:recette-comptes', ['--nouveaux-mots-de-passe' => true]);
        if ($this->option('simulation')) {
            $this->call('raaga:simulation');
        }
        $this->info(__('recette.reinitialiser.terminee'));

        return self::SUCCESS;
    }
}
