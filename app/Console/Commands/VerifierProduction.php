<?php

namespace App\Console\Commands;

use App\Contracts\PaymentProvider;
use App\Services\Securite\ComptesDemo;
use Illuminate\Console\Command;
use Throwable;

/**
 * Contrôles avant mise en production, appelés par deploy.sh (un échec arrête le déploiement) : aucune donnée ni aucun
 * compte de démonstration, un opérateur de paiement réel (jamais le simulateur), les coordonnées des deux comptes de
 * destination (RAAGA_SEQUESTRE_* pour l'investissement, RAAGA_COMPTE_LOCATION_* pour la location).
 */
class VerifierProduction extends Command
{
    protected $signature = 'raaga:verifier-production';

    protected $description = 'Vérifie que la configuration est prête pour la production (démo, paiement simulé, comptes de destination).';

    /** Variables obligatoires : clé de configuration => nom de la variable d'environnement. */
    public const VARIABLES = [
        'raaga.compte_sequestre.titulaire' => 'RAAGA_SEQUESTRE_TITULAIRE',
        'raaga.compte_sequestre.banque' => 'RAAGA_SEQUESTRE_BANQUE',
        'raaga.compte_sequestre.numero' => 'RAAGA_SEQUESTRE_NUMERO',
        'raaga.compte_location.titulaire' => 'RAAGA_COMPTE_LOCATION_TITULAIRE',
        'raaga.compte_location.banque' => 'RAAGA_COMPTE_LOCATION_BANQUE',
        'raaga.compte_location.numero' => 'RAAGA_COMPTE_LOCATION_NUMERO',
    ];

    public function handle(ComptesDemo $comptes): int
    {
        $problemes = [];

        if (config('raaga.demo')) {
            $problemes[] = 'RAAGA_DEMO=true : les données de démonstration doivent être coupées (RAAGA_DEMO=false).';
        }
        if (($n = $comptes->actifs()->count()) > 0) {
            $problemes[] = $n.' compte(s) de démonstration actif(s) (mot de passe = adresse e-mail) : lancez « php artisan raaga:comptes-demo --desactiver ».';
        }
        if (! $this->operateurReel()) {
            $problemes[] = 'Aucun opérateur de paiement réel configuré (RAAGA_PAIEMENT_FOURNISSEUR) : le paiement simulé est interdit en production.';
        }
        foreach (self::VARIABLES as $cle => $variable) {
            if (blank(config($cle))) {
                $problemes[] = 'Variable manquante : '.$variable.'.';
            }
        }

        if ($problemes !== []) {
            foreach ($problemes as $probleme) {
                $this->error('✗ '.$probleme);
            }
            $this->error('Mise en production refusée : '.count($problemes).' point(s) à corriger.');

            return self::FAILURE;
        }

        $this->info('✓ Aucun compte ni aucune donnée de démonstration, opérateur de paiement réel, comptes de destination renseignés.');

        return self::SUCCESS;
    }

    /** Un opérateur réel est configuré et se charge (jamais le simulateur). */
    private function operateurReel(): bool
    {
        if (in_array(config('raaga.paiement.fournisseur'), [null, '', 'simule'], true)) {
            return false;
        }
        try {
            return ! app(PaymentProvider::class)->estSimule();
        } catch (Throwable) {
            return false;
        }
    }
}
