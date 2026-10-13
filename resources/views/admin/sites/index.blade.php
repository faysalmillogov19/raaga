@php($gerer = auth()->user()->can(\App\Enums\Droit::SitesGerer->value))

<x-admin.layout :titre="__('admin.sites.titre')" :fil="[['libelle' => __('admin.sites.titre')]]">
    <div class="bo-carte">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-4">
            <p class="texte-carte max-w-3xl">{{ __('admin.sites.intro') }}</p>
            @if ($gerer)
                <a href="{{ route('admin.sites.create') }}" class="bo-bouton bo-bouton-principal">{{ __('admin.sites.nouveau') }}</a>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr>
                    @foreach (__('admin.sites.colonnes') as $cle => $libelle)
                        <th scope="col" @class(['montant' => $cle === 'boutiques'])>{{ $libelle }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @forelse ($sites as $site)
                        <tr>
                            <td class="font-mono font-semibold">{{ $site->code ?? '—' }}</td>
                            <td><a href="{{ route('admin.sites.show', $site) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $site->nom }}</a></td>
                            <td>{{ $site->region }} · {{ $site->commune }}</td>
                            <td>@include('admin.parametrage._statut', ['statut' => $site->statut])</td>
                            <td class="montant">{{ format_nombre($site->boutiques_count) }}
                                @if ($site->boutiques_sans_niveau_count > 0)<br><span class="text-sm font-semibold text-rouge">{{ trans_choice('admin.sites.boutiques.sans_niveau_alerte', $site->boutiques_sans_niveau_count, ['n' => $site->boutiques_sans_niveau_count]) }}</span>@endif</td>
                            <td>{{ $site->version ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">{{ __('admin.sites.aucun') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Types de boutiques : catégories communes à tous les sites --}}
    <section class="bo-carte" aria-labelledby="types-titre">
        <h2 id="types-titre" class="!text-[1.15rem]">{{ __('admin.sites.types.titre') }}</h2>
        <p class="texte-carte mt-1">{{ __('admin.sites.types.aide') }}</p>

        @if ($types->isEmpty())
            <p class="mt-3">{{ __('admin.sites.types.aucun') }}</p>
        @else
            <ul class="mt-3 divide-y divide-ligne">
                @foreach ($types as $type)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                        <span>@if ($type->code)<span class="font-mono font-semibold text-vert-fonce">{{ $type->code }}</span> · @endif<span class="font-semibold text-encre">{{ $type->libelle }}</span>
                            @unless ($type->code)<span class="text-sm font-semibold text-rouge">({{ __('admin.sites.types.code_manquant') }})</span>@endunless
                            @if ($type->description) <span class="texte-carte">— {{ $type->description }}</span>@endif
                            <span class="texte-carte">· {{ __('admin.sites.types.nombre', ['n' => format_nombre($type->boutiques_count)]) }}</span></span>
                        @if ($gerer && ! $type->code)
                            <form method="POST" action="{{ route('admin.sites.types.update', $type) }}" class="flex items-end gap-2" novalidate>
                                @csrf @method('PATCH')
                                <label for="type-code-{{ $type->id }}" class="sr-only">{{ __('admin.sites.types.code') }}</label>
                                <input id="type-code-{{ $type->id }}" name="code" maxlength="6" required class="champ !w-28 font-mono uppercase" placeholder="{{ __('admin.sites.types.code') }}">
                                <button type="submit" class="bo-bouton bo-bouton-secondaire">{{ __('admin.sites.types.enregistrer_code') }}</button>
                            </form>
                        @endif
                        @if ($gerer && $type->boutiques_count === 0)
                            <form method="POST" action="{{ route('admin.sites.types.destroy', $type) }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="bo-bouton bo-bouton-secondaire">{{ __('admin.sites.types.supprimer') }}</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($gerer)
            <form method="POST" action="{{ route('admin.sites.types.store') }}" class="mt-4 grid gap-3 lg:grid-cols-[10rem_1fr_2fr_auto] lg:items-end" novalidate>
                @csrf
                <x-admin.champ nom="code" :libelle="__('admin.sites.types.code')" maxlength="6" required :aide="__('admin.sites.types.code_aide')" />
                <x-admin.champ nom="libelle" :libelle="__('admin.sites.types.libelle')" maxlength="80" required />
                <x-admin.champ nom="description" :libelle="__('admin.sites.types.description')" maxlength="300" />
                <button type="submit" class="bo-bouton bo-bouton-principal">{{ __('admin.sites.types.ajouter') }}</button>
            </form>
        @endif
    </section>
</x-admin.layout>
