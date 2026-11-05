<?php

use App\Enums\StatutReservationBoutique;
use App\Enums\TypeCarte;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Cartes\Cartes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Libellés des sites (réunion du 05/11/2026) : « Arrondissement 1 Ouagadougou », « Arrondissement 4 Ouagadougou »,
 * « Sarfalao Bobo-Dioulasso », partout dans la plateforme. Sites repérés par leur code ; un site absent est ignoré.
 * Changement tracé au journal ; cartes de commerçant des attributaires de ces sites recalculées (nouvelle version).
 */
return new class extends Migration
{
    public const LIBELLES = [
        'RAA' => 'Arrondissement 1 Ouagadougou',
        'A04' => 'Arrondissement 4 Ouagadougou',
        'SAR' => 'Sarfalao Bobo-Dioulasso',
    ];

    public function up(): void
    {
        $renommes = [];
        foreach (self::LIBELLES as $code => $nom) {
            $site = DB::table('sites')->where('code', $code)->first(['id', 'nom']);
            if (! $site || $site->nom === $nom) {
                continue;
            }
            DB::table('sites')->where('id', $site->id)->update(['nom' => $nom, 'updated_at' => now()]);
            app(Journal::class)->ecrire(null, 'site.renomme', 'Site', $site->id, ['nom' => $site->nom], ['nom' => $nom]);
            $renommes[] = $site->id;
        }
        if ($renommes === []) {
            return;
        }

        $titulaires = ReservationBoutique::query()->where('statut', StatutReservationBoutique::Attribuee)
            ->whereHas('boutique', fn ($q) => $q->whereIn('site_id', $renommes))->distinct()->pluck('user_id');
        foreach (User::query()->whereIn('id', $titulaires)->get() as $user) {
            if (Cartes::courante($user, TypeCarte::Commercant)) {
                app(Cartes::class)->synchroniser($user, TypeCarte::Commercant);
            }
        }
    }

    public function down(): void
    {
        // Libellés corrigés : pas de retour aux anciens.
    }
};
