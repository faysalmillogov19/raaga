<?php

namespace App\Http\Controllers\Compte;

use App\Enums\DocumentAccepte;
use App\Enums\Role;
use App\Enums\StatutSouscription;
use App\Http\Controllers\Controller;
use App\Models\Acceptation;
use App\Models\LigneRegistre;
use App\Models\Souscription;
use App\Services\Documents\GenerateurPdf;
use App\Services\Paiement\ServicePaiement;
use App\Support\Recu;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Mon espace (citoyen) : indicateurs, souscriptions, documents, dividendes, profil.
 * Toutes les requêtes partent du compte connecté : un citoyen ne voit jamais les données d'un autre.
 */
class EspaceController extends Controller
{
    public const ONGLETS = ['souscriptions', 'documents', 'dividendes', 'profil'];

    public function show(Request $request, ServicePaiement $paiements): View
    {
        $user = $request->user();

        // Profils sans espace citoyen : état vide (le back-office et les institutions ont leur propre espace).
        if (! $user->hasRole(Role::Citoyen)) {
            return view('public.preparation', ['page' => 'espace', 'titre' => __('public.preparation.pages.espace.titre')]);
        }

        $paiements->expirerReservations();

        $souscriptions = $user->souscriptions()->with(['paiements', 'tranche'])->latest('id')->get();
        $onglet = in_array($request->query('onglet'), self::ONGLETS, true) ? $request->query('onglet') : 'souscriptions';

        $payees = $souscriptions->where('statut', StatutSouscription::Payee);
        $allouees = $souscriptions->where('statut', StatutSouscription::Allouee);

        return view('public.espace.show', [
            'user' => $user,
            'onglet' => $onglet,
            'souscriptions' => $souscriptions,
            'indicateurs' => [
                'investi' => $payees->sum('montant') + $allouees->sum(fn (Souscription $s) => $s->montant - (int) $s->montant_a_rembourser),
                'en_attente' => $payees->sum('nb_actions_demandees'),
                'allouees' => $allouees->sum('nb_actions_allouees'),
                // Aucun versement de dividende aux actions B n'est encore enregistré par la plateforme.
                'dividendes' => 0,
            ],
            'recus' => $souscriptions->filter(fn (Souscription $s) => Recu::disponible($s))
                ->map(fn (Souscription $s) => ['souscription' => $s, 'paiement' => Recu::paiement($s)])
                ->filter(fn (array $r) => $r['paiement'] !== null),
            'reglements' => Acceptation::query()->where('user_id', $user->id)->where('document', DocumentAccepte::ReglementTranche)
                ->whereNotNull('souscription_id')->with('souscription.tranche')->orderByDesc('accepte_le')->get()
                ->unique(fn (Acceptation $a) => $a->souscription->tranche_id.'-'.$a->version_document),
            'attestations' => $user->lignesRegistre()->with('tranche')->orderByDesc('date_inscription')->get(),
        ]);
    }

    /** Règlement de la tranche tel qu'accepté lors d'une souscription du citoyen. */
    public function reglement(Request $request, Souscription $souscription): View
    {
        abort_unless($souscription->user_id === $request->user()->id, 403);

        return view('public.souscription.reglement', ['tranche' => $souscription->tranche]);
    }

    /** Attestation d'actionnaire (actions B), après allocation. */
    public function attestation(Request $request, LigneRegistre $ligne, GenerateurPdf $pdf): Response
    {
        abort_unless($ligne->user_id === $request->user()->id, 403);

        return response($pdf->rendre('documents.attestation-actions-b', [
            'ligne' => $ligne->load(['tranche', 'user']),
            'totalDetenu' => (int) $request->user()->lignesRegistre()->sum('nb_actions'),
        ]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="attestation-'.$ligne->numero_certificat.'.pdf"',
        ]);
    }
}
