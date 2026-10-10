@php
    $a = fn (string $cle, array $p = []) => __('admin.assistance.'.$cle, $p);
    $colonne = fn (string $cle) => request()->fullUrlWithQuery(['tri' => $cle, 'sens' => $tri === $cle && $sens === 'asc' ? 'desc' : 'asc', 'page' => null]);
    $indicateur = fn (string $cle) => $tri === $cle ? ($sens === 'asc' ? ' ↑' : ' ↓') : '';
@endphp

<x-admin.layout :titre="__('admin.menu.assistance')" :fil="[['libelle' => __('admin.menu.assistance')]]">
    <div class="bo-carte !mt-0">
        <p class="texte-carte max-w-3xl">{{ $a('intro', ['n' => format_nombre($total)]) }}</p>

        <form method="GET" action="{{ route('admin.assistance') }}" class="bo-filtres mt-4" role="search">
            <div class="formulaire-champ min-w-[260px] flex-1">
                <label for="q">{{ __('admin.utilisateurs.rechercher') }}</label>
                <input id="q" name="q" type="search" value="{{ request('q') }}" class="champ" placeholder="{{ $a('recherche_aide') }}">
            </div>
            <div class="formulaire-champ">
                <label for="type">{{ $a('type') }}</label>
                <select id="type" name="type" class="champ">
                    <option value="">{{ __('admin.utilisateurs.tous') }}</option>
                    @foreach (['citoyen', 'agent'] as $type)
                        <option value="{{ $type }}" @selected(request('type') === $type)>{{ $a('types.'.$type) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="formulaire-champ">
                <label for="statut">{{ __('admin.utilisateurs.colonnes.statut') }}</label>
                <select id="statut" name="statut" class="champ">
                    <option value="">{{ __('admin.utilisateurs.tous') }}</option>
                    <option value="actif" @selected(request('statut') === 'actif')>{{ __('admin.utilisateurs.statuts.actif') }}</option>
                    <option value="desactive" @selected(request('statut') === 'desactive')>{{ __('admin.utilisateurs.statuts.desactive') }}</option>
                </select>
            </div>
            <div class="formulaire-champ">
                <label for="kyc">{{ __('admin.utilisateurs.colonnes.kyc') }}</label>
                <select id="kyc" name="kyc" class="champ">
                    <option value="">{{ __('admin.utilisateurs.tous') }}</option>
                    @foreach (\App\Enums\StatutKyc::cases() as $statut)
                        <option value="{{ $statut->value }}" @selected(request('kyc') === $statut->value)>{{ $statut->label() }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="bo-bouton bo-bouton-secondaire">{{ __('admin.utilisateurs.filtrer') }}</button>
            @if (request()->hasAny(['q', 'type', 'statut', 'kyc']))
                <a href="{{ route('admin.assistance') }}" class="lien-action">{{ __('admin.utilisateurs.reinitialiser_filtres') }}</a>
            @endif
        </form>

        <p class="texte-carte mt-4" role="status">{{ __('admin.utilisateurs.resultats', ['n' => format_nombre($utilisateurs->total())]) }}</p>

        <div class="mt-2 overflow-x-auto">
            <table class="bo-tableau">
                <thead>
                    <tr>
                        <th scope="col"><a href="{{ $colonne('nom') }}">{{ __('admin.utilisateurs.colonnes.nom') }}{{ $indicateur('nom') }}</a></th>
                        <th scope="col">{{ __('admin.utilisateurs.colonnes.telephone') }}</th>
                        <th scope="col">{{ $a('type') }}</th>
                        <th scope="col">{{ __('admin.utilisateurs.colonnes.kyc') }}</th>
                        <th scope="col">{{ __('admin.utilisateurs.colonnes.residence') }}</th>
                        <th scope="col"><a href="{{ $colonne('inscription') }}">{{ __('admin.utilisateurs.colonnes.inscription') }}{{ $indicateur('inscription') }}</a></th>
                        <th scope="col">{{ __('admin.utilisateurs.colonnes.statut') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($utilisateurs as $u)
                        <tr>
                            <td><a href="{{ route('admin.assistance.show', $u) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $u->name }}</a></td>
                            <td class="whitespace-nowrap">{{ $u->telephone ? \App\Support\Telephone::formater($u->telephone) : '—' }}</td>
                            <td>{{ $u->codeRole()->label() }}</td>
                            <td>{{ $u->statut_kyc?->label() ?? '—' }}</td>
                            <td>{{ $u->pays_residence === null ? '—' : ($u->pays_residence === 'BF' ? ($u->region ?? 'Burkina Faso') : __('admin.utilisateurs.diaspora').' · '.(config('raaga.inscription.pays')[$u->pays_residence] ?? $u->pays_residence)) }}</td>
                            <td class="whitespace-nowrap">{{ $u->created_at?->format('d/m/Y') }}</td>
                            <td>@include('admin.utilisateurs._statut', ['u' => $u, 'invitation' => false])</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">{{ __('admin.utilisateurs.aucun') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $utilisateurs->links() }}</div>
    </div>
</x-admin.layout>
