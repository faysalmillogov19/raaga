<?php

namespace App\Services\Securite;

use App\Models\User;
use App\Services\Audit\Journal;
use Database\Seeders\ComptesDemoSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * Comptes de démonstration encore actifs : adresse du domaine de démonstration (@raaga.test), ou mot de passe égal à
 * l'adresse e-mail. Jamais en production : « raaga:comptes-demo --desactiver » les désactive, « raaga:verifier-production »
 * arrête le déploiement s'il en reste.
 */
class ComptesDemo
{
    public function __construct(private readonly Journal $journal) {}

    /** @return Collection<int, User> */
    public function actifs(): Collection
    {
        return User::query()->where('actif', true)->whereNotNull('email')->orderBy('id')->get()
            ->filter(fn (User $u) => str_ends_with(mb_strtolower($u->email), ComptesDemoSeeder::DOMAINE)
                || ($u->password !== null && Hash::check($u->email, $u->password)))
            ->values();
    }

    /** Désactive le compte (jamais supprimé) et l'inscrit au journal d'audit. */
    public function desactiver(User $user): void
    {
        $user->forceFill(['actif' => false])->save();
        $this->journal->ecrire(null, 'compte.demo_desactive', 'User', $user->id, ['actif' => true], ['actif' => false, 'email' => $user->email]);
    }
}
