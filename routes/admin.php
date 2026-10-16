<?php

use App\Enums\Droit;
use App\Http\Controllers\Admin\AppelsFondsController;
use App\Http\Controllers\Admin\AssistanceController;
use App\Http\Controllers\Admin\AValiderController;
use App\Http\Controllers\Admin\BrouillonsController;
use App\Http\Controllers\Admin\CadreController;
use App\Http\Controllers\Admin\CampagnesBoutiquesController;
use App\Http\Controllers\Admin\CapacitesController;
use App\Http\Controllers\Admin\CautionsRembourserController;
use App\Http\Controllers\Admin\ConnexionController;
use App\Http\Controllers\Admin\ConnexionsController;
use App\Http\Controllers\Admin\ConventionsController;
use App\Http\Controllers\Admin\DividendesController;
use App\Http\Controllers\Admin\GrillesTarifairesController;
use App\Http\Controllers\Admin\ImportBoutiquesController;
use App\Http\Controllers\Admin\ImportsController;
use App\Http\Controllers\Admin\ImportSitesController;
use App\Http\Controllers\Admin\ImportsValidationController;
use App\Http\Controllers\Admin\InstitutionsController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\KybController;
use App\Http\Controllers\Admin\ManifestationsInteretController;
use App\Http\Controllers\Admin\NiveauxZonesController;
use App\Http\Controllers\Admin\PaiementsController;
use App\Http\Controllers\Admin\ParametresInvestissementController;
use App\Http\Controllers\Admin\ParametresPaiementsController;
use App\Http\Controllers\Admin\PhaseController;
use App\Http\Controllers\Admin\PiecesKycController;
use App\Http\Controllers\Admin\PlateformeController;
use App\Http\Controllers\Admin\ProfilController;
use App\Http\Controllers\Admin\ReferentielBoutiquesController;
use App\Http\Controllers\Admin\RegistreAController;
use App\Http\Controllers\Admin\RemboursementsController;
use App\Http\Controllers\Admin\SectionController;
use App\Http\Controllers\Admin\SiteFinancesController;
use App\Http\Controllers\Admin\SitesController;
use App\Http\Controllers\Admin\SoumissionGroupeeController;
use App\Http\Controllers\Admin\TranchesController;
use App\Http\Controllers\Admin\TypesBoutiqueController;
use App\Http\Controllers\Admin\UtilisateursController;
use App\Http\Controllers\Admin\VerificationsIdentiteController;
use App\Support\MenuBackoffice;
use Illuminate\Support\Facades\Route;

/*
| Back-office (/admin) : SUPER_ADMIN, VALIDATEUR, REGULATEUR.
| Chaque route est protégée côté serveur par le middleware « backoffice » (connexion complète
| avec TOTP, session) et par le droit de la section (« can:… »). Masquer un menu ne suffit pas.
*/

