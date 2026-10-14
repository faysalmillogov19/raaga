@php($g = __('admin.grilles'))

<x-admin.layout :titre="$g['titre']" :fil="[['libelle' => __('admin.boutiques.titre'), 'url' => route('admin.boutiques')], ['libelle' => $g['titre']]]">
    <div class="bo-carte !mt-0">
        <p class="texte-carte max-w-3xl">{{ $g['intro'] }}</p>
        <p class="texte-carte mt-1 max-w-3xl">{{ $g['calcul'] }}</p>

        <div class="mt-4 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr>@foreach ($g['colonnes'] as $libelle)<th scope="col">{{ $libelle }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse ($sites as $site)
                        @php($confirmee = $site->grilleTarifaire?->confirmee())
                        <tr>
                            <td><a href="{{ route('admin.boutiques.grilles.show', $site) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $site->code ? $site->code.' · ' : '' }}{{ $site->nom }}</a></td>
                            <td>@if ($site->grilleTarifaire)<x-admin.badge-statut :statut="$site->grilleTarifaire->statut" />@else<span class="texte-carte">{{ $g['aucune_grille'] }}</span>@endif</td>
                            <td>{{ $confirmee['version'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3">{{ $g['aucun_site'] }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin.layout>
