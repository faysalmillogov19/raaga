<?php

namespace App\Http\Controllers\Compte;

use App\Enums\DocumentAccepte;
use App\Enums\Role;
use App\Enums\StatutInscriptionBoutique;
use App\Enums\StatutReservationBoutique;
use App\Enums\StatutSouscription;
use App\Http\Controllers\Controller;
use App\Models\Acceptation;
use App\Models\InscriptionBoutique;
use App\Models\LigneRegistre;
use App\Models\ManifestationInteret;
use App\Models\ReservationBoutique;
use App\Models\Souscription;
use App\Services\Documents\GenerateurPdf;
use App\Services\Paiement\ServicePaiement;
use App\Support\Phase;
use App\Support\Recu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Mon espace (citoyen) : deux démarches dans deux onglets distincts, « Mes actions » (souscriptions, documents,
 * dividendes ; si la présentation des actions est visible) et « Mes boutiques » (candidatures, cautions), puis le profil.
 * Toutes les requêtes partent du compte connecté : un citoyen ne voit jamais les données d'un autre.
 */
class EspaceController extends Controller
{
    public const ONGLETS = ['actions', 'interets', 'boutiques', 'profil'];

    /** Anciennes adresses des rubriques des actions : regroupées dans l'onglet « Mes actions ». */
    private const RUBRIQUES_ACTIONS = ['souscriptions', 'documents', 'dividendes'];

    public function show(Request $request, ServicePaiement $paiements): View
    {
        $user = $request->user();

        // Profils sans espace citoyen : état vide (le back-office et les institutions ont leur propre espace).
        if (! $user->hasRole(Role::Citoyen)) {
            return view('public.preparation', ['page' => 'espace', 'titre' => __('public.preparation.pages.espace.titre')]);
        }

        $paiements->expirerReservations();

        $souscriptions = $user->souscriptions()->with(['paiements', 'tranche'])->latest('id')->get();
        // Présentation des actions masquée : ni souscriptions d'actions, ni documents ou dividendes d'actionnaire.
        $actions = Phase::presentationActions();
        $onglets = $actions ? self::ONGLETS : ['boutiques', 'profil'];
        $demande = in_array($request->query('onglet'), self::RUBRIQUES_ACTIONS, true) ? 'actions' : $request->query('onglet');
        $onglet = in_array($demande, $onglets, true) ? $demande : $onglets[0];
        $candidatures = ReservationBoutique::query()->where('user_id', $user->id)->with(['campagne', 'boutique.type'])->latest('id')->get();
        $cautionsPayees = $candidatures->whereIn('statut', [StatutReservationBoutique::Reservee, StatutReservationBoutique::Attribuee]);

        $payees = $souscriptions->where('statut', StatutSouscription::Payee);
        $allouees = $souscriptions->where('statut', StatutSouscription::Allouee);

        // Notifications (relances, annulation) : les cinq plus récentes, marquées lues une fois affichées.
        $notifications = $user->notifications()->latest()->limit(5)->get();
        $user->unreadNotifications()->whereIn('id', $notifications->pluck('id'))->update(['read_at' => now()]);

        return view('public.espace.show', [
            'user' => $user,
            'notifications' => $notifications,
            'onglet' => $onglet,
            'onglets' => $onglets,
            'souscriptions' => $souscriptions,
            'indicateurs' => $onglet === 'actions' ? [
                'investi' => format_fcfa($payees->sum('montant') + $allouees->sum(fn (Souscription $s) => $s->montant - (int) $s->montant_a_rembourser)),
                'en_attente' => format_nombre($payees->sum('nb_actions_demandees')),
                'allouees' => format_nombre($allouees->sum('nb_actions_allouees')),
                // Aucun versement de dividende aux actions B n'est encore enregistré par la plateforme.
                'dividendes' => format_fcfa(0),
            ] : ($onglet === 'boutiques' ? [
                'pre_reservees' => format_nombre($candidatures->where('statut', StatutReservationBoutique::PreReservee)->count()),
                'cautions' => format_fcfa($cautionsPayees->sum('caution')),
                'reservees' => format_nombre($candidatures->where('statut', StatutReservationBoutique::Reservee)->count()),
                'attribuees' => format_nombre($candidatures->where('statut', StatutReservationBoutique::Attribuee)->count()),
            ] : []),
            'recus' => $souscriptions->filter(fn (Souscription $s) => Recu::disponible($s))
                ->map(fn (Souscription $s) => ['souscription' => $s, 'paiement' => Recu::paiement($s)])
                ->filter(fn (array $r) => $r['paiement'] !== null),
            'reglements' => Acceptation::query()->where('user_id', $user->id)->where('document', DocumentAccepte::ReglementTranche)
                ->whereNotNull('souscription_id')->with('souscription.tranche')->orderByDesc('accepte_le')->get()
                ->unique(fn (Acceptation $a) => $a->souscription->tranche_id.'-'.$a->version_document),
            'attestations' => $user->lignesRegistre()->with('tranche')->orderByDesc('date_inscription')->get(),
            'candidatures' => $candidatures,
            // Listes d'attente : inscriptions en attente (rang), et inscription retenue de chaque réservation (désistement).
            'inscriptions' => $onglet === 'boutiques' ? InscriptionBoutique::query()->where('user_id', $user->id)
                ->where('statut', StatutInscriptionBoutique::EnAttente)->with(['boutique.type', 'campagne'])->latest('id')->get() : collect(),
            'retenues' => InscriptionBoutique::query()->where('user_id', $user->id)->where('statut', StatutInscriptionBoutique::Retenue)
                ->pluck('id', 'reservation_boutique_id'),
            // RAAGA Invest : manifestations d'intérêt du citoyen (simulations non engageantes, aucun paiement).
            'interets' => $actions ? ManifestationInteret::query()->where('user_id', $user->id)->latest('id')->get() : collect(),
        ]);
    }

    /** Règlement de la tranche tel qu'accepté lors d'une souscription du citoyen. */
    public function reglement(Request $request, Souscription $souscription): View
    {
        abort_unless($souscription->user_id === $request->user()->id, 403);

        return view('public.souscription.reglement', ['tranche' => $souscription->tranche]);
    }

    /** Attestation d'actionnaire (actions B), après allocation. */
    public function attestation(Request $request, LigneRegistre $ligne, GenerateurPdf $pdf): Response|RedirectResponse
    {
        abort_unless($ligne->user_id === $request->user()->id, 403);
        // Actions gelées (identité non corrigée dans le délai) : attestation bloquée jusqu'au dégel.
        if ($request->user()->actionsGelees()) {
            return redirect()->route('espace', ['onglet' => 'actions'])->with('erreur', __('public.invest.identite.attestation_bloquee'));
        }

        return response($pdf->rendre('documents.attestation-actions-b', [
            'ligne' => $ligne->load(['tranche', 'user']),
            'totalDetenu' => (int) $request->user()->lignesRegistre()->sum('nb_actions'),
        ]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="attestation-'.$ligne->numero_certificat.'.pdf"',
        ]);
    }
}
