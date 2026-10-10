<x-admin.layout :titre="__('admin.connexions.titre')" :fil="[['libelle' => __('admin.connexions.titre')]]">
    <div class="bo-carte">
        <p class="texte-carte">{{ __('admin.connexions.intro') }}</p>

        <div class="mt-4 overflow-x-auto">
            <table class="bo-tableau">
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.connexions.date') }}</th>
                        <th scope="col">{{ __('admin.connexions.compte') }}</th>
                        <th scope="col">{{ __('admin.connexions.etape') }}</th>
                        <th scope="col">{{ __('admin.connexions.resultat') }}</th>
                        <th scope="col">{{ __('admin.connexions.motif') }}</th>
                        <th scope="col">{{ __('admin.connexions.ip') }}</th>
                        <th scope="col">{{ __('admin.connexions.navigateur') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($connexions as $connexion)
                        <tr>
                            <td class="whitespace-nowrap">{{ \Carbon\Carbon::parse($connexion->created_at)->format('d/m/Y H:i:s') }}</td>
                            <td>{{ $connexion->nom_utilisateur ?? '—' }}<br><span class="texte-carte">{{ $connexion->email }}</span></td>
                            <td>{{ __('admin.connexions.etapes.'.$connexion->etape) }}</td>
                            <td>
                                <span @class(['bo-etiquette', 'bo-etiquette-succes' => $connexion->succes, 'bo-etiquette-echec' => ! $connexion->succes])>
                                    {{ $connexion->succes ? __('admin.connexions.succes') : __('admin.connexions.echec') }}
                                </span>
                            </td>
                            <td>{{ $connexion->motif ? __('admin.connexions.motifs.'.$connexion->motif) : '—' }}</td>
                            <td class="whitespace-nowrap">{{ $connexion->ip }}</td>
                            <td class="max-w-[260px] truncate" title="{{ $connexion->user_agent }}">{{ $connexion->user_agent }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">{{ __('admin.connexions.aucune') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $connexions->links() }}</div>
    </div>
</x-admin.layout>
