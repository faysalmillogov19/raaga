<?php

namespace App\Console\Commands;

use App\Enums\StatutReservationBoutique;
use App\Enums\TypeCarte;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Services\Boutiques\IdentifiantsCommercant;
use App\Services\Cartes\Cartes;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rattrapage (02/11/2026) : identifiant fiscal et numéro de registre Raaga des commerçants dont une boutique était déjà
 * attribuée avant leur mise en place (année de la première attribution), puis mise à jour de leur carte de commerçant
 * (nouvelle version, nouveaux fichiers). Sans effet pour un commerçant qui les a déjà : à lancer une fois au déploiement.
 */
class IdentifiantsCommercants extends Command
{
    protected $signature = 'raaga:identifiants-commercants';

    protected $description = 'Attribue l’identifiant fiscal et le numéro de registre Raaga aux commerçants déjà attributaires';

    public function handle(IdentifiantsCommercant $identifiants, Cartes $cartes): int
    {
        $premieres = ReservationBoutique::query()->where('statut', StatutReservationBoutique::Attribuee)
            ->selectRaw('user_id, min(coalesce(attribuee_le, updated_at)) as le')->groupBy('user_id')->pluck('le', 'user_id');
        $attribues = 0;
        foreach (User::query()->whereKey($premieres->keys())->whereNull('identifiant_fiscal_raaga')->orderBy('id')->get() as $user) {
            DB::transaction(fn () => $identifiants->attribuer($user, null, CarbonImmutable::parse($premieres[$user->id])));
            $cartes->synchroniser($user, TypeCarte::Commercant);
            $attribues++;
        }
        $this->info('Commerçants traités : '.$attribues.'.');

        return self::SUCCESS;
    }
}
