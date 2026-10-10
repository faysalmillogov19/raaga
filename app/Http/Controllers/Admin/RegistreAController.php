<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\LigneRegistreA;
use App\Services\Audit\Journal;
use App\Services\Institutionnels\RegistreA;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Registre des actions A (lecture : SUPER_ADMIN, RÉGULATEUR, VALIDATEUR ; export : RÉGULATEUR),
 * avec la répartition du capital A / B et la vérification de la chaîne d'intégrité.
 */
class RegistreAController extends Controller
{
    public function index(): View
    {
        Gate::authorize(Droit::RegistreAVoir->value);

        return view('admin.registre-a.index', [
            'lignes' => LigneRegistreA::query()->with(['institution', 'convention'])->orderBy('id')->paginate(50),
            'repartition' => RegistreA::repartitionCapital(),
            'chaineIntegre' => RegistreA::chaineIntegre(),
        ]);
    }

    public function attestation(LigneRegistreA $ligne): StreamedResponse
    {
        Gate::authorize(Droit::RegistreAVoir->value);

        return Storage::disk(RegistreA::DISQUE)->response($ligne->attestation_chemin, 'attestation-'.$ligne->numero_certificat.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function exporter(Request $request, Journal $journal): StreamedResponse
    {
        Gate::authorize(Droit::RegistreAExporter->value);
        $journal->ecrire($request->user(), 'registre_a.export', 'LigneRegistreA', null);

        return response()->streamDownload(function () {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['certificat', 'institution', 'convention', 'nb_actions', 'numero_debut', 'numero_fin', 'date_inscription', 'empreinte'], ';');
            LigneRegistreA::query()->with(['institution', 'convention'])->orderBy('id')->each(fn (LigneRegistreA $l) => fputcsv($sortie, [
                $l->numero_certificat, $l->institution->denomination, $l->convention->reference, $l->nb_actions,
                $l->numero_debut, $l->numero_fin, $l->date_inscription->toDateString(), $l->empreinte,
            ], ';'));
            fclose($sortie);
        }, 'registre-actions-a-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
