<?php

use App\Http\Controllers\Admin\ConnexionController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Institutionnel\ActionsController;
use App\Http\Controllers\Institutionnel\AppelsController;
use App\Http\Controllers\Institutionnel\ConventionController;
use App\Http\Controllers\Institutionnel\DividendesController;
use App\Http\Controllers\Institutionnel\EspaceController;
use App\Http\Controllers\Institutionnel\OrganisationController;
use Illuminate\Support\Facades\Route;

/*
| Espace des investisseurs institutionnels (/institutionnel) : actions A.
| Connexion e-mail + mot de passe + TOTP (mêmes règles que le back-office, espace « institutionnel ») ;
| chaque écran est limité à l'institution de l'utilisateur connecté (403 sinon).
*/

Route::prefix('institutionnel')->name('institutionnel.')->group(function () {

    Route::controller(ConnexionController::class)->prefix('connexion')->name('connexion')
        ->group(function () {
            Route::get('/', 'create')->defaults('espace', 'institutionnel');
            Route::post('/', 'store')->defaults('espace', 'institutionnel')->middleware('throttle:20,1');
            Route::get('/totp', 'totp')->defaults('espace', 'institutionnel')->name('.totp');
            Route::post('/totp', 'verifierTotp')->defaults('espace', 'institutionnel')->middleware('throttle:20,1');
            Route::get('/enrolement', 'enrolement')->defaults('espace', 'institutionnel')->name('.enrolement');
            Route::post('/enrolement', 'confirmerEnrolement')->defaults('espace', 'institutionnel')->middleware('throttle:20,1');
            Route::get('/codes-secours', 'codesSecours')->defaults('espace', 'institutionnel')->name('.codes-secours');
            Route::post('/codes-secours', 'terminerEnrolement')->defaults('espace', 'institutionnel');
        });

    Route::get('/invitation/{jeton}', [InvitationController::class, 'show'])->defaults('espace', 'institutionnel')->name('invitation');
    Route::post('/invitation/{jeton}', [InvitationController::class, 'store'])->defaults('espace', 'institutionnel')->middleware('throttle:10,1');

    Route::middleware('backoffice:institutionnel')->group(function () {
        Route::post('/deconnexion', [ConnexionController::class, 'destroy'])->defaults('espace', 'institutionnel')->name('deconnexion');

        Route::get('/', [EspaceController::class, 'tableau'])->name('tableau');
        Route::get('/contact', [EspaceController::class, 'contact'])->name('contact');
        Route::get('/dividendes', [DividendesController::class, 'index'])->name('dividendes');
        Route::get('/dividendes/{distribution}/avis', [DividendesController::class, 'avis'])->name('dividendes.avis');
        foreach (['projet', 'assemblees'] as $rubrique) {
            Route::get('/'.$rubrique, [EspaceController::class, 'aVenir'])->defaults('rubrique', $rubrique)->name($rubrique);
        }

        Route::get('/convention', [ConventionController::class, 'show'])->name('convention');
        Route::get('/convention/{convention}/pdf', [ConventionController::class, 'pdf'])->name('convention.pdf');
        Route::post('/convention/{convention}/signer', [ConventionController::class, 'signer'])->middleware('throttle:10,1')->name('convention.signer');
        Route::post('/convention/{convention}/deposer', [ConventionController::class, 'deposer'])->name('convention.deposer');

        Route::get('/appels-de-fonds', [AppelsController::class, 'index'])->name('appels');
        Route::get('/appels-de-fonds/{appel}', [AppelsController::class, 'show'])->name('appels.show');
        Route::get('/appels-de-fonds/{appel}/avis', [AppelsController::class, 'avis'])->name('appels.avis');
        Route::post('/appels-de-fonds/{appel}/virement', [AppelsController::class, 'declarer'])->name('appels.declarer');

        Route::get('/actions', [ActionsController::class, 'index'])->name('actions');
        Route::get('/actions/{ligne}/attestation', [ActionsController::class, 'attestation'])->name('actions.attestation');

        Route::get('/organisation', [OrganisationController::class, 'show'])->name('organisation');
        Route::post('/organisation/pieces', [OrganisationController::class, 'deposer'])->name('organisation.pieces');
        Route::put('/organisation/rib', [OrganisationController::class, 'rib'])->name('organisation.rib');
        Route::post('/organisation/utilisateurs', [OrganisationController::class, 'inviter'])->name('organisation.inviter');
        Route::post('/organisation/utilisateurs/{utilisateur}/desactiver', [OrganisationController::class, 'desactiver'])->name('organisation.desactiver');
        Route::post('/organisation/utilisateurs/{utilisateur}/reactiver', [OrganisationController::class, 'reactiver'])->name('organisation.reactiver');
    });
});
