<?php

namespace App\Console\Commands;

use App\Enums\TypeCarte;
use App\Models\Carte;
use App\Models\User;
use App\Services\Cartes\Cartes;
use Illuminate\Console\Command;

/**
 * Rattrapage (03/11/2026) : la carte de commerçant porte désormais le numéro IFU du titulaire. Les cartes courantes déjà
 * émises sous un numéro RG-C le reprennent (nouvelle version, nouveaux fichiers) ; un titulaire sans IFU garde son
 * numéro RG-C jusqu'à ce qu'il le renseigne. Sans effet sur une carte déjà à jour : à lancer une fois au déploiement.
 */
class NumerosCartesCommercants extends Command
{
    protected $signature = 'raaga:numeros-cartes-commercants';

    protected $description = 'Donne le numéro IFU du titulaire aux cartes de commerçant déjà émises';

    public function handle(Cartes $cartes): int
    {
        $titulaires = Carte::query()->courantes()->where('type', TypeCarte::Commercant)->select('user_id');
        $misesAJour = 0;
        foreach (User::query()->whereIn('id', $titulaires)->whereNotNull('ifu')->orderBy('id')->get() as $user) {
            $avant = Cartes::courante($user, TypeCarte::Commercant)?->numero;
            if ($cartes->synchroniser($user, TypeCarte::Commercant)?->numero !== $avant) {
                $misesAJour++;
            }
        }
        $this->info('Cartes de commerçant mises à jour : '.$misesAJour.'.');

        return self::SUCCESS;
    }
}
