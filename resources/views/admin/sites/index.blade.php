@php
    $gerer = auth()->user()->can(\App\Enums\Droit::SitesGerer->value);
    $c = __('admin.sites.colonnes');
    $l = __('admin.sites.liste');
@endphp

<x-admin.layout :titre="__('admin.sites.titre')" :fil="[['libelle' => __('admin.sites.titre')]]">
    <div class="bo-carte !mt-0">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-4">
            <p class="texte-carte max-w-3xl">{{ __('admin.sites.intro') }}</p>
            @if ($gerer)
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('admin.referencement.imports') }}" class="bo-bouton bo-bouton-secondaire">{{ __('admin.menu.imports') }}</a>
                    <a href="{{ route('admin.sites.create') }}" class="bo-bouton bo-bouton-principal">{{ __('admin.sites.nouveau') }}</a>
                </div>
            @endif
        </div>

        @if ($sites->isEmpty())
            <x-etat-vide icone="info" :titre="__('admin.sites.aucun')" :lien="$gerer ? route('admin.sites.create') : null" :libelle-lien="__('admin.sites.nouveau')">{{ __('admin.sites.aucun_aide') }}</x-etat-vide>
        @else
            <div class="overflow-x-auto">
                <table class="bo-tableau">
                    <thead><tr>
                        @foreach ($c as $cle => $libelle)<th scope="col" @class(['montant' => $cle === 'boutiques'])>{{ $libelle }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($sites as $site)
                            <tr>
                                <td class="font-mono font-semibold">{{ $site->code ?? '—' }}</td>
                                <td><a href="{{ route('admin.sites.show', $site) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $site->nom }}</a></td>
                                <td>{{ $site->localite() ?? '—' }}@if ($site->arrondissement) · {{ $site->arrondissement }}@endif</td>
                                <td>{{ $site->phase?->label() ?? '—' }}</td>
                                <td>@include('admin.parametrage._statut', ['statut' => $site->statut])</td>
                                <td class="montant">{{ format_nombre($site->boutiques_count) }}
                                    @if ($site->boutiques_sans_niveau_count > 0)<br><span class="text-sm font-semibold text-rouge">{{ trans_choice('admin.sites.boutiques.sans_niveau_alerte', $site->boutiques_sans_niveau_count, ['n' => $site->boutiques_sans_niveau_count]) }}</span>@endif</td>
                                <td>{{ $site->version ?: '—' }}</td>
                                <td>
                                    <div class="flex gap-2">
                                        <x-admin.bouton-icone :href="route('admin.sites.show', $site)" icone="voir" :intitule="$l['voir'].' '.$site->nom" :infobulle="$l['voir']" />
                                        @if ($gerer)
                                            @if ($site->estModifiable())
                                                <x-admin.bouton-icone :href="route('admin.sites.edit', $site)" icone="modifier" :intitule="$l['modifier'].' '.$site->nom" :infobulle="$l['modifier']" />
                                            @else
                                                <x-admin.bouton-icone icone="modifier" :intitule="$l['modifier'].' '.$site->nom" :infobulle="$l['non_modifiable']" disabled />
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-admin.layout>
