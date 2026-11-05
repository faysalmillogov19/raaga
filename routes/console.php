<?php

use App\Support\Planificateur;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Battement du planificateur (05/11/2026) : prouve que la tâche cron « schedule:run » tourne chaque minute
// (contrôlé par raaga:verifier-production).
Schedule::call(fn () => Planificateur::battre())->everyMinute()->name('raaga-battement-planificateur');

// Rappels (J-15, J-3, J), retards et relances des appels de fonds des actions A.
Schedule::command('raaga:appels-de-fonds')->dailyAt('07:00');

// Réservations de souscription non payées dans le délai de la tranche : passage EXPIRÉE.
Schedule::command('raaga:expirer-reservations')->everyFiveMinutes()->withoutOverlapping();

// Relances des paiements attendus (souscriptions et cautions) : 24 h avant la fin du délai, puis à l'expiration.
Schedule::command('raaga:relancer-paiements')->everyFifteenMinutes()->withoutOverlapping();

// Cartes Raaga : renouvellement, expiration, avertissement avant la fin de la pièce d'identité.
Schedule::command('raaga:cartes')->dailyAt('06:30');

// Formalisation différée (05/11/2026) : rappels à 2 mois, 1 mois et 15 jours de l'échéance, alerte au back-office à l'échéance.
Schedule::command('raaga:formalisation')->dailyAt('08:00');

// Campagnes de boutiques : ouverture à la date d'ouverture, clôture à la date de clôture.
Schedule::command('raaga:campagnes-boutiques')->everyMinute()->withoutOverlapping();

// Tranches d'actions : ouverture à la date d'ouverture, clôture à la date de clôture (prolongeable par avenant).
Schedule::command('raaga:tranches')->everyMinute()->withoutOverlapping();
