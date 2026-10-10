{{-- Historique commun : versions figées, décisions du validateur, avenants. --}}
@php($libellesAudit = __('admin.utilisateurs.audit'))

<section class="bo-carte" aria-labelledby="historique-titre">
    <h2 id="historique-titre" class="!text-[1.15rem]">{{ __('admin.parametrage.versions') }}</h2>
    @if ($versions->isEmpty())
        <p class="texte-carte mt-2">{{ __('admin.parametrage.aucune_version') }}</p>
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr><th scope="col">Version</th><th scope="col">{{ __('admin.parametrage.date') }}</th><th scope="col">{{ __('admin.parametrage.auteur') }}</th><th scope="col">{{ __('admin.parametrage.motif') }}</th></tr></thead>
                <tbody>
                    @foreach ($versions as $v)
                        <tr>
                            <td>{{ $v->numero }}</td>
                            <td class="whitespace-nowrap">{{ $v->created_at?->format('d/m/Y H:i') }}</td>
                            <td>{{ $v->auteur?->name }}</td>
                            <td>{{ $v->motif }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h3 class="mt-6">{{ __('admin.parametrage.decisions') }}</h3>
    @if ($decisions->isEmpty())
        <p class="texte-carte mt-1">—</p>
    @else
        <div class="mt-2 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr><th scope="col">Version</th><th scope="col">{{ __('admin.parametrage.statut') }}</th><th scope="col">{{ __('admin.parametrage.date') }}</th><th scope="col">{{ __('admin.parametrage.decideur') }}</th><th scope="col">{{ __('admin.parametrage.motif') }}</th></tr></thead>
                <tbody>
                    @foreach ($decisions as $d)
                        <tr>
                            <td>{{ $d->version }}</td>
                            <td>@include('admin.parametrage._statut', ['statut' => $d->decision])</td>
                            <td class="whitespace-nowrap">{{ $d->created_at?->format('d/m/Y H:i') }}</td>
                            <td>{{ $d->decideur?->name }}</td>
                            <td>{{ $d->motif ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
