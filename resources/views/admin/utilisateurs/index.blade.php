<x-admin.layout :titre="__('admin.utilisateurs.titre')" :fil="[['libelle' => __('admin.utilisateurs.titre')]]">
    @php
        $colonne = fn (string $cle) => request()->fullUrlWithQuery(['tri' => $cle, 'sens' => $tri === $cle && $sens === 'asc' ? 'desc' : 'asc', 'page' => null]);
        $indicateur = fn (string $cle) => $tri === $cle ? ($sens === 'asc' ? ' ↑' : ' ↓') : '';
    @endphp

    <div class="bo-carte !mt-0">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <p class="texte-carte max-w-3xl">{{ __('admin.utilisateurs.intro.backoffice') }}</p>
            <a href="{{ route('admin.utilisateurs.inviter') }}" class="bo-bouton bo-bouton-principal">{{ __('admin.utilisateurs.inviter') }}</a>
        </div>

        <form method="GET" action="{{ route('admin.utilisateurs') }}" class="bo-filtres mt-4" role="search">
            <div class="formulaire-champ min-w-[260px] flex-1">
                <label for="q">{{ __('admin.utilisateurs.rechercher') }}</label>
                <input id="q" name="q" type="search" value="{{ request('q') }}" class="champ" placeholder="{{ __('admin.utilisateurs.recherche_aide') }}">
            </div>
            <div class="formulaire-champ">
                <label for="role">{{ __('admin.utilisateurs.colonnes.role') }}</label>
                <select id="role" name="role" class="champ">
                    <option value="">{{ __('admin.utilisateurs.tous') }}</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->value }}" @selected(request('role') === $role->value)>{{ $role->label() }}</option>
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
            <button type="submit" class="bo-bouton bo-bouton-secondaire">{{ __('admin.utilisateurs.filtrer') }}</button>
            @if (request()->hasAny(['q', 'role', 'statut']))
                <a href="{{ route('admin.utilisateurs') }}" class="lien-action">{{ __('admin.utilisateurs.reinitialiser_filtres') }}</a>
            @endif
        </form>

        <p class="texte-carte mt-4" role="status">{{ __('admin.utilisateurs.resultats', ['n' => format_nombre($utilisateurs->total())]) }}</p>

        <div class="mt-2 overflow-x-auto">
            <table class="bo-tableau">
                <thead>
                    <tr>
                        <th scope="col"><a href="{{ $colonne('nom') }}" aria-label="{{ __('admin.utilisateurs.trier_par', ['colonne' => __('admin.utilisateurs.colonnes.nom')]) }}">{{ __('admin.utilisateurs.colonnes.nom') }}{{ $indicateur('nom') }}</a></th>
                            <th scope="col">{{ __('admin.utilisateurs.colonnes.email') }}</th>
                            <th scope="col">{{ __('admin.utilisateurs.colonnes.role') }}</th>
                            <th scope="col">{{ __('admin.utilisateurs.colonnes.statut') }}</th>
                            <th scope="col">{{ __('admin.utilisateurs.colonnes.totp') }}</th>
                            <th scope="col"><a href="{{ $colonne('connexion') }}">{{ __('admin.utilisateurs.colonnes.connexion') }}{{ $indicateur('connexion') }}</a></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($utilisateurs as $u)
                        <tr>
                            <td><a href="{{ route('admin.utilisateurs.show', $u) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $u->name }}</a></td>
                                    @php($code = $u->codeRole())
                                <td>{{ $u->email }}</td>
                                <td><span class="bo-badge {{ $code->badgeClasse() }}">{{ $code->badge() }}</span></td>
                                <td>@include('admin.utilisateurs._statut', ['u' => $u, 'invitation' => in_array($u->id, $invitationsEnAttente, true)])</td>
                                <td>{{ $u->totpActive() ? __('admin.utilisateurs.totp_active') : __('admin.utilisateurs.totp_inactive') }}</td>
                                <td class="whitespace-nowrap">{{ $u->derniere_connexion_le?->format('d/m/Y H:i') ?? __('admin.utilisateurs.jamais') }}</td>
                            </tr>
                    @empty
                        <tr><td colspan="6">{{ __('admin.utilisateurs.aucun') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $utilisateurs->links() }}</div>
    </div>
</x-admin.layout>