Route::prefix('admin')->name('admin.')->group(function () {

    // Connexion : e-mail + mot de passe, puis TOTP (ou enrôlement à la première connexion).
    Route::controller(ConnexionController::class)->prefix('connexion')->name('connexion')->group(function () {
        Route::get('/', 'create');
        Route::post('/', 'store')->middleware('throttle:20,1');
        Route::get('/totp', 'totp')->name('.totp');
        Route::post('/totp', 'verifierTotp')->middleware('throttle:20,1');
        Route::get('/enrolement', 'enrolement')->name('.enrolement');
        Route::post('/enrolement', 'confirmerEnrolement')->middleware('throttle:20,1');
        Route::get('/codes-secours', 'codesSecours')->name('.codes-secours');
        Route::post('/codes-secours', 'terminerEnrolement');
    });

    // Lien d'invitation ou de nouvel accès (jeton à usage unique, valable 48 h).
    Route::get('/invitation/{jeton}', [InvitationController::class, 'show'])->name('invitation');
    Route::post('/invitation/{jeton}', [InvitationController::class, 'store'])->middleware('throttle:10,1');

    // Anciennes adresses du référentiel (avant le groupe « Référencement ») : redirection permanente.
    Route::get('/sites/{chemin?}', function (?string $chemin = null) {
        $chemin = (string) $chemin;
        if (preg_match('#^(\d+)/(boutiques|excel)(/|$)#', $chemin, $m)) {
            return redirect(url('admin/referencement/boutiques').'?site='.$m[1], 301);
        }
        $nouveau = match (true) {
            $chemin === '' => 'referencement/sites',
            str_starts_with($chemin, 'types') => 'referencement/referentiels/'.$chemin,
            str_starts_with($chemin, 'excel/boutiques') => 'referencement/imports/boutiques'.substr($chemin, strlen('excel/boutiques')),
            str_starts_with($chemin, 'excel') => 'referencement/imports/sites'.substr($chemin, strlen('excel')),
            default => 'referencement/sites/'.$chemin,
        };

        return redirect(url('admin/'.$nouveau).(request()->getQueryString() ? '?'.request()->getQueryString() : ''), 301);
    })->where('chemin', '.*');
    Route::get('/referencement/sites/{site}/{chemin}', fn (string $site) => redirect(url('admin/referencement/boutiques').'?site='.$site, 301))
        ->whereNumber('site')->where('chemin', '(boutiques|excel)(/.*)?');
    Route::get('/boutiques/grilles/{chemin?}', fn (?string $chemin = null) => redirect(url('admin/referencement/grille'.($chemin ? '/'.$chemin : '')), 301))
        ->where('chemin', '.*');

    Route::middleware('backoffice')->group(function () {
        Route::post('/deconnexion', [ConnexionController::class, 'destroy'])->name('deconnexion');

        // Pièces d'identité des citoyens : VALIDATEUR uniquement (l'écran KYC viendra avec le lot KYC).
        Route::get('/kyc/pieces/{document}', PiecesKycController::class)->whereNumber('document')->middleware('can:'.Droit::KycVoirPieces->value)->name('kyc.piece');

        // Barre du haut : thème mémorisé par utilisateur, notifications, recherche globale.
        Route::post('/preferences/theme', [CadreController::class, 'theme'])->name('preferences.theme');
        Route::post('/preferences/menu', [CadreController::class, 'menu'])->name('preferences.menu');
        Route::post('/notifications/lues', [CadreController::class, 'notificationsLues'])->name('notifications.lues');
        Route::get('/recherche', [CadreController::class, 'recherche'])->name('recherche');
        // File « À valider » du VALIDATEUR : décision sur une ligne ou sur une sélection (rejet : motif commun).
        Route::get('/a-valider', [AValiderController::class, 'index'])->middleware('can:'.Droit::AValiderVoir->value)->name('a-valider');
        Route::post('/a-valider', [AValiderController::class, 'decider'])->middleware('can:'.Droit::AValiderVoir->value)->name('a-valider.decider');
        Route::get('/a-valider/{rubrique}', [AValiderController::class, 'index'])->where('rubrique', '[a-z-]+')->middleware('can:'.Droit::AValiderVoir->value)->name('a-valider.rubrique');

        // RAAGA Invest : paramètres d'investissement (RÉGULATEUR saisit, VALIDATEUR confirme), manifestations d'intérêt (lecture, export).
        Route::controller(ParametresInvestissementController::class)->prefix('investissement')->name('investissement')->group(function () {
            Route::get('/', 'show')->middleware('can:'.Droit::TranchesVoir->value);
            Route::put('/', 'update')->middleware('can:'.Droit::TranchesSaisir->value)->name('.update');
            Route::post('/soumettre', 'soumettre')->middleware('can:'.Droit::TranchesSaisir->value)->name('.soumettre');
            Route::post('/reviser', 'reviser')->middleware('can:'.Droit::TranchesSaisir->value)->name('.reviser');
            Route::post('/confirmer', 'confirmer')->middleware('can:'.Droit::TranchesConfirmer->value)->name('.confirmer');
            Route::post('/rejeter', 'rejeter')->middleware('can:'.Droit::TranchesConfirmer->value)->name('.rejeter');
        });
        Route::get('/manifestations-interet', [ManifestationsInteretController::class, 'index'])->middleware('can:'.Droit::InteretsVoir->value)->name('interets');

        // Paramètres des paiements (délai de complément) : RÉGULATEUR saisit, VALIDATEUR confirme (« À valider › Données financières »).
        Route::controller(ParametresPaiementsController::class)->prefix('parametres-paiements')->name('parametres-paiements')->group(function () {
            Route::get('/', 'show')->middleware('can:'.Droit::ModalitesPaiementVoir->value);
            Route::put('/', 'update')->middleware('can:'.Droit::MoyensPaiementGerer->value)->name('.update');
            Route::post('/soumettre', 'soumettre')->middleware('can:'.Droit::MoyensPaiementGerer->value)->name('.soumettre');
            Route::post('/reviser', 'reviser')->middleware('can:'.Droit::MoyensPaiementGerer->value)->name('.reviser');
            Route::post('/confirmer', 'confirmer')->middleware('can:'.Droit::MoyensPaiementConfirmer->value)->name('.confirmer');
            Route::post('/rejeter', 'rejeter')->middleware('can:'.Droit::MoyensPaiementConfirmer->value)->name('.rejeter');
        });

        // Contrôle des paiements (CONTRÔLEUR DES PAIEMENTS) ; corrections confirmées par le VALIDATEUR (« À valider › Paiements »).
        Route::controller(PaiementsController::class)->prefix('paiements')->name('paiements')->group(function () {
            Route::get('/', 'index')->middleware('can:'.Droit::PaiementsVoir->value);
            Route::get('/{type}/{id}', 'dossier')->where('type', 'souscription|caution')->whereNumber('id')->middleware('can:'.Droit::PaiementsVoir->value)->name('.dossier');
            Route::post('/{type}/{id}/relance', 'relancer')->where('type', 'souscription|caution')->whereNumber('id')->middleware('can:'.Droit::RelancesEnvoyer->value)->name('.relance');
            Route::get('/preuve/{paiement}', 'preuve')->whereNumber('paiement')->middleware('can:'.Droit::PaiementsVoir->value)->name('.preuve');
            Route::post('/controle/{paiement}', 'marquer')->whereNumber('paiement')->middleware('can:'.Droit::SouscriptionsRapprocher->value)->name('.controle');
            Route::post('/correction/{paiement}', 'proposerCorrection')->whereNumber('paiement')->middleware('can:'.Droit::SouscriptionsRapprocher->value)->name('.correction');
            Route::post('/corrections/{correction}/confirmer', 'confirmerCorrection')->whereNumber('correction')->middleware('can:'.Droit::PaiementsCorrectionsConfirmer->value)->name('.corrections.confirmer');
            Route::post('/corrections/{correction}/rejeter', 'rejeterCorrection')->whereNumber('correction')->middleware('can:'.Droit::PaiementsCorrectionsConfirmer->value)->name('.corrections.rejeter');
        });
        Route::controller(RemboursementsController::class)->prefix('remboursements')->name('remboursements')->group(function () {
            Route::get('/', 'index')->middleware('can:'.Droit::RemboursementsVoir->value);
            Route::post('/{remboursement}/execution', 'declarer')->whereNumber('remboursement')->middleware('can:'.Droit::RemboursementsSuivre->value)->name('.execution');
            Route::post('/{remboursement}/confirmer', 'confirmer')->whereNumber('remboursement')->middleware('can:'.Droit::RemboursementsApprouver->value)->name('.confirmer');
            Route::post('/{remboursement}/rejeter', 'rejeter')->whereNumber('remboursement')->middleware('can:'.Droit::RemboursementsApprouver->value)->name('.rejeter');
        });
        // Cautions à rembourser (réservations annulées faute d'avance) : RÉGULATEUR propose la retenue ; VALIDATEUR décide (« À valider › Cautions »).
        Route::controller(CautionsRembourserController::class)->prefix('cautions-a-rembourser')->name('cautions')->group(function () {
            Route::get('/', 'index')->middleware('can:'.Droit::AvancesVoir->value);
            Route::post('/{remboursement}/retenue', 'proposer')->whereNumber('remboursement')->middleware('can:'.Droit::AvancesProposer->value)->name('.proposer');
            Route::post('/decisions/{decision}/confirmer', 'confirmer')->whereNumber('decision')->middleware('can:'.Droit::AvancesDecider->value)->name('.confirmer');
            Route::post('/decisions/{decision}/rejeter', 'rejeter')->whereNumber('decision')->middleware('can:'.Droit::AvancesDecider->value)->name('.rejeter');
        });
        // Vérifications d'identité : l'ADMIN marque le dossier ; le VALIDATEUR confirme ou rejette l'annulation (« À valider › Identités »).
        Route::controller(VerificationsIdentiteController::class)->prefix('kyc')->name('kyc')->group(function () {
            Route::get('/', 'index')->middleware('can:'.Droit::KycVoir->value);
            Route::get('/dossiers/{citoyen}', 'dossier')->whereNumber('citoyen')->middleware('can:'.Droit::KycVoir->value)->name('.dossier');
            Route::post('/dossiers/{citoyen}/conforme', 'conforme')->whereNumber('citoyen')->middleware('can:'.Droit::KycTraiter->value)->name('.conforme');
            Route::post('/dossiers/{citoyen}/douteux', 'douteux')->whereNumber('citoyen')->middleware('can:'.Droit::KycTraiter->value)->name('.douteux');
            Route::post('/propositions/{verification}/confirmer', 'confirmerAnnulation')->whereNumber('verification')->middleware('can:'.Droit::KycAnnulationConfirmer->value)->name('.annulation.confirmer');
            Route::post('/propositions/{verification}/rejeter', 'rejeterAnnulation')->whereNumber('verification')->middleware('can:'.Droit::KycAnnulationConfirmer->value)->name('.annulation.rejeter');
        });

        // Import confirmé : détail, fichier d'origine, décision du VALIDATEUR sur l'import entier (« À valider › Imports »).
        Route::controller(ImportsValidationController::class)->prefix('imports-validation')->name('imports-validation.')->group(function () {
            Route::get('/{import}', 'show')->whereNumber('import')->middleware('can:'.Droit::ImportsVoir->value)->name('show');
            Route::get('/{import}/fichier', 'fichier')->whereNumber('import')->middleware('can:'.Droit::ImportsVoir->value)->name('fichier');
            Route::post('/{import}/confirmer', 'confirmer')->whereNumber('import')->middleware('can:'.Droit::SitesConfirmer->value)->name('confirmer');
            Route::post('/{import}/rejeter', 'rejeter')->whereNumber('import')->middleware('can:'.Droit::SitesConfirmer->value)->name('rejeter');
        });

        // « Mes brouillons » : brouillons et rejetés du GESTIONNAIRE ou du RÉGULATEUR, toutes entités confondues.
        Route::get('/brouillons', BrouillonsController::class)->middleware('can:'.Droit::BrouillonsVoir->value)->name('brouillons');

        // Listes de gestion : soumission groupée à validation et retrait (droit de l'auteur vérifié par élément).
        Route::controller(SoumissionGroupeeController::class)->prefix('soumission')->name('soumission.')->group(function () {
            Route::post('/controle', 'controler')->name('controle');
            Route::post('/', 'soumettre')->name('soumettre');
            Route::post('/retrait', 'retirer')->name('retirer');
        });

        // Décisions de phase du site public : le RÉGULATEUR propose (ou retire), le VALIDATEUR confirme ou rejette ; SUPER_ADMIN en lecture.
        Route::controller(PhaseController::class)->prefix('phase')->name('phase')->group(function () {
            Route::get('/', 'index')->middleware('can:'.Droit::PhaseVoir->value);
            Route::post('/', 'proposer')->middleware('can:'.Droit::PhaseProposer->value)->name('.proposer');
            Route::post('/{decision}/retirer', 'retirer')->whereNumber('decision')->middleware('can:'.Droit::PhaseProposer->value)->name('.retirer');
            Route::post('/{decision}/confirmer', 'confirmer')->whereNumber('decision')->middleware('can:'.Droit::PhaseConfirmer->value)->name('.confirmer');
            Route::post('/{decision}/rejeter', 'rejeter')->whereNumber('decision')->middleware('can:'.Droit::PhaseConfirmer->value)->name('.rejeter');
        });

        // Charte du back-office : tous les composants, pour contrôle visuel (SUPER_ADMIN).
        Route::view('/charte', 'admin.charte')->middleware('can:'.Droit::CharteVoir->value)->name('charte');

        // Mon profil : chaque compte back-office met à jour ses propres informations (jamais son rôle).
        Route::get('/profil', [ProfilController::class, 'show'])->name('profil');
        Route::post('/profil', [ProfilController::class, 'update'])->name('profil.update');
        Route::post('/profil/mot-de-passe', [ProfilController::class, 'motDePasse'])->middleware('throttle:10,1')->name('profil.mot-de-passe');

        // Référencement : niveaux et zones, boutiques de tous les sites, capacités prévues, imports.
        Route::prefix('referencement')->name('referencement.')->group(function () {
            // Référentiels (menu dépliant) : niveaux, zones et unités, une fiche par élément ; mêmes actions pour les
            // trois listes, droits vérifiés par liste dans le contrôleur. Types de boutiques : plus bas.
            Route::controller(NiveauxZonesController::class)->prefix('referentiels')->group(function () {
                Route::get('/niveaux', 'index')->defaults('liste', 'niveaux')->middleware('can:'.Droit::ReferentielsBoutiquesVoir->value)->name('niveaux');
                Route::get('/zones', 'index')->defaults('liste', 'zones')->middleware('can:'.Droit::ReferentielsBoutiquesVoir->value)->name('zones');
                Route::get('/unites', 'index')->defaults('liste', 'unites')->middleware('can:'.Droit::UnitesVoir->value)->name('unites');
                Route::get('/moyens-paiement', 'index')->defaults('liste', 'moyens')->middleware('can:'.Droit::ModalitesPaiementVoir->value)->name('moyens');
                Route::get('/{liste}/{id}', 'show')->whereIn('liste', ['niveaux', 'zones', 'unites', 'moyens'])->whereNumber('id')->name('zones.show');
                Route::post('/{liste}', 'store')->whereIn('liste', ['niveaux', 'zones', 'unites', 'moyens'])->name('zones.store');
                Route::put('/{liste}/{id}', 'update')->whereIn('liste', ['niveaux', 'zones', 'unites', 'moyens'])->whereNumber('id')->name('zones.update');
                Route::post('/{liste}/{id}/activer', 'activer')->whereIn('liste', ['niveaux', 'zones', 'unites', 'moyens'])->whereNumber('id')->name('zones.activer');
                Route::post('/{liste}/{id}/desactiver', 'desactiver')->whereIn('liste', ['niveaux', 'zones', 'unites', 'moyens'])->whereNumber('id')->name('zones.desactiver');
                Route::delete('/{liste}/{id}', 'destroy')->whereIn('liste', ['niveaux', 'zones', 'unites', 'moyens'])->whereNumber('id')->name('zones.destroy');
                Route::post('/{liste}/{id}/soumettre', 'soumettre')->whereIn('liste', ['niveaux', 'zones', 'unites', 'moyens'])->whereNumber('id')->name('zones.soumettre');
                Route::post('/{liste}/{id}/confirmer', 'confirmer')->whereIn('liste', ['niveaux', 'zones', 'unites', 'moyens'])->whereNumber('id')->name('zones.confirmer');
                Route::post('/{liste}/{id}/rejeter', 'rejeter')->whereIn('liste', ['niveaux', 'zones', 'unites', 'moyens'])->whereNumber('id')->name('zones.rejeter');
            });
            // Anciennes adresses (avant le menu « Référentiels ») : redirection permanente.
            Route::permanentRedirect('/niveaux-zones', '/admin/referencement/referentiels/niveaux')->name('niveaux-zones');
            Route::get('/{ancien}/{chemin?}', fn (string $ancien, ?string $chemin = null) => redirect(url('admin/referencement/referentiels/'.$ancien.($chemin ? '/'.$chemin : ''))
                .(request()->getQueryString() ? '?'.request()->getQueryString() : ''), 301))
                ->whereIn('ancien', ['niveaux', 'zones', 'types'])->where('chemin', '.*')->name('anciennes-adresses');
            // Boutiques : ajout (site choisi d'abord), fiche, Excel ; seulement sur un site en brouillon ou en révision (sauf export).
            Route::prefix('boutiques')->name('boutiques.')->middleware('can:'.Droit::SitesGerer->value)->group(function () {
                Route::get('/ajouter', fn () => redirect()->route('admin.referencement.boutiques', ['ajouter' => 1, 'site' => request('site')]))->name('ajouter.ecran');
                Route::post('/ajouter', [SitesController::class, 'ajouterDepuisListe'])->name('ajouter');
                Route::controller(SitesController::class)->group(function () {
                    Route::post('/sites/{site}', 'ajouterBoutique')->whereNumber('site')->name('store');
                    Route::post('/sites/{site}/serie', 'genererBoutiques')->whereNumber('site')->name('serie');
                    Route::get('/{boutique}/modifier', 'editBoutique')->whereNumber('boutique')->name('edit');
                    Route::put('/{boutique}', 'updateBoutique')->whereNumber('boutique')->name('update');
                    Route::delete('/{boutique}', 'supprimerBoutique')->whereNumber('boutique')->name('destroy');
                });
                Route::controller(ImportBoutiquesController::class)->prefix('excel')->name('excel.')->group(function () {
                    Route::get('/modele', 'modelePourSite')->name('modele');
                    Route::get('/export', 'exporterPourSite')->name('export');
                    Route::post('/import', 'deposerPourSite')->middleware('throttle:20,1')->name('deposer');
                    Route::get('/sites/{site}/import', 'apercu')->whereNumber('site')->name('import');
                    Route::post('/sites/{site}/import/confirmer', 'confirmer')->whereNumber('site')->name('import.confirmer');
                    Route::post('/sites/{site}/import/annuler', 'annuler')->whereNumber('site')->name('import.annuler');
                });
            });
            Route::controller(ReferentielBoutiquesController::class)->prefix('boutiques')->name('boutiques')->middleware('can:'.Droit::SitesVoir->value)->group(function () {
                Route::get('/', 'index');
                Route::get('/export', 'exporter')->name('.export');
                Route::get('/{boutique}', 'show')->whereNumber('boutique')->name('.show');
            });
            Route::get('/capacites', [CapacitesController::class, 'index'])->middleware('can:'.Droit::SitesVoir->value)->name('capacites');
            Route::controller(CapacitesController::class)->prefix('capacites')->name('capacites.')->middleware('can:'.Droit::SitesGerer->value)->group(function () {
                Route::post('/', 'store')->name('store');
                Route::get('/{capacite}/modifier', 'edit')->whereNumber('capacite')->name('edit');
                Route::put('/{capacite}', 'update')->whereNumber('capacite')->name('update');
                Route::delete('/{capacite}', 'destroy')->whereNumber('capacite')->name('destroy');
            });
            // Imports : un sous-onglet par entité importable ; l'ancienne page unique renvoie vers le premier.
            Route::redirect('/imports', '/admin/referencement/imports/sites')->name('imports');
            Route::get('/imports/{entite}', [ImportsController::class, 'index'])->whereIn('entite', array_keys(ImportsController::ENTITES))
                ->middleware('can:'.Droit::ImportsVoir->value)->name('imports.entite');
            Route::get('/imports/{journal}/fichier', [ImportsController::class, 'fichier'])->whereNumber('journal')->middleware('can:'.Droit::ImportsVoir->value)->name('imports.fichier');
        });

        // Types de boutiques : GESTIONNAIRE DES SITES ET BOUTIQUES gère, SUPER_ADMIN en lecture, VALIDATEUR décide depuis « À valider ».
        Route::controller(TypesBoutiqueController::class)->prefix('referencement/referentiels/types')->name('sites.types')->group(function () {
            Route::get('/', 'index')->middleware('can:'.Droit::ReferentielsBoutiquesVoir->value);
            Route::post('/', 'store')->middleware('can:'.Droit::SitesGerer->value)->name('.store');
            Route::get('/{type}', 'show')->whereNumber('type')->middleware('can:'.Droit::ReferentielsBoutiquesVoir->value)->name('.show');
            Route::put('/{type}', 'update')->whereNumber('type')->middleware('can:'.Droit::SitesGerer->value)->name('.update');
            Route::post('/{type}/attribuable', 'demanderAttribuable')->whereNumber('type')->middleware('can:'.Droit::SitesGerer->value)->name('.attribuable');
            Route::post('/{type}/activer', 'activer')->whereNumber('type')->middleware('can:'.Droit::SitesGerer->value)->name('.activer');
            Route::post('/{type}/desactiver', 'desactiver')->whereNumber('type')->middleware('can:'.Droit::SitesGerer->value)->name('.desactiver');
            Route::post('/{type}/soumettre', 'soumettre')->whereNumber('type')->middleware('can:'.Droit::SitesGerer->value)->name('.soumettre');
            Route::delete('/{type}', 'destroy')->whereNumber('type')->middleware('can:'.Droit::SitesGerer->value)->name('.destroy');
            Route::post('/{type}/confirmer', 'confirmer')->whereNumber('type')->middleware('can:'.Droit::SitesConfirmer->value)->name('.confirmer');
            Route::post('/{type}/rejeter', 'rejeter')->whereNumber('type')->middleware('can:'.Droit::SitesConfirmer->value)->name('.rejeter');
        });

        // Sites (référentiel, en amont des campagnes) : GESTIONNAIRE DES SITES ET BOUTIQUES saisit, VALIDATEUR confirme.
        Route::controller(SitesController::class)->prefix('referencement/sites')->name('sites')->group(function () {
            Route::get('/', 'index')->middleware('can:'.Droit::SitesVoir->value);
            Route::get('/creer', 'create')->middleware('can:'.Droit::SitesGerer->value)->name('.create');
            Route::post('/', 'store')->middleware('can:'.Droit::SitesGerer->value)->name('.store');
            Route::get('/{site}', 'show')->whereNumber('site')->middleware('can:'.Droit::SitesVoir->value)->name('.show');
            Route::get('/{site}/modifier', 'edit')->whereNumber('site')->middleware('can:'.Droit::SitesGerer->value)->name('.edit');
            Route::put('/{site}', 'update')->whereNumber('site')->middleware('can:'.Droit::SitesGerer->value)->name('.update');
            Route::post('/{site}/soumettre', 'soumettre')->whereNumber('site')->middleware('can:'.Droit::SitesGerer->value)->name('.soumettre');
            Route::post('/{site}/reviser', 'reviser')->whereNumber('site')->middleware('can:'.Droit::SitesConfirmer->value)->name('.reviser');
            Route::post('/{site}/demander-revision', 'demanderRevision')->whereNumber('site')->middleware('can:'.Droit::SitesGerer->value)->name('.demander-revision');
            Route::post('/{site}/refuser-revision', 'refuserRevision')->whereNumber('site')->middleware('can:'.Droit::SitesConfirmer->value)->name('.refuser-revision');
            Route::post('/{site}/confirmer', 'confirmer')->whereNumber('site')->middleware('can:'.Droit::SitesConfirmer->value)->name('.confirmer');
            Route::post('/{site}/rejeter', 'rejeter')->whereNumber('site')->middleware('can:'.Droit::SitesConfirmer->value)->name('.rejeter');
        });

        // Données financières d'un site : RÉGULATEUR saisit et soumet, VALIDATEUR confirme ; jamais le GESTIONNAIRE (403).
        // Liste de tous les sites (menu « Tarification › Finances des sites » du RÉGULATEUR), puis fiche de chaque site.
        Route::get('/referencement/finances', [SiteFinancesController::class, 'index'])->middleware('can:'.Droit::BoutiquesVoir->value)->name('sites.finances.liste');
        Route::controller(SiteFinancesController::class)->prefix('referencement/sites/{site}/finances')->whereNumber('site')->name('sites.finances')->group(function () {
            Route::get('/', 'show')->middleware('can:'.Droit::BoutiquesVoir->value);
            Route::put('/', 'update')->middleware('can:'.Droit::BoutiquesSaisir->value)->name('.update');
            Route::post('/soumettre', 'soumettre')->middleware('can:'.Droit::BoutiquesSaisir->value)->name('.soumettre');
            Route::post('/reviser', 'reviser')->middleware('can:'.Droit::BoutiquesSaisir->value)->name('.reviser');
            Route::post('/confirmer', 'confirmer')->middleware('can:'.Droit::BoutiquesConfirmer->value)->name('.confirmer');
            Route::post('/rejeter', 'rejeter')->middleware('can:'.Droit::BoutiquesConfirmer->value)->name('.rejeter');
        });

        // Sites et capacités prévues en Excel : modèle, export, import avec aperçu puis confirmation (GESTIONNAIRE).
        Route::controller(ImportSitesController::class)->prefix('referencement/imports/sites')->middleware('can:'.Droit::SitesGerer->value)->name('sites.excel.')->group(function () {
            Route::get('/modele', 'modele')->name('modele');
            Route::get('/export', 'exporter')->name('export');
            Route::post('/import', 'deposer')->middleware('throttle:20,1')->name('deposer');
            Route::get('/import', 'apercu')->name('apercu');
            Route::post('/import/confirmer', 'confirmer')->name('confirmer');
            Route::post('/import/annuler', 'annuler')->name('annuler');
        });

        // Boutiques de plusieurs sites existants en un fichier (import combiné ; ne crée jamais de site).
        Route::controller(ImportBoutiquesController::class)->prefix('referencement/imports/boutiques')->middleware('can:'.Droit::SitesGerer->value)->name('sites.boutiques-excel.')->group(function () {
            Route::get('/attente/{import}/reprendre', 'reprendre')->whereNumber('import')->name('reprendre');
            Route::get('/modele', 'modeleCombine')->name('modele');
            Route::post('/import', 'deposerCombine')->middleware('throttle:20,1')->name('deposer');
            Route::get('/import', 'apercuCombine')->name('apercu');
            Route::post('/import/confirmer', 'confirmerCombine')->name('confirmer');
            Route::post('/import/annuler', 'annulerCombine')->name('annuler');
        });

        // Grilles tarifaires des boutiques (une par site) : RÉGULATEUR saisit et soumet, VALIDATEUR confirme.
        Route::controller(GrillesTarifairesController::class)->prefix('referencement/grille')->name('boutiques.grilles')->group(function () {
            Route::get('/', 'index')->middleware('can:'.Droit::GrilleVoir->value);
            Route::get('/{site}', 'show')->whereNumber('site')->middleware('can:'.Droit::GrilleVoir->value)->name('.show');
            Route::get('/{site}/modifier', 'edit')->whereNumber('site')->middleware('can:'.Droit::BoutiquesSaisir->value)->name('.edit');
            Route::put('/{site}', 'update')->whereNumber('site')->middleware('can:'.Droit::BoutiquesSaisir->value)->name('.update');
            Route::post('/{site}/soumettre', 'soumettre')->whereNumber('site')->middleware('can:'.Droit::BoutiquesSaisir->value)->name('.soumettre');
            Route::post('/{site}/reviser', 'reviser')->whereNumber('site')->middleware('can:'.Droit::BoutiquesSaisir->value)->name('.reviser');
            Route::post('/{site}/confirmer', 'confirmer')->whereNumber('site')->middleware('can:'.Droit::BoutiquesConfirmer->value)->name('.confirmer');
            Route::post('/{site}/rejeter', 'rejeter')->whereNumber('site')->middleware('can:'.Droit::BoutiquesConfirmer->value)->name('.rejeter');
        });

        // Boutiques : RÉGULATEUR saisit et soumet les campagnes de réservation (premier arrivé), VALIDATEUR confirme, ouvre, clôture, attribue.
        Route::controller(CampagnesBoutiquesController::class)->prefix('boutiques')->name('boutiques')->group(function () {
            Route::get('/', 'index')->middleware('can:'.Droit::BoutiquesVoir->value);
            Route::get('/creer', 'create')->middleware('can:'.Droit::BoutiquesSaisir->value)->name('.create');
            Route::post('/', 'store')->middleware('can:'.Droit::BoutiquesSaisir->value)->name('.store');
            Route::get('/{campagne}', 'show')->whereNumber('campagne')->middleware('can:'.Droit::BoutiquesVoir->value)->name('.show');
            Route::get('/{campagne}/modifier', 'edit')->whereNumber('campagne')->middleware('can:'.Droit::BoutiquesSaisir->value)->name('.edit');
            Route::put('/{campagne}', 'update')->whereNumber('campagne')->middleware('can:'.Droit::BoutiquesSaisir->value)->name('.update');
            Route::post('/{campagne}/soumettre', 'soumettre')->whereNumber('campagne')->middleware('can:'.Droit::BoutiquesSaisir->value)->name('.soumettre');
            Route::post('/{campagne}/confirmer', 'confirmer')->whereNumber('campagne')->middleware('can:'.Droit::BoutiquesConfirmer->value)->name('.confirmer');
            Route::post('/{campagne}/rejeter', 'rejeter')->whereNumber('campagne')->middleware('can:'.Droit::BoutiquesConfirmer->value)->name('.rejeter');
            Route::post('/{campagne}/ouvrir', 'ouvrir')->whereNumber('campagne')->middleware('can:'.Droit::BoutiquesPiloter->value)->name('.ouvrir');
            Route::post('/{campagne}/cloturer', 'cloturer')->whereNumber('campagne')->middleware('can:'.Droit::BoutiquesPiloter->value)->name('.cloturer');
            Route::post('/{campagne}/reservations/{reservation}/attribuer', 'attribuer')->whereNumber(['campagne', 'reservation'])->middleware('can:'.Droit::BoutiquesPiloter->value)->name('.attribuer');
        });

        // Tranches : RÉGULATEUR saisit et soumet, VALIDATEUR confirme puis ouvre, prolonge, clôture.
        Route::controller(TranchesController::class)->prefix('tranches')->name('tranches')->group(function () {
            Route::get('/', 'index')->middleware('can:'.Droit::TranchesVoir->value);
            Route::get('/creer', 'create')->middleware('can:'.Droit::TranchesSaisir->value)->name('.create');
            Route::post('/', 'store')->middleware('can:'.Droit::TranchesSaisir->value)->name('.store');
            Route::get('/{tranche}', 'show')->whereNumber('tranche')->middleware('can:'.Droit::TranchesVoir->value)->name('.show');
            Route::get('/{tranche}/reglement', 'reglement')->middleware('can:'.Droit::TranchesVoir->value)->name('.reglement');
            Route::get('/{tranche}/modifier', 'edit')->middleware('can:'.Droit::TranchesSaisir->value)->name('.edit');
            Route::put('/{tranche}', 'update')->middleware('can:'.Droit::TranchesSaisir->value)->name('.update');
            Route::post('/{tranche}/soumettre', 'soumettre')->middleware('can:'.Droit::TranchesSaisir->value)->name('.soumettre');
            Route::get('/{tranche}/avenant', 'avenant')->middleware('can:'.Droit::TranchesSaisir->value)->name('.avenant');
            Route::post('/{tranche}/avenant', 'proposerAvenant')->middleware('can:'.Droit::TranchesSaisir->value)->name('.avenant.store');
            Route::post('/{tranche}/confirmer', 'confirmer')->middleware('can:'.Droit::TranchesConfirmer->value)->name('.confirmer');
            Route::post('/{tranche}/rejeter', 'rejeter')->middleware('can:'.Droit::TranchesConfirmer->value)->name('.rejeter');
            Route::post('/{tranche}/avenants/{avenant}/confirmer', 'confirmerAvenant')->middleware('can:'.Droit::TranchesConfirmer->value)->name('.avenant.confirmer');
            Route::post('/{tranche}/avenants/{avenant}/rejeter', 'rejeterAvenant')->middleware('can:'.Droit::TranchesConfirmer->value)->name('.avenant.rejeter');
            Route::post('/{tranche}/ouvrir', 'ouvrir')->middleware('can:'.Droit::TranchesPiloter->value)->name('.ouvrir');
            Route::post('/{tranche}/cloturer', 'cloturer')->middleware('can:'.Droit::TranchesPiloter->value)->name('.cloturer');
        });

        // Dividendes : RÉGULATEUR saisit, simule et soumet ; VALIDATEUR confirme (puis met en paiement).
        Route::controller(DividendesController::class)->prefix('dividendes')->name('dividendes')->group(function () {
            Route::get('/', 'index')->middleware('can:'.Droit::DividendesVoir->value);
            Route::get('/creer', 'create')->middleware('can:'.Droit::DividendesSaisir->value)->name('.create');
            Route::post('/', 'store')->middleware('can:'.Droit::DividendesSaisir->value)->name('.store');
            Route::get('/{distribution}', 'show')->whereNumber('distribution')->middleware('can:'.Droit::DividendesVoir->value)->name('.show');
            Route::get('/{distribution}/avis', 'avis')->middleware('can:'.Droit::DividendesVoir->value)->name('.avis');
            Route::get('/{distribution}/pv', 'pv')->middleware('can:'.Droit::DividendesVoir->value)->name('.pv');
            Route::get('/{distribution}/simulation', 'simulation')->middleware('can:'.Droit::SimulationsLancer->value)->name('.simulation');
            Route::get('/{distribution}/modifier', 'edit')->middleware('can:'.Droit::DividendesSaisir->value)->name('.edit');
            Route::put('/{distribution}', 'update')->middleware('can:'.Droit::DividendesSaisir->value)->name('.update');
            Route::post('/{distribution}/soumettre', 'soumettre')->middleware('can:'.Droit::DividendesSaisir->value)->name('.soumettre');
            Route::get('/{distribution}/avenant', 'avenant')->middleware('can:'.Droit::DividendesSaisir->value)->name('.avenant');
            Route::post('/{distribution}/avenant', 'proposerAvenant')->middleware('can:'.Droit::DividendesSaisir->value)->name('.avenant.store');
            Route::post('/{distribution}/confirmer', 'confirmer')->middleware('can:'.Droit::DividendesConfirmer->value)->name('.confirmer');
            Route::post('/{distribution}/rejeter', 'rejeter')->middleware('can:'.Droit::DividendesConfirmer->value)->name('.rejeter');
            Route::post('/{distribution}/avenants/{avenant}/confirmer', 'confirmerAvenant')->middleware('can:'.Droit::DividendesConfirmer->value)->name('.avenant.confirmer');
            Route::post('/{distribution}/avenants/{avenant}/rejeter', 'rejeterAvenant')->middleware('can:'.Droit::DividendesConfirmer->value)->name('.avenant.rejeter');
        });

        // Assistance (ADMIN, SUPER_ADMIN) : citoyens souscripteurs et agents de guichet.
        Route::controller(AssistanceController::class)->prefix('assistance')->name('assistance')->group(function () {
            Route::get('/', 'index')->middleware('can:'.Droit::AssistanceVoir->value);
            Route::get('/{utilisateur}', 'show')->whereNumber('utilisateur')->middleware('can:'.Droit::AssistanceVoir->value)->name('.show');
            Route::post('/{utilisateur}/desactiver', 'desactiver')->middleware('can:'.Droit::AssistanceAgir->value)->name('.desactiver');
            Route::post('/{utilisateur}/reactiver', 'reactiver')->middleware('can:'.Droit::AssistanceAgir->value)->name('.reactiver');
        });

        // Investisseurs institutionnels (actions A).
        Route::controller(InstitutionsController::class)->prefix('institutions')->name('institutions')->group(function () {
            Route::get('/', 'index')->middleware('can:'.Droit::InstitutionsGerer->value);
            Route::get('/creer', 'create')->middleware('can:'.Droit::InstitutionsGerer->value)->name('.create');
            Route::post('/', 'store')->middleware('can:'.Droit::InstitutionsGerer->value)->name('.store');
            Route::get('/{institution}', 'show')->whereNumber('institution')->middleware('can:'.Droit::InstitutionsGerer->value)->name('.show');
            Route::post('/{institution}/inviter', 'inviter')->middleware('can:'.Droit::InstitutionsGerer->value)->name('.inviter');
            Route::post('/{institution}/utilisateurs/{utilisateur}/lien', 'renvoyerLien')->middleware('can:'.Droit::InstitutionsGerer->value)->name('.lien');
            Route::post('/{institution}/suspendre', 'suspendre')->middleware('can:'.Droit::InstitutionsGerer->value)->name('.suspendre');
            Route::post('/{institution}/reactiver', 'reactiver')->middleware('can:'.Droit::InstitutionsGerer->value)->name('.reactiver');
            Route::put('/{institution}/interlocuteur', 'interlocuteur')->middleware('can:'.Droit::InstitutionsInterlocuteur->value)->name('.interlocuteur');
        });

        Route::controller(KybController::class)->prefix('dossiers-institutionnels')->name('kyb')->group(function () {
            Route::get('/', 'index')->middleware('can:'.Droit::KybVoir->value);
            Route::get('/{institution}', 'show')->whereNumber('institution')->middleware('can:'.Droit::KybVoir->value)->name('.show');
            Route::get('/pieces/{document}', 'telecharger')->middleware('can:'.Droit::KybVoir->value)->name('.piece');
            Route::post('/pieces/{document}/valider', 'valider')->middleware('can:'.Droit::KybVerifier->value)->name('.valider');
            Route::post('/pieces/{document}/rejeter', 'rejeter')->middleware('can:'.Droit::KybVerifier->value)->name('.rejeter');
        });

        Route::controller(ConventionsController::class)->prefix('conventions')->name('conventions')->group(function () {
            Route::get('/', 'index')->middleware('can:'.Droit::ConventionsVoir->value);
            Route::get('/creer', 'create')->middleware('can:'.Droit::ConventionsSaisir->value)->name('.create');
            Route::post('/', 'store')->middleware('can:'.Droit::ConventionsSaisir->value)->name('.store');
            Route::get('/{convention}', 'show')->whereNumber('convention')->middleware('can:'.Droit::ConventionsVoir->value)->name('.show');
            Route::get('/{convention}/pdf', 'pdf')->middleware('can:'.Droit::ConventionsVoir->value)->name('.pdf');
            Route::get('/{convention}/pdf-signe', 'pdfSigne')->middleware('can:'.Droit::ConventionsVoir->value)->name('.pdf-signe');
            Route::get('/{convention}/modifier', 'edit')->middleware('can:'.Droit::ConventionsSaisir->value)->name('.edit');
            Route::put('/{convention}', 'update')->middleware('can:'.Droit::ConventionsSaisir->value)->name('.update');
            Route::post('/{convention}/soumettre', 'soumettre')->middleware('can:'.Droit::ConventionsSaisir->value)->name('.soumettre');
            Route::get('/{convention}/avenant', 'avenant')->middleware('can:'.Droit::ConventionsSaisir->value)->name('.avenant');
            Route::post('/{convention}/avenant', 'proposerAvenant')->middleware('can:'.Droit::ConventionsSaisir->value)->name('.avenant.store');
            Route::post('/{convention}/confirmer', 'confirmer')->middleware('can:'.Droit::ConventionsConfirmer->value)->name('.confirmer');
            Route::post('/{convention}/rejeter', 'rejeter')->middleware('can:'.Droit::ConventionsConfirmer->value)->name('.rejeter');
            Route::post('/{convention}/attester', 'attesterReception')->middleware('can:'.Droit::ConventionsConfirmer->value)->name('.attester');
            Route::post('/{convention}/avenants/{avenant}/confirmer', 'confirmerAvenant')->middleware('can:'.Droit::ConventionsConfirmer->value)->name('.avenant.confirmer');
            Route::post('/{convention}/avenants/{avenant}/rejeter', 'rejeterAvenant')->middleware('can:'.Droit::ConventionsConfirmer->value)->name('.avenant.rejeter');
        });

        Route::controller(AppelsFondsController::class)->prefix('appels-de-fonds')->name('appels-fonds')->group(function () {
            Route::get('/', 'index')->middleware('can:'.Droit::AppelsFondsVoir->value);
            Route::get('/{appel}', 'show')->whereNumber('appel')->middleware('can:'.Droit::AppelsFondsVoir->value)->name('.show');
            Route::get('/{appel}/avis', 'avis')->middleware('can:'.Droit::AppelsFondsVoir->value)->name('.avis');
            Route::get('/virements/{declaration}/avis', 'avisVirement')->middleware('can:'.Droit::AppelsFondsVoir->value)->name('.avis-virement');
            Route::post('/virements/{declaration}/rapprocher', 'rapprocher')->middleware('can:'.Droit::AppelsFondsRapprocher->value)->name('.rapprocher');
            Route::post('/virements/{declaration}/rejeter', 'rejeter')->middleware('can:'.Droit::AppelsFondsRapprocher->value)->name('.rejeter');
        });

        Route::controller(RegistreAController::class)->prefix('registre-a')->name('registre-a')->group(function () {
            Route::get('/', 'index')->middleware('can:'.Droit::RegistreAVoir->value);
            Route::get('/export', 'exporter')->middleware('can:'.Droit::RegistreAExporter->value)->name('.export');
            Route::get('/{ligne}/attestation', 'attestation')->middleware('can:'.Droit::RegistreAVoir->value)->name('.attestation');
        });

        // Paramètres de la plateforme : le droit de chaque section est vérifié dans le contrôleur.
        Route::controller(PlateformeController::class)->prefix('plateforme')->name('plateforme')
            ->middleware('can:'.Droit::PlateformeVoir->value)->group(function () {
                Route::get('/', 'index');
                Route::put('/{section}', 'update')->name('.update');
                // L'aperçu reçoit le formulaire d'enregistrement (méthode simulée PUT).
                Route::match(['post', 'put'], '/{section}/apercu', 'apercu')->name('.apercu');
                Route::post('/versions/{version}/restaurer', 'restaurer')->whereNumber('version')
                    ->middleware('can:'.Droit::PlateformeModifierTout->value)->name('.restaurer');
            });

        // Paramètres en lecture seule (SUPER_ADMIN) : renvoie vers les écrans de consultation.
        Route::get('/parametres', [SectionController::class, 'parametres'])
            ->middleware('can:'.Droit::ParametresVoir->value)->name('parametres');

        // Utilisateurs back-office (SUPER_ADMIN).
        Route::controller(UtilisateursController::class)->prefix('utilisateurs')->name('utilisateurs')
            ->middleware('can:'.Droit::UtilisateursGerer->value)->group(function () {
                Route::get('/', 'index');
                Route::get('/inviter', 'create')->name('.inviter');
                Route::post('/', 'store')->name('.store');
                Route::get('/{utilisateur}', 'show')->whereNumber('utilisateur')->name('.show');
                Route::post('/{utilisateur}/role', 'modifierRole')->name('.role');
                Route::post('/{utilisateur}/desactiver', 'desactiver')->name('.desactiver');
                Route::post('/{utilisateur}/reactiver', 'reactiver')->name('.reactiver');
                Route::post('/{utilisateur}/totp', 'reinitialiserTotp')->name('.totp');
                Route::post('/{utilisateur}/deverrouiller', 'deverrouiller')->name('.deverrouiller');
                Route::post('/{utilisateur}/lien', 'envoyerLien')->name('.lien');
            });

        Route::get('/', [SectionController::class, 'tableau'])
            ->middleware('can:'.MenuBackoffice::entree('tableau')['droit']->value)
            ->name('tableau');

        Route::get('/connexions', [ConnexionsController::class, 'index'])
            ->middleware('can:'.MenuBackoffice::entree('connexions')['droit']->value)
            ->name('connexions');

        // Sections livrées aux étapes suivantes : déjà protégées par leur droit.
        foreach (['demandes', 'contenus', 'approbations', 'securite', 'simulations', 'conformite', 'souscriptions',
            'registre', 'audit', 'regulation', 'observations', 'referentiels'] as $section) {
            $entree = MenuBackoffice::entree($section);

            Route::get('/'.$section, [SectionController::class, 'show'])
                ->defaults('section', $section)
                ->middleware('can:'.$entree['droit']->value)
                ->name($section);
        }
    });
});
