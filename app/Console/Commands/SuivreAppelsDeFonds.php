<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Enums\StatutAppelFonds;
use App\Models\AppelFonds;
use App\Models\User;
use App\Notifications\AlerteRetardAppelFonds;
use App\Notifications\AppelFondsNotification;
use App\Services\Institutionnels\AppelsDeFonds;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;

/**
 * Suivi quotidien des appels de fonds : avis au signataire à J-15, J-3 et le jour de l'échéance ;
 * après l'échéance, passage EN RETARD, relance du signataire (puis chaque semaine) et alerte du VALIDATEUR.
 * Chaque envoi est noté dans l'appel (« rappels ») : jamais deux fois le même.
 */
class SuivreAppelsDeFonds extends Command
{
    protected $signature = 'raaga:appels-de-fonds';

    protected $description = 'Rappels, retards et relances des appels de fonds des actions A';

    public function handle(AppelsDeFonds $appels): int
    {
        $aujourdhui = now()->startOfDay();
        $envois = 0;

        AppelFonds::query()
            ->whereNot('statut', StatutAppelFonds::Recu)
            ->whereHas('convention', fn (Builder $q) => $q->whereNotNull('signee_le'))
            ->with(['convention', 'institution'])
            ->each(function (AppelFonds $appel) use ($appels, $aujourdhui, &$envois) {
                $rappels = $appel->rappels ?? [];
                $jours = (int) $aujourdhui->diffInDays($appel->echeance->copy()->startOfDay(), false);
                $signataires = $appel->institution->signatairesActifs()->get();

                if ($jours >= 0 && in_array($jours, config('raaga.institutionnels.rappels_jours'), true) && ! in_array("J-{$jours}", $rappels, true)) {
                    Notification::send($signataires, new AppelFondsNotification($appel, $jours));
                    $rappels[] = "J-{$jours}";
                    $envois++;
                }

                if ($jours < 0) {
                    $appels->recalculerStatut($appel);

                    // Relance le lendemain de l'échéance, puis chaque semaine ; alerte du VALIDATEUR une fois.
                    $relance = 'relance-'.intdiv(-$jours - 1, 7);
                    if ($appel->statut === StatutAppelFonds::EnRetard && ! in_array($relance, $rappels, true)) {
                        Notification::send($signataires, new AppelFondsNotification($appel, null));
                        $rappels[] = $relance;
                        $envois++;

                        if (! in_array('alerte', $rappels, true)) {
                            Notification::send(User::query()->where('actif', true)
                                ->whereHas('role', fn (Builder $q) => $q->where('code', Role::Validateur->value))->get(), new AlerteRetardAppelFonds($appel));
                            $rappels[] = 'alerte';
                        }
                    }
                }

                $appel->forceFill(['rappels' => $rappels])->save();
            });

        $this->info("Appels de fonds suivis : {$envois} envoi(s).");

        return self::SUCCESS;
    }
}
