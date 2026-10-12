<?php

use App\Enums\Role;
use App\Http\Controllers\Compte\BoutiquesController;
use App\Http\Controllers\Compte\CautionController;
use App\Http\Controllers\Compte\ConnexionController;
use App\Http\Controllers\Compte\EspaceController;
use App\Http\Controllers\Compte\InscriptionController;
use App\Http\Controllers\Compte\PaiementController;
use App\Http\Controllers\Compte\ProfilController as CompteProfilController;
use App\Http\Controllers\Compte\SouscriptionController;
use App\Http\Controllers\Compte\VerificationController;
use App\Http\Controllers\Publique\AccueilController;
use App\Http\Controllers\Publique\ArmoiriesController;
use App\Http\Controllers\Publique\ContactController;
use App\Http\Controllers\Publique\GuichetController;
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
Route::get('/contact', [ContactController::class, 'show'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,10')->name('contact.store');
Route::get('/guichets', GuichetController::class)->name('guichets');

Route::controller(PageController::class)->group(function () {
    Route::get('/offre', 'offre')->name('offre');
    Route::get('/transparence', 'transparence')->name('transparence');
    Route::get('/faq', 'faq')->name('faq');
    Route::get('/actualites/{slug}', 'actualite')->name('actualites.show');

    // Tunnel de souscription : pages d'attente jusqu'à la mise en service des parcours.

    Route::get('/mentions-legales', 'legal')->defaults('page', 'mentions')->name('legal.mentions');
    Route::get('/donnees-personnelles', 'legal')->defaults('page', 'donnees')->name('legal.donnees');
    Route::get('/accessibilite', 'legal')->defaults('page', 'accessibilite')->name('legal.accessibilite');
});

// Souscription (citoyen connecté ; sans tranche ouverte, état vide visible de tous).
Route::controller(SouscriptionController::class)->group(function () {
    Route::get('/souscrire', 'create')->name('souscrire');
    Route::get('/souscrire/reglement', 'reglement')->name('souscrire.reglement');
    Route::post('/souscrire', 'store')->middleware(['auth', 'role:citoyen', 'throttle:10,1'])->name('souscrire.store');
});

// Boutiques du marché : présentation et règlement publics, candidature et suivi (citoyen connecté).
Route::controller(BoutiquesController::class)->prefix('boutiques')->name('boutiques')->group(function () {
    Route::get('/', 'index');
    Route::get('/reglement', 'reglement')->name('.reglement');
    Route::middleware(['auth', 'role:citoyen'])->group(function () {
        Route::get('/candidater', 'create')->name('.candidater');
        Route::post('/candidater', 'store')->middleware('throttle:10,1')->name('.candidater.store');
        Route::get('/candidatures/{reference}', 'candidature')->name('.candidature');
    });
});

// Caution d'une candidature : même module de paiement que les souscriptions (titulaire uniquement).
Route::middleware(['auth', 'role:citoyen'])->controller(CautionController::class)->prefix('boutiques/candidatures/{reference}/caution')->name('boutiques.caution.')->group(function () {
    Route::get('/', 'show')->name('show');
    Route::post('/', 'payer')->middleware('throttle:10,1')->name('payer');
    Route::get('/statut', 'statut')->middleware('throttle:60,1')->name('statut');
    Route::post('/simuler', 'simuler')->middleware('throttle:10,1')->name('simuler');
    Route::post('/changer', 'changer')->name('changer');
    Route::get('/recu', 'recu')->name('recu');
});

// Paiement d'une souscription réservée (citoyen connecté ; sans réservation, état vide).
Route::get('/paiement', [PaiementController::class, 'index'])->name('paiement');
Route::middleware(['auth', 'role:citoyen'])->controller(PaiementController::class)->prefix('paiement/{reference}')->name('paiement.')->group(function () {
    Route::get('/', 'show')->name('show');
    Route::post('/', 'payer')->middleware('throttle:10,1')->name('payer');
    Route::get('/statut', 'statut')->middleware('throttle:60,1')->name('statut');
    Route::post('/simuler', 'simuler')->middleware('throttle:10,1')->name('simuler');
    Route::post('/changer', 'changer')->name('changer');
    Route::get('/confirmation', 'confirmation')->name('confirmation');
    Route::get('/recu', 'recu')->name('recu');
});

// Mon espace : tableau de bord du citoyen (un autre profil voit un état vide).
Route::get('/espace', [EspaceController::class, 'show'])->middleware('auth')->name('espace');
Route::middleware(['auth', 'role:citoyen'])->controller(EspaceController::class)->prefix('espace/documents')->name('espace.documents.')->group(function () {
    Route::get('/reglement/{souscription:reference}', 'reglement')->name('reglement');
    Route::get('/attestation/{ligne:numero_certificat}', 'attestation')->name('attestation');
});

// Vérification d'identité (citoyen) : dépôt des pièces, suivi, fichiers servis au seul titulaire.
Route::middleware(['auth', 'role:citoyen'])->controller(VerificationController::class)->prefix('espace/verification')->name('espace.verification')->group(function () {
    Route::get('/', 'show');
    Route::post('/', 'store')->middleware('throttle:10,1')->name('.store');
    Route::get('/fichiers/{document}', 'fichier')->whereNumber('document')->name('.fichier');
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
// Agent de guichet : état vide en attendant l'interface guichet.
Route::view('/guichet', 'dashboards.show', ['role' => Role::AgentGuichet])->middleware(['auth', 'role:'.Role::AgentGuichet->value])->name('guichet');

Route::get('/dashboard', function () {
    return redirect()->route(auth()->user()->codeRole()->dashboardRoute());
})->middleware('auth')->name('dashboard');

// Un tableau de bord par rôle : /{prefixe}/dashboard, protégé par le middleware role.
Route::middleware('auth')->group(function () {
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

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
require __DIR__.'/institutionnel.php';
