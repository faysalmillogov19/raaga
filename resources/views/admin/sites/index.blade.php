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
                            <td><a href="{{ route('admin.sites.show', $site) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $site->nom }}</a></td>
                            <td>{{ $site->region }} · {{ $site->commune }}</td>
                            <td>@include('admin.parametrage._statut', ['statut' => $site->statut])</td>
                            <td class="montant">{{ format_nombre($site->boutiques_count) }}</td>
                            <td>{{ $site->version ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">{{ __('admin.sites.aucun') }}</td></tr>
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
                        <span><span class="font-semibold text-encre">{{ $type->libelle }}</span>
                            @if ($type->description) <span class="texte-carte">— {{ $type->description }}</span>@endif
                            <span class="texte-carte">· {{ __('admin.sites.types.nombre', ['n' => format_nombre($type->boutiques_count)]) }}</span></span>
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
            <form method="POST" action="{{ route('admin.sites.types.store') }}" class="mt-4 grid gap-3 lg:grid-cols-[1fr_2fr_auto] lg:items-end" novalidate>
                @csrf
                <x-admin.champ nom="libelle" :libelle="__('admin.sites.types.libelle')" maxlength="80" required />
                <x-admin.champ nom="description" :libelle="__('admin.sites.types.description')" maxlength="300" />
                <button type="submit" class="bo-bouton bo-bouton-principal">{{ __('admin.sites.types.ajouter') }}</button>
            </form>
        @endif
    </section>
</x-admin.layout>
