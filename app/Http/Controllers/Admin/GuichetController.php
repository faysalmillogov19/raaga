<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\StatutReservationBoutique;
use App\Http\Controllers\Controller;
use App\Models\Carte;
use App\Models\Recepisse;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Notifications\CarteImprimee;
use App\Services\Audit\Journal;
use App\Services\Cartes\FichiersCarte;
use App\Services\Cartes\ReglagesCarteManquants;
use App\Services\Cartes\RenduCarte;
use App\Support\Ifu;
use App\Support\Plateforme\ReglagesCarte;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Guichet (Agent de guichet, ADMIN) : vérification d'une carte scannée (photo, nom, type, emplacements, statut en grand,
 * chaque scan tracé au journal d'audit), recherche par numéro de carte (ou identifiant fiscal et numéro de registre
 * Raaga d'un commerçant), dossier du titulaire (sans aucun montant).
 */
class GuichetController extends Controller
{
    public function __construct(private readonly Journal $journal) {}

    public function index(): View
    {
        Gate::authorize(Droit::CartesVerifier->value);

        return view('admin.guichet.index');
    }

    /**
     * Numéro de carte (RG-C-000123, ou IFU pour une carte de commerçant), code de contrôle imprimé (K7M2-Q9XP : seule
     * la version en cours le porte) ou adresse scannée : ouvre la vérification de la carte (à défaut de carte courante
     * portant ce numéro, la dernière émise).
     */
    public function rechercher(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::CartesVerifier->value);
        $saisie = trim((string) $request->validate(['carte' => ['required', 'string', 'max:300']])['carte']);
        // Récépissé de préinscription (05/11/2026) : numéro RP-AAAA-000123 ou QR code (/r/…) : dossier du titulaire.
        $recepisse = preg_match('#/r/([A-Za-z0-9]+)#', $saisie, $r) ? Recepisse::query()->where('jeton', $r[1])->first()
            : Recepisse::query()->where('numero', mb_strtoupper($saisie))->first();
        if ($recepisse) {
            return redirect()->route('admin.guichet.titulaire', $recepisse->user_id);
        }
        $jeton = preg_match('#/c/([A-Za-z0-9]+)#', $saisie, $m) ? $m[1] : null;
        $code = $jeton ? null : Carte::normaliserCode($saisie);
        $carte = Carte::query()->when($jeton, fn ($q) => $q->where('jeton', $jeton), fn ($q) => $q->when($code,
            fn ($q) => $q->where('code_controle', $code),
            fn ($q) => $q->where('numero', str_starts_with(mb_strtoupper($saisie), 'RG-') ? mb_strtoupper($saisie) : Ifu::normaliser($saisie))))
            ->orderByRaw('courante is null')->latest('id')->first();
        if (! $carte) {
            return back()->withErrors(['carte' => __('admin.guichet.erreurs.introuvable')])->withInput();
        }

        return redirect()->route('admin.guichet.carte', $carte->jeton);
    }

    public function carte(Request $request, string $jeton): View
    {
        Gate::authorize(Droit::CartesVerifier->value);
        $carte = Carte::query()->where('jeton', $jeton)->with('user')->first();
        // Chaque scan d'un agent connecté est tracé (carte inconnue comprise).
        $this->journal->ecrire($request->user(), 'carte.scannee', 'Carte', $carte?->id, null,
            ['numero' => $carte?->numero, 'resultat' => $carte?->estValide() ? 'valide' : 'non_valide']);

        return view('admin.guichet.carte', ['carte' => $carte]);
    }

    /**
     * Impression au guichet, après contrôle de la pièce d'identité du titulaire : PDF au format carte bancaire (imprimante
     * classique ou à cartes), impression tracée au journal d'audit, titulaire notifié. Le PDF est celui généré à l'avance
     * en tâche de fond ; pas encore prêt : génération demandée, rien n'est tracé ni notifié (réglage de la carte manquant :
     * l'agent en voit le détail, à transmettre au SUPER_ADMIN).
     */
    public function imprimer(Request $request, string $jeton, FichiersCarte $fichiers, RenduCarte $rendu): Response|RedirectResponse
    {
        Gate::authorize(Droit::CartesImprimer->value);
        $request->validate(['piece_verifiee' => ['accepted']], ['piece_verifiee.accepted' => __('admin.guichet.erreurs.piece')]);
        $carte = Carte::query()->where('jeton', $jeton)->with('user')->firstOrFail();
        if (! $carte->courante || ! $carte->estValide()) {
            return back()->withErrors(['piece_verifiee' => __('admin.guichet.erreurs.non_imprimable')]);
        }
        if (! $fichiers->prets($carte)) {
            if ($carte->fichiersEnEchec()) {
                return back()->withErrors(['piece_verifiee' => __('admin.guichet.erreurs.echec')]);
            }
            if (! $carte->reglages_rendu && ReglagesCarte::instantane() === null) {
                return back()->withErrors(['piece_verifiee' => (new ReglagesCarteManquants(ReglagesCarte::manquants()))->getMessage()]);
            }
            $fichiers->demander($carte);

            return back()->withErrors(['piece_verifiee' => __('admin.guichet.erreurs.preparation')]);
        }
        $pdf = $fichiers->lire($carte, 'pdf');
        $this->journal->ecrire($request->user(), 'carte.imprimee', 'Carte', $carte->id, null, ['numero' => $carte->numero, 'version' => $carte->version]);
        $carte->user->notify(new CarteImprimee($carte));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$rendu->nomFichier($carte, 'pdf').'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function titulaire(User $titulaire): View
    {
        Gate::authorize(Droit::CartesVerifier->value);
        // Titulaire d'une carte, ou préinscrit sans IFU avec un récépissé (05/11/2026).
        abort_unless($titulaire->cartes()->exists() || Recepisse::query()->where('user_id', $titulaire->id)->exists(), 404);

        return view('admin.guichet.titulaire', [
            'titulaire' => $titulaire,
            'cartes' => $titulaire->cartes()->latest('id')->get(),
            'emplacements' => ReservationBoutique::query()->where('user_id', $titulaire->id)->where('statut', StatutReservationBoutique::Attribuee)
                ->with('boutique.site')->get()->sortBy(fn (ReservationBoutique $r) => $r->boutique?->code)->values(),
            // Formalisation différée (05/11/2026) : récépissés de préinscription à imprimer.
            'recepisses' => Recepisse::query()->where('user_id', $titulaire->id)->with('boutique.site')->latest('id')->get(),
        ]);
    }
}
