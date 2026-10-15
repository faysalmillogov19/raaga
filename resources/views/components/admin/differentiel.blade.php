@props(['differentiel', 'libelles', 'premiere' => false])

{{-- Différentiel entre la version soumise et la précédente : [champ => [avant, après]]. --}}
@if ($premiere)
    <p class="texte-carte">{{ __('admin.parametrage.differentiel_premiere') }}</p>
@elseif ($differentiel === [])
    <p class="texte-carte">{{ __('admin.parametrage.aucun_changement') }}</p>
@endif

@if ($differentiel !== [] && ! $premiere)
    <div class="mt-2 overflow-x-auto">
        <table class="bo-tableau">
            <thead><tr><th scope="col">{{ __('admin.parametrage.champ') }}</th><th scope="col">{{ __('admin.parametrage.avant') }}</th><th scope="col">{{ __('admin.parametrage.apres') }}</th></tr></thead>
            <tbody>
                @foreach ($differentiel as $champ => [$avant, $apres])
                    <tr class="adm-diff-ligne" data-champ-modifie="{{ $champ }}">
                        <td class="font-semibold">{{ ucfirst($libelles[$champ] ?? $champ) }}</td>
                        <td class="text-gris line-through">{{ \App\Support\AffichageParametres::valeur($champ, $avant) }}</td>
                        <td class="font-bold text-vert-fonce">{{ \App\Support\AffichageParametres::valeur($champ, $apres) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
