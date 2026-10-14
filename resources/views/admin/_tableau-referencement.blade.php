{{-- Tableau de bord du GESTIONNAIRE DES SITES ET BOUTIQUES : sites par statut, boutiques par état physique, derniers imports. --}}
@php
    use App\Enums\EtatPhysiqueBoutique;
    use App\Enums\StatutSite;
    use App\Models\AuditLog;
    use App\Models\Boutique;
    use App\Models\Site;

    $r = __('admin.tableau.referencement');
    $sitesParStatut = Site::query()->selectRaw('statut, count(*) as n')->groupBy('statut')->pluck('n', 'statut');
    $boutiquesParEtat = Boutique::query()->selectRaw('etat_physique, count(*) as n')->groupBy('etat_physique')->pluck('n', 'etat_physique');
    $imports = AuditLog::query()->whereIn('action', array_keys(\App\Http\Controllers\Admin\ImportsController::ACTIONS))->with('acteur')->latest('id')->limit(5)->get();
@endphp
<section class="bo-carte" aria-labelledby="referencement-titre">
    <h2 id="referencement-titre" class="!text-[1.15rem]">{{ $r['titre'] }}</h2>
    <div class="mt-3 grid gap-[18px] lg:grid-cols-3">
        <div>
            <h3 class="!text-base"><a href="{{ route('admin.sites') }}" class="text-vert-fonce underline underline-offset-2">{{ $r['sites'] }}</a></h3>
            <ul class="mt-1 space-y-1">
                @foreach (StatutSite::cases() as $statut)
                    <li class="flex justify-between gap-3"><span>{{ $statut->label() }}</span><span class="font-semibold">{{ format_nombre((int) ($sitesParStatut[$statut->value] ?? 0)) }}</span></li>
                @endforeach
            </ul>
        </div>
        <div>
            <h3 class="!text-base"><a href="{{ route('admin.referencement.boutiques') }}" class="text-vert-fonce underline underline-offset-2">{{ $r['boutiques'] }}</a></h3>
            <ul class="mt-1 space-y-1">
                @foreach (EtatPhysiqueBoutique::cases() as $etat)
                    <li class="flex justify-between gap-3"><span>{{ $etat->label() }}</span><span class="font-semibold">{{ format_nombre((int) ($boutiquesParEtat[$etat->value] ?? 0)) }}</span></li>
                @endforeach
                <li class="flex justify-between gap-3"><span>{{ $r['non_renseigne'] }}</span><span class="font-semibold">{{ format_nombre((int) ($boutiquesParEtat[''] ?? 0)) }}</span></li>
            </ul>
        </div>
        <div>
            <h3 class="!text-base"><a href="{{ route('admin.referencement.imports') }}" class="text-vert-fonce underline underline-offset-2">{{ $r['imports'] }}</a></h3>
            @if ($imports->isEmpty())
                <p class="texte-carte mt-1">{{ $r['aucun_import'] }}</p>
            @else
                <ul class="mt-1 space-y-1 text-sm">
                    @foreach ($imports as $import)
                        <li>{{ $import->created_at->format('d/m/Y H:i') }} — {{ __('admin.imports.types.'.$import->action) }} — « {{ $import->nouvelle_valeur['fichier'] ?? '' }} »</li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</section>
