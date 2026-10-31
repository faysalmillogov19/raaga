<?php

namespace App\Console\Commands;

use App\Jobs\GenererFichiersCarte;
use App\Services\Cartes\NavigateurChrome;
use App\Services\Paiement\OuverturePaiements;
use App\Services\Securite\ComptesDemo;
use App\Support\GardeFouBase;
use App\Support\ImagesPlateforme;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Contrôles avant mise en production, à deux niveaux (appelé par deploy.sh) :
 *  - BLOQUANT (code d'erreur, déploiement arrêté) : données de démonstration actives, compte de démonstration actif,
 *    prestataire de paiement simulé configuré ;
 *  - AVERTISSEMENT (code 0) : aucun opérateur réel (« paiements fermés »), coordonnées des comptes de destination
 *    manquantes (RAAGA_SEQUESTRE_*, RAAGA_COMPTE_LOCATION_*). Les paiements restent alors fermés (voir OuverturePaiements).
 *    Clé des portiques (RAAGA_CLE_PORTIQUES) vide : l'interface de vérification des cartes pour les portiques refuse tout.
 *    Armoiries ou logo de la carte pris dans les fichiers fournis avec le code (rien de déposé) et de moins de 600 px de
 *    haut : flous à l'impression de la carte (30/10/2026).
 *  - fichiers des cartes générés en tâche de fond par Chrome (30/10/2026) : BLOQUANT si Chrome ne démarre pas (essai
 *    réel) ou si la file d'attente n'est pas réelle (sync, null) ou sans sa table ; AVERTISSEMENT pour les générations de
 *    carte en échec (avec leur nombre) et les tâches en attente depuis plus de 10 minutes (worker arrêté).
 * --strict : les avertissements deviennent bloquants (avant l'ouverture d'une campagne).
 * Contrôle aussi que les commandes du poste de développement (raaga:inventaire) refusent de tourner ici : BLOQUANT sinon.
 */
class VerifierProduction extends Command
{
    protected $signature = 'raaga:verifier-production {--strict : Les avertissements deviennent bloquants (avant l’ouverture d’une campagne)}';

    protected $description = 'Vérifie la configuration de production : bloquants (démo, paiement simulé) et avertissements (opérateur, comptes de destination, clé des portiques).';

    /** Variables des comptes de destination : clé de configuration => nom de la variable d'environnement. */
    /** Hauteur minimale (px) des armoiries et du logo imprimés sur la carte. */
    public const HAUTEUR_IMPRESSION = 600;

    /** Images de l'onglet « Identité » imprimées sur la carte : champ => libellé de l'avertissement. */
    public const IMAGES_CARTE = ['armoiries' => 'armoiries', 'logoHorizontal' => 'logo'];

    public const VARIABLES = [
        'raaga.compte_sequestre.titulaire' => 'RAAGA_SEQUESTRE_TITULAIRE',
        'raaga.compte_sequestre.banque' => 'RAAGA_SEQUESTRE_BANQUE',
        'raaga.compte_sequestre.numero' => 'RAAGA_SEQUESTRE_NUMERO',
        'raaga.compte_location.titulaire' => 'RAAGA_COMPTE_LOCATION_TITULAIRE',
        'raaga.compte_location.banque' => 'RAAGA_COMPTE_LOCATION_BANQUE',
        'raaga.compte_location.numero' => 'RAAGA_COMPTE_LOCATION_NUMERO',
    ];

    /** Attente (minutes) au-delà de laquelle une tâche non traitée signale un worker arrêté. */
    public const ATTENTE_WORKER = 10;

    public function handle(ComptesDemo $comptes, NavigateurChrome $chrome): int
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
            $avertissements[] = 'Aucun opérateur de paiement réel configuré : paiements par opérateur fermés (mobile money, carte, Faso Arzeka) ; seuls les paiements manuels (virement, versement, espèces) restent ouverts vers les comptes renseignés.';
        }
        foreach (self::VARIABLES as $cle => $variable) {
            if (blank(config($cle))) {
                $avertissements[] = 'Variable manquante : '.$variable.' (tous les paiements fermés vers ce compte, manuels compris).';
            }
        }

        // Inventaires locaux : la commande doit refuser de tourner en production (et ici, si ce n'est pas un poste local).
        if (GardeFouBase::posteLocal('production')) {
            $bloquants[] = 'raaga:inventaire serait autorisée en production : elle est réservée au poste de développement (APP_ENV=local).';
        } elseif (! GardeFouBase::posteLocal() && $this->callSilently('raaga:inventaire', ['--dossier' => storage_path('framework/inventaire-refuse')]) !== self::FAILURE) {
            $bloquants[] = 'raaga:inventaire ne refuse pas de tourner dans cet environnement ('.app()->environment().').';
        }

        if (blank(config('raaga.cartes.cle_portiques'))) {
            $avertissements[] = 'Variable manquante : RAAGA_CLE_PORTIQUES (interface de vérification des cartes pour les portiques fermée : toute demande est refusée).';
        }

        // Carte d'identification : armoiries et logo par défaut trop petits pour l'impression (un fichier déposé n'est pas contrôlé ici).
        foreach (self::IMAGES_CARTE as $champ => $libelle) {
            $chemin = ImagesPlateforme::deposee($champ) ? null : ImagesPlateforme::chemin($champ);
            $hauteur = $chemin && is_file($chemin) ? (getimagesize($chemin)[1] ?? 0) : null;
            if ($hauteur !== null && $hauteur < self::HAUTEUR_IMPRESSION) {
                $avertissements[] = ucfirst($libelle).' par défaut en basse résolution ('.$hauteur.' px de haut, '.self::HAUTEUR_IMPRESSION.' attendus pour l’impression de la carte), déposer la version officielle dans « Paramètres de la plateforme », section Identité.';
            }
        }

        $this->fileAttente($bloquants, $avertissements);
        try {
            $chrome->essai();
        } catch (Throwable $e) {
            $bloquants[] = 'Chrome ne démarre pas (RAAGA_CHROME_BINAIRE) : les fichiers des cartes ne peuvent pas être générés. '.$e->getMessage();
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
            ? '✓ Prêt pour la production : aucune démonstration, opérateur de paiement réel, comptes de destination et clé des portiques renseignés.'
            : '✓ Aucun point bloquant ('.count($avertissements).' avertissement(s) : services concernés fermés tant qu’ils ne sont pas réglés).');

        return self::SUCCESS;
    }

    /**
     * File d'attente des fichiers des cartes : connexion réelle et sa table, générations en échec, worker en marche.
     *
     * @param  list<string>  $bloquants
     * @param  list<string>  $avertissements
     */
    private function fileAttente(array &$bloquants, array &$avertissements): void
    {
        $connexion = (string) config('queue.default');
        $pilote = config('queue.connections.'.$connexion.'.driver');
        if (in_array($pilote, [null, 'sync', 'null'], true)) {
            $bloquants[] = 'QUEUE_CONNECTION='.$connexion.' : les fichiers des cartes doivent être générés en tâche de fond (QUEUE_CONNECTION=database et worker « php artisan queue:work » permanent).';

            return;
        }
        if ($pilote === 'database') {
            $table = (string) config('queue.connections.'.$connexion.'.table', 'jobs');
            if (! Schema::hasTable($table)) {
                $bloquants[] = 'Table de la file d’attente absente ('.$table.') : lancez « php artisan migrate ».';

                return;
            }
            $enAttente = DB::table($table)->whereNull('reserved_at')->where('available_at', '<', now()->subMinutes(self::ATTENTE_WORKER)->getTimestamp())->count();
            if ($enAttente > 0) {
                $avertissements[] = $enAttente.' tâche(s) en attente depuis plus de '.self::ATTENTE_WORKER.' minutes : le worker de la file d’attente (php artisan queue:work) ne tourne pas.';
            }
        }

        $echecs = (string) config('queue.failed.table', 'failed_jobs');
        if (Schema::hasTable($echecs)) {
            $n = DB::table($echecs)->where('payload', 'like', '%'.class_basename(GenererFichiersCarte::class).'%')->count();
            if ($n > 0) {
                $avertissements[] = $n.' génération(s) de fichiers de carte en échec (cause au journal de l’application) : relancez « php artisan raaga:cartes-fichiers --toutes --manquants ».';
            }
        }
    }
}
