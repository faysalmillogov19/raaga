<?php

use App\Enums\Role;
use App\Http\Controllers\Compte\ConnexionController;
use App\Http\Controllers\Compte\InscriptionController;
use App\Http\Controllers\Compte\ProfilController as CompteProfilController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Publique\AccueilController;
use App\Http\Controllers\Publique\ArmoiriesController;
use App\Http\Controllers\Publique\ImagePlateformeController;
use App\Http\Controllers\Publique\PageController;
use Illuminate\Support\Facades\Route;

// Pages publiques
Route::get('/', AccueilController::class)->name('accueil');

// Armoiries de la plateforme (déposées dans les paramètres de la plateforme).
Route::get('/armoiries', ArmoiriesController::class)->name('armoiries');
// Logos de la plateforme (paramètres de la plateforme, sinon public/images/logo).
// Manifeste de l'application web : nom lu dans les paramètres de la plateforme.
Route::get('/site.webmanifest', fn () => response()->json([
    'name' => plateforme('identite.nomPlateforme'),
    'short_name' => 'Raaga',
    'lang' => 'fr',
    'start_url' => '/',
    'display' => 'browser',
    'background_color' => '#FFFFFF',
    'theme_color' => '#005C2B',
    'icons' => [
        ['src' => asset('images/logo/icone-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => asset('images/logo/icone-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
    ],
], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->name('manifeste');
Route::get('/plateforme/images/{champ}', ImagePlateformeController::class)->whereAlpha('champ')->name('plateforme.image');
Route::controller(PageController::class)->group(function () {
    Route::get('/offre', 'offre')->name('offre');
    Route::get('/transparence', 'transparence')->name('transparence');
    Route::get('/faq', 'faq')->name('faq');
    Route::get('/actualites/{slug}', 'actualite')->name('actualites.show');

    // Tunnel de souscription : pages d'attente jusqu'à la mise en service (étape 3).
    Route::get('/souscrire', 'preparation')->defaults('page', 'souscrire')->name('souscrire');
    Route::get('/paiement', 'preparation')->defaults('page', 'paiement')->name('paiement');
    Route::get('/espace', 'preparation')->defaults('page', 'espace')->middleware('auth')->name('espace');
    Route::get('/espace/verification', 'preparation')->defaults('page', 'verification')->middleware('auth')->name('espace.verification');

    Route::get('/mentions-legales', 'legal')->defaults('page', 'mentions')->name('legal.mentions');
    Route::get('/donnees-personnelles', 'legal')->defaults('page', 'donnees')->name('legal.donnees');
    Route::get('/accessibilite', 'legal')->defaults('page', 'accessibilite')->name('legal.accessibilite');
});

// Mon profil (citoyen) : informations personnelles et téléphone (confirmé par SMS).
Route::middleware(['auth', 'role:citoyen'])->controller(CompteProfilController::class)->prefix('espace/profil')->name('espace.profil')->group(function () {
    Route::get('/', 'show');
    Route::post('/', 'update')->name('.update');
    Route::post('/telephone', 'envoyerCodeTelephone')->middleware('throttle:10,1')->name('.telephone');
    Route::post('/telephone/confirmer', 'confirmerTelephone')->middleware('throttle:20,1')->name('.telephone.confirmer');
    Route::post('/telephone/renvoyer', 'renvoyerCodeTelephone')->middleware('throttle:10,1')->name('.telephone.renvoyer');
    Route::post('/telephone/annuler', 'annulerTelephone')->name('.telephone.annuler');
});

// Compte citoyen : connexion et inscription par téléphone + code SMS.
Route::get('/inscription', [InscriptionController::class, 'create'])->name('inscription');

Route::middleware('guest')->group(function () {
    Route::controller(ConnexionController::class)->prefix('connexion')->name('connexion')->group(function () {
        Route::get('/', 'create');
        Route::post('/telephone', 'envoyerCode')->middleware('throttle:10,1')->name('.telephone');
        Route::post('/code', 'verifierCode')->middleware('throttle:20,1')->name('.code');
        Route::post('/renvoyer', 'renvoyer')->middleware('throttle:10,1')->name('.renvoyer');
        Route::get('/modifier', 'modifier')->name('.modifier');
    });

    Route::controller(InscriptionController::class)->prefix('inscription')->name('inscription.')->group(function () {
        Route::post('/telephone', 'envoyerCode')->middleware('throttle:10,1')->name('telephone');
        Route::post('/code', 'verifierCode')->middleware('throttle:20,1')->name('code');
        Route::post('/renvoyer', 'renvoyer')->middleware('throttle:10,1')->name('renvoyer');
        Route::post('/retour', 'retour')->name('retour');
        Route::post('/identite', 'store')->middleware('throttle:10,1')->name('identite');
    });
});

// Redirige chaque utilisateur vers le tableau de bord de son rôle.
Route::get('/dashboard', function () {
    return redirect()->route(auth()->user()->codeRole()->dashboardRoute());
})->middleware(['auth', 'verified'])->name('dashboard');

// Un tableau de bord par rôle : /{prefixe}/dashboard, protégé par le middleware role.
Route::middleware(['auth', 'verified'])->group(function () {
    foreach (Role::cases() as $role) {
        if ($role->estEspaceSecurise()) {
            continue; // Back-office : /admin (routes/admin.php) ; institutionnels : /institutionnel.
        }

        Route::prefix($role->prefix())
            ->name($role->prefix().'.')
            ->middleware('role:'.$role->value)
            ->group(function () use ($role) {
                Route::view('/dashboard', 'dashboards.show', ['role' => $role])->name('dashboard');
            });
    }
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
require __DIR__.'/institutionnel.php';
