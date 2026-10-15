<?php

namespace App\Console\Commands;

use App\Services\Paiement\OuverturePaiements;
use App\Services\Securite\ComptesDemo;
use Illuminate\Console\Command;

/**
 * Contrôles avant mise en production, à deux niveaux (appelé par deploy.sh) :
 *  - BLOQUANT (code d'erreur, déploiement arrêté) : données de démonstration actives, compte de démonstration actif,
 *    prestataire de paiement simulé configuré ;
 *  - AVERTISSEMENT (code 0) : aucun opérateur réel (« paiements fermés »), coordonnées des comptes de destination
 *    manquantes (RAAGA_SEQUESTRE_*, RAAGA_COMPTE_LOCATION_*). Les paiements restent alors fermés (voir OuverturePaiements).
 * --strict : les avertissements deviennent bloquants (avant l'ouverture d'une campagne).
 */
class VerifierProduction extends Command
{
    protected $signature = 'raaga:verifier-production {--strict : Les avertissements deviennent bloquants (avant l’ouverture d’une campagne)}';

    protected $description = 'Vérifie la configuration de production : bloquants (démo, paiement simulé) et avertissements (opérateur, comptes de destination).';

    /** Variables des comptes de destination : clé de configuration => nom de la variable d'environnement. */
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
        $bloquants = [];
        $avertissements = [];

        if (config('raaga.demo')) {
            $bloquants[] = 'RAAGA_DEMO=true : les données de démonstration doivent être coupées (RAAGA_DEMO=false).';
        }
        if (($n = $comptes->actifs()->count()) > 0) {
            $bloquants[] = $n.' compte(s) de démonstration actif(s) (mot de passe = adresse e-mail) : lancez « php artisan raaga:comptes-demo --desactiver ».';
        }
        if (config('raaga.paiement.fournisseur') === 'simule') {
            $bloquants[] = 'RAAGA_PAIEMENT_FOURNISSEUR=simule : le prestataire de paiement simulé est interdit en production.';
        } elseif (! OuverturePaiements::operateurReel()) {
            $avertissements[] = 'Aucun opérateur de paiement réel configuré : paiements fermés (aucune campagne ne s’ouvre, aucun citoyen ne paie).';
        }
        foreach (self::VARIABLES as $cle => $variable) {
            if (blank(config($cle))) {
                $avertissements[] = 'Variable manquante : '.$variable.' (paiements fermés vers ce compte).';
            }
        }

        foreach ($bloquants as $message) {
            $this->error('✗ BLOQUANT : '.$message);
        }
        $strict = (bool) $this->option('strict');
        foreach ($avertissements as $message) {
            $strict ? $this->error('✗ BLOQUANT (--strict) : '.$message) : $this->warn('! AVERTISSEMENT : '.$message);
        }

        $refus = count($bloquants) + ($strict ? count($avertissements) : 0);
        if ($refus > 0) {
            $this->error('Mise en production refusée : '.$refus.' point(s) bloquant(s).');

            return self::FAILURE;
        }
        $this->info($avertissements === []
            ? '✓ Prêt pour la production : aucune démonstration, opérateur de paiement réel, comptes de destination renseignés.'
            : '✓ Aucun point bloquant ('.count($avertissements).' avertissement(s) : paiements fermés tant qu’ils ne sont pas réglés).');

        return self::SUCCESS;
    }
}
