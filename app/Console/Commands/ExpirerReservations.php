<?php

namespace App\Console\Commands;

use App\Services\Boutiques\IdentitesAttributaires;
use App\Services\Boutiques\ListesAttente;
use App\Services\Boutiques\RelancesInteret;
use App\Services\Paiement\ServicePaiement;
use Illuminate\Console\Command;

/**
 * Expiration des souscriptions RÉSERVÉES non payées dans le délai de la tranche (valeur + durée, en secondes), et des
 * boutiques pré-réservées dont la caution n'est pas payée ; puis chaque boutique libre qui a une liste d'attente passe
 * au premier inscrit qui peut être retenu. Identité des attributaires : délai de correction fixé, levé ou dépassé
 * (IdentitesAttributaires). Boutiques sans personne à retenir : relance de la liste d'attente générale, puis
 * RÉGULATEUR prévenu (RelancesInteret).
 */
class ExpirerReservations extends Command
{
    protected $signature = 'raaga:expirer-reservations';

    protected $description = 'Expire les réservations de souscription non payées dans le délai';

    public function handle(ServicePaiement $paiements, ListesAttente $listes, IdentitesAttributaires $identites, RelancesInteret $relances): int
    {
        $nombre = $paiements->expirerReservations();
        $kyc = $identites->surveiller();
        $retenus = $listes->reevaluer();
        $r = $relances->executer();

        $this->info("{$nombre} réservation(s) expirée(s). {$retenus} attributaire(s) retenu(s) sur liste d'attente. "
            ."Identités à corriger : {$kyc['signalees']} signalée(s), {$kyc['levees']} corrigée(s), {$kyc['annulees']} réservation(s) annulée(s). "
            ."Relances de la liste générale : {$r['ouvertes']} ouverte(s), {$r['closes']} close(s).");

        return self::SUCCESS;
    }
}
