{{--
    « Mes brouillons » : un tableau par entité (élément, statut, motif du rejet, « Soumettre ») et « Tout soumettre »,
    qui envoie chaque élément au contrôle de sa fiche ; le résultat est donné élément par élément.
--}}
@php
    use App\Contracts\Parametrable;
    use App\Services\Validation\SoumissionGroupee;

    $b = __('admin.soumission.brouillons');
    // Vue « en attente » : déjà soumis, rien à soumettre ; titre, introduction et compteur propres à chaque vue.
    $attente = $vue === 'en_attente';
    $v = $vue ? $b['vues'][$vue] : ['titre' => $b['titre'], 'intro' => $b['intro'], 'vide' => $b['vide']];
    $cles = collect($groupes)->flatMap(fn ($lignes, $entite) => $lignes->map(fn ($l) => $entite.':'.$l->getKey()))->values();
@endphp

<x-admin.layout :titre="$v['titre']" :sous-titre="trans_choice('admin.soumission.brouillons.'.(in_array($vue, ['rejetes', 'en_attente'], true) ? $vue : 'non_soumis'), $cles->count(), ['n' => format_nombre($cles->count())])">
    @if ($cles->isNotEmpty() && ! $attente)
        <x-slot:actions>
            <form method="POST" action="{{ route('admin.soumission.soumettre') }}" data-tout-soumettre>
                @csrf
                @foreach ($cles as $cle)
                    <input type="hidden" name="elements[]" value="{{ $cle }}">
                @endforeach
                <x-admin.bouton variante="principal" type="submit">{{ __('admin.soumission.brouillons.tout_soumettre', ['n' => $cles->count()]) }}</x-admin.bouton>
            </form>
        </x-slot:actions>
    @endif

    <p class="texte-carte max-w-3xl" data-vue="{{ $vue ?? 'tous' }}">{{ $v['intro'] }}</p>

    @forelse ($groupes as $entite => $lignes)
        <section class="bo-carte" aria-labelledby="brouillons-{{ $entite }}" data-groupe="{{ $entite }}">
            <h2 id="brouillons-{{ $entite }}" class="flex items-center gap-2 !text-[1.15rem]">
                <span>{{ $b['groupes'][$entite] }}</span><span class="adm-compteur !ml-0">{{ $lignes->count() }}</span>
            </h2>
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau">
                    <thead><tr>@foreach ($attente ? array_slice($b['colonnes'], 0, 2) : $b['colonnes'] as $libelle)<th scope="col">{{ $libelle }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($lignes as $ligne)
                            @php
                                $objet = SoumissionGroupee::objet($entite, $ligne);
                                $libelle = in_array($entite, ['site', 'grille', 'finances'], true) ? trim(($ligne->code ? $ligne->code.' · ' : '').$ligne->nom)
                                    : ($entite === 'campagne' ? $ligne->nom : $ligne->code.' · '.$ligne->libelle);
                            @endphp
                            <tr>
                                <td><a href="{{ SoumissionGroupee::url($entite, $ligne) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $libelle }}</a></td>
                                <td>@if ($objet?->motif_rejet && $objet instanceof Parametrable)<x-admin.badge-statut :statut="$b['rejete']" />@else<x-admin.badge-statut :statut="$objet?->statut" />@endif</td>
                                @unless ($attente)
                                <td>{{ $objet?->motif_rejet }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.soumission.soumettre') }}">
                                        @csrf
                                        <input type="hidden" name="elements[]" value="{{ $entite }}:{{ $ligne->getKey() }}">
                                        <x-admin.bouton variante="contour" taille="petit" type="submit" aria-label="{{ $b['soumettre'] }} {{ $libelle }}">{{ $b['soumettre'] }}</x-admin.bouton>
                                    </form>
                                </td>
                                @endunless
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @empty
        <div class="bo-carte"><p class="font-semibold text-encre">{{ $v['vide'] }}</p></div>
    @endforelse
</x-admin.layout>
