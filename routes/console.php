<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Rappels (J-15, J-3, J), retards et relances des appels de fonds des actions A.
Schedule::command('raaga:appels-de-fonds')->dailyAt('07:00');

// Réservations de souscription non payées dans le délai de la tranche : passage EXPIRÉE.
Schedule::command('raaga:expirer-reservations')->everyFiveMinutes()->withoutOverlapping();
