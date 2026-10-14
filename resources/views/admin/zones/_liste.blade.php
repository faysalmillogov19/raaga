{{--
    Une liste du référentiel (niveaux ou zones). Variables : $liste (« niveaux » | « zones »), $elements, $gerer, $valider.
    Niveaux : libellé et rang ; zones : libellé, description et ordre. Code jamais modifiable.
--}}
@php
    use App\Enums\StatutTypeBoutique;

    $z = __('admin.zones');
    $niveaux = $liste === 'niveaux';
@endphp
<section class="bo-carte !mt-0" aria-labelledby="titre-{{ $liste }}">
    <h2 id="titre-{{ $liste }}" class="!text-[1.15rem]">{{ $z[$liste.'_titre'] }}</h2>
    <p class="texte-carte mt-1">{{ $z[$liste.'_aide'] }}</p>

    <ul class="mt-3 divide-y divide-ligne">
        @foreach ($elements as $el)
            <li @class(['py-3', 'opacity-70' => ! $el->actif])>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p><span class="font-mono text-lg font-bold">{{ $el->code }}</span>
                        <span class="font-semibold text-encre">{{ $el->libelle }}</span>
                        <span class="texte-carte">· {{ $niveaux ? __('admin.zones.rang_valeur', ['n' => $el->rang]) : __('admin.zones.ordre_valeur', ['n' => $el->ordre]) }}</span></p>
                    <p class="flex flex-wrap items-center gap-1">
                        @if ($el->par_defaut)<span class="bo-etiquette bo-etiquette-neutre">{{ $z['par_defaut'] }}</span>@endif
                        <span @class(['bo-etiquette', 'bo-etiquette-succes' => $el->statut === StatutTypeBoutique::Valide, 'bo-etiquette-attention' => $el->statut === StatutTypeBoutique::AValider, 'bo-etiquette-echec' => $el->statut === StatutTypeBoutique::Rejete])>{{ $el->statut->label() }}</span>
                        @unless ($el->actif)<span class="bo-etiquette bo-etiquette-neutre">{{ $z['desactive'] }}</span>@endunless
                        <span class="texte-carte">{{ $z['utilise_par'] }} {{ trans_choice('admin.zones.boutiques', $el->boutiques_count, ['n' => format_nombre($el->boutiques_count)]) }}</span>
                    </p>
                </div>
                @if (! $niveaux && $el->description)<p class="texte-carte">{{ $el->description }}</p>@endif
                @if ($el->motif_rejet)<p class="texte-carte">{{ __('admin.parametrage.motif_rejet', ['motif' => $el->motif_rejet]) }}</p>@endif

                @if ($gerer)
                    <form method="POST" action="{{ route('admin.referencement.zones.update', [$liste, $el->id]) }}" class="mt-2 flex flex-wrap items-end gap-2" novalidate>
                        @csrf @method('PUT')
                        <div><label for="{{ $liste }}-libelle-{{ $el->id }}" class="sr-only">{{ $z['colonnes']['libelle'] }} {{ $el->code }}</label>
                            <input id="{{ $liste }}-libelle-{{ $el->id }}" name="libelle" value="{{ $el->libelle }}" maxlength="100" required class="champ !w-52"></div>
                        @if ($niveaux)
                            <div><label for="niveaux-rang-{{ $el->id }}" class="sr-only">{{ $z['colonnes']['rang'] }} {{ $el->code }}</label>
                                <input id="niveaux-rang-{{ $el->id }}" name="rang" type="number" min="-9" max="99" value="{{ $el->rang }}" required class="champ !w-20"></div>
                        @else
                            <div><label for="zones-description-{{ $el->id }}" class="sr-only">{{ $z['colonnes']['description'] }} {{ $el->code }}</label>
                                <input id="zones-description-{{ $el->id }}" name="description" value="{{ $el->description }}" maxlength="300" class="champ !w-52" placeholder="{{ $z['colonnes']['description'] }}"></div>
                            <div><label for="zones-ordre-{{ $el->id }}" class="sr-only">{{ $z['colonnes']['ordre'] }} {{ $el->code }}</label>
                                <input id="zones-ordre-{{ $el->id }}" name="ordre" type="number" min="0" max="9999" value="{{ $el->ordre }}" class="champ !w-20"></div>
                        @endif
                        <button type="submit" class="bo-bouton bo-bouton-secondaire">{{ $z['enregistrer'] }}</button>
                    </form>
                @endif

                <div class="mt-2 flex flex-wrap gap-2">
                    @if ($valider && $el->statut === StatutTypeBoutique::AValider)
                        <x-admin.confirmation id="confirmer-{{ $liste }}-{{ $el->id }}" :titre="$z['confirmer']" :message="$z['confirmer_message']"
                                              :action="route('admin.referencement.zones.confirmer', [$liste, $el->id])" :bouton="$z['confirmer']">
                            <x-slot:declencheur>{{ $z['confirmer'] }}</x-slot:declencheur>
                        </x-admin.confirmation>
                        <x-admin.confirmation id="rejeter-{{ $liste }}-{{ $el->id }}" :titre="$z['rejeter']" :message="$z['rejeter_message']"
                                              :action="route('admin.referencement.zones.rejeter', [$liste, $el->id])" :bouton="$z['rejeter']" motif danger>
                            <x-slot:declencheur>{{ $z['rejeter'] }}</x-slot:declencheur>
                        </x-admin.confirmation>
                    @endif
                    @if ($gerer)
                        <form method="POST" action="{{ route('admin.referencement.zones.'.($el->actif ? 'desactiver' : 'activer'), [$liste, $el->id]) }}">@csrf
                            <button type="submit" class="bo-bouton bo-bouton-secondaire">{{ $el->actif ? $z['desactiver'] : $z['activer'] }}</button></form>
                        @if (! $el->par_defaut && $el->nombreUtilisations() === 0)
                            <form method="POST" action="{{ route('admin.referencement.zones.destroy', [$liste, $el->id]) }}">@csrf @method('DELETE')
                                <button type="submit" class="bo-bouton bo-bouton-danger">{{ $z['supprimer'] }}</button></form>
                        @endif
                    @endif
                </div>

                @php
                    $versions = $el->versions();
                @endphp
                @if ($versions->isNotEmpty())
                    <details class="mt-2">
                        <summary class="cursor-pointer text-sm font-semibold text-vert-fonce">{{ __('admin.types.historique') }} ({{ $versions->count() }})</summary>
                        <ul class="mt-1 space-y-1 text-sm">
                            @foreach ($versions as $v)
                                <li>{{ $v->numero }} · {{ $v->created_at->format('d/m/Y H:i') }} · {{ $v->auteur?->name ?? __('admin.types.systeme') }} — {{ $v->motif }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </li>
        @endforeach
    </ul>

    @if ($gerer)
        <form method="POST" action="{{ route('admin.referencement.zones.store', $liste) }}" class="mt-4 grid gap-3 sm:grid-cols-2" novalidate>
            @csrf
            <h3 class="!text-base sm:col-span-2">{{ $z[$liste.'_ajouter'] }}</h3>
            <div class="formulaire-champ !mt-0"><label for="{{ $liste }}-code">{{ $z['colonnes']['code'] }}</label>
                <input id="{{ $liste }}-code" name="code" maxlength="{{ $niveaux ? 3 : 5 }}" required autocomplete="off" class="champ uppercase" aria-describedby="{{ $liste }}-code-aide">
                <p id="{{ $liste }}-code-aide" class="aide-champ">{{ $z[$liste.'_code_aide'] }}</p></div>
            <div class="formulaire-champ !mt-0"><label for="{{ $liste }}-libelle">{{ $z['colonnes']['libelle'] }}</label>
                <input id="{{ $liste }}-libelle" name="libelle" maxlength="100" required class="champ"></div>
            @if ($niveaux)
                <div class="formulaire-champ !mt-0"><label for="niveaux-rang">{{ $z['colonnes']['rang'] }}</label>
                    <input id="niveaux-rang" name="rang" type="number" min="-9" max="99" required class="champ" aria-describedby="niveaux-rang-aide">
                    <p id="niveaux-rang-aide" class="aide-champ">{{ $z['rang_aide'] }}</p></div>
            @else
                <div class="formulaire-champ !mt-0"><label for="zones-description">{{ $z['colonnes']['description'] }}</label>
                    <input id="zones-description" name="description" maxlength="300" class="champ"></div>
                <div class="formulaire-champ !mt-0"><label for="zones-ordre">{{ $z['colonnes']['ordre'] }}</label>
                    <input id="zones-ordre" name="ordre" type="number" min="0" max="9999" class="champ"></div>
            @endif
            <div class="flex items-end"><button type="submit" class="bo-bouton bo-bouton-principal">{{ $z['ajouter'] }}</button></div>
        </form>
    @endif
</section>
