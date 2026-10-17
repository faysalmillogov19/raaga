<?php

use App\Enums\Role;
use App\Http\Controllers\CartePhotoController;
use App\Http\Controllers\Compte\AvanceController;
use App\Http\Controllers\Compte\BoutiquesController;
use App\Http\Controllers\Compte\CarteController;
use App\Http\Controllers\Compte\CautionController;
use App\Http\Controllers\Compte\ConnexionController;
use App\Http\Controllers\Compte\EspaceController;
use App\Http\Controllers\Compte\InscriptionController;
use App\Http\Controllers\Compte\PaiementController;
use App\Http\Controllers\Compte\ProfilController as CompteProfilController;
use App\Http\Controllers\Compte\SouscriptionController;
use App\Http\Controllers\Compte\VerificationController;
use App\Http\Controllers\Publique\AccueilController;
use App\Http\Controllers\Publique\AlerteSouscriptionController;
use App\Http\Controllers\Publique\ArmoiriesController;
use App\Http\Controllers\Publique\ContactController;
use App\Http\Controllers\Publique\DiapositiveController;
use App\Http\Controllers\Publique\GuichetController;
use App\Http\Controllers\Publique\ImagePlateformeController;
use App\Http\Controllers\Publique\InvestController;
use App\Http\Controllers\Publique\PageController;
use App\Http\Controllers\Publique\SmsRecetteController;
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
Route::get('/plateforme/diapositives/{image}/{largeur}', DiapositiveController::class)->where('image', '[0-9a-f-]{36}')->whereNumber('largeur')->name('diapositive');
Route::get('/plateforme/images/{champ}', ImagePlateformeController::class)->whereAlpha('champ')->name('plateforme.image');
Route::get('/contact', [ContactController::class, 'show'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,10')->name('contact.store');
Route::get('/guichets', GuichetController::class)->name('guichets');

// SMS simulés, pour saisir les codes de confirmation en recette (jamais en production).
Route::get('/recette/sms', SmsRecetteController::class)->name('recette.sms');
Route::redirect('/admin/recette', '/recette/sms');
Route::redirect('/admin/recette/sms', '/recette/sms');

Route::controller(PageController::class)->group(function () {
    // Pages d'information sur les actions : visibles si la « présentation des actions » est décidée.
    Route::get('/offre', 'offre')->middleware('actions.presentation')->name('offre');
    Route::get('/transparence', 'transparence')->middleware('actions.presentation')->name('transparence');
    Route::get('/faq', 'faq')->name('faq');
    Route::get('/actualites/{slug}', 'actualite')->middleware('actions.presentation')->name('actualites.show');

    // Tunnel de souscription : pages d'attente jusqu'à la mise en service des parcours.

    Route::get('/mentions-legales', 'legal')->defaults('page', 'mentions')->name('legal.mentions');
    Route::get('/donnees-personnelles', 'legal')->defaults('page', 'donnees')->name('legal.donnees');
    Route::get('/accessibilite', 'legal')->defaults('page', 'accessibilite')->name('legal.accessibilite');
});

// Souscription d'actions : seulement si elle est ouverte (paramètre confirmé ET tranche ouverte) ; sinon, page
// « Souscription bientôt ouverte » et inscription à l'alerte SMS.
Route::get('/souscription-bientot', [AlerteSouscriptionController::class, 'show'])->middleware('actions.presentation')->name('souscription.bientot');
// Inscription à l'alerte : aussi depuis l'annonce de l'accueil, avant que la présentation des actions soit activée.
Route::post('/souscription-bientot', [AlerteSouscriptionController::class, 'store'])->middleware('throttle:10,1')->name('souscription.bientot.store');
Route::middleware('actions.souscription')->controller(SouscriptionController::class)->group(function () {
    Route::get('/souscrire', 'create')->name('souscrire');
    Route::get('/souscrire/reglement', 'reglement')->name('souscrire.reglement');
    Route::post('/souscrire', 'store')->middleware(['auth', 'role:citoyen', 'throttle:10,1'])->name('souscrire.store');
});

// Boutiques du marché, au premier arrivé : plan public et règlement ; récapitulatif, réservation et suivi (citoyen connecté).
Route::controller(BoutiquesController::class)->prefix('boutiques')->name('boutiques')->group(function () {
    Route::get('/', 'index');
    Route::get('/reglement', 'reglement')->name('.reglement');
    Route::middleware(['auth', 'role:citoyen'])->group(function () {
        Route::get('/plan/{code}', 'boutique')->name('.boutique');
        // Tentatives limitées par utilisateur (5 par minute) sur l'action de réservation.
        Route::post('/plan/{code}/reserver', 'reserver')->middleware('throttle:5,1')->name('.reserver');
        Route::get('/reservations/{reference}', 'suivi')->name('.suivi');
    });
});

// Caution d'une candidature : même module de paiement que les souscriptions (titulaire uniquement).
Route::middleware(['auth', 'role:citoyen'])->controller(CautionController::class)->prefix('boutiques/reservations/{reference}/caution')->name('boutiques.reservation.')->group(function () {
    Route::get('/', 'show')->name('show');
    Route::post('/', 'payer')->middleware('throttle:10,1')->name('payer');
    Route::get('/statut', 'statut')->middleware('throttle:60,1')->name('statut');
    Route::post('/simuler', 'simuler')->middleware('throttle:10,1')->name('simuler');
    Route::post('/changer', 'changer')->name('changer');
    Route::post('/preuve', 'preuve')->middleware('throttle:10,1')->name('preuve');
    Route::get('/recu', 'recu')->name('recu');
});

// Avance d'une boutique réservée : même circuit que la caution (titulaire uniquement), payable encore en retard.
Route::middleware(['auth', 'role:citoyen'])->controller(AvanceController::class)->prefix('boutiques/reservations/{reference}/avance')->name('boutiques.avance.')->group(function () {
    Route::get('/', 'show')->name('show');
    Route::post('/', 'payer')->middleware('throttle:10,1')->name('payer');
    Route::get('/statut', 'statut')->middleware('throttle:60,1')->name('statut');
    Route::post('/simuler', 'simuler')->middleware('throttle:10,1')->name('simuler');
    Route::post('/changer', 'changer')->name('changer');
    Route::post('/preuve', 'preuve')->middleware('throttle:10,1')->name('preuve');
    Route::get('/recu', 'recu')->name('recu');
});

// Paiement d'une souscription réservée (citoyen connecté ; sans réservation, état vide).
Route::get('/paiement', [PaiementController::class, 'index'])->middleware('actions.presentation')->name('paiement');
Route::middleware(['actions.presentation', 'auth', 'role:citoyen'])->controller(PaiementController::class)->prefix('paiement/{reference}')->name('paiement.')->group(function () {
    Route::get('/', 'show')->name('show');
    Route::post('/', 'payer')->middleware('throttle:10,1')->name('payer');
    Route::get('/statut', 'statut')->middleware('throttle:60,1')->name('statut');
    Route::post('/simuler', 'simuler')->middleware('throttle:10,1')->name('simuler');
    Route::post('/changer', 'changer')->name('changer');
    Route::post('/preuve', 'preuve')->middleware('throttle:10,1')->name('preuve');
    Route::get('/confirmation', 'confirmation')->name('confirmation');
    Route::get('/recu', 'recu')->name('recu');
});

// RAAGA Invest : simulateur (public) et manifestation d'intérêt (citoyen connecté) ; aucun paiement.
Route::middleware('actions.presentation')->controller(InvestController::class)->prefix('invest')->name('invest.')->group(function () {
    Route::get('/simulateur', 'simulateur')->name('simulateur');
    Route::post('/interet', 'interet')->middleware(['auth', 'role:citoyen', 'throttle:10,1'])->name('interet');
});

// Mon espace : tableau de bord du citoyen (un autre profil voit un état vide).
Route::get('/espace', [EspaceController::class, 'show'])->middleware('auth')->name('espace');
Route::middleware(['actions.presentation', 'auth', 'role:citoyen'])->controller(EspaceController::class)->prefix('espace/documents')->name('espace.documents.')->group(function () {
    Route::get('/reglement/{souscription:reference}', 'reglement')->name('reglement');
    Route::get('/attestation/{ligne:numero_certificat}', 'attestation')->name('attestation');
});

// Vérification d'identité (citoyen) : dépôt des pièces, suivi, fichiers servis au seul titulaire.
// Ma carte : cartes COMMERÇANT et ACTIONNAIRE du citoyen ; photo d'une carte (titulaire et agents autorisés seulement).
Route::get('/espace/carte/{type?}', [CarteController::class, 'show'])
    ->whereIn('type', ['commercant', 'actionnaire'])->middleware(['auth', 'role:citoyen'])->name('espace.carte');
Route::get('/cartes/{carte}/photo', CartePhotoController::class)->whereNumber('carte')->middleware('auth')->name('cartes.photo');

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
