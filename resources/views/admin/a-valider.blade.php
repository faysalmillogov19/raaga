{{--
    File « À valider » du VALIDATEUR : groupes dépliables par type d'élément, une ligne par élément (détail, valider,
    rejeter), cases à cocher et décision groupée (rejet : motif commun). Un élément soumis par soi-même est grisé,
    sans décision possible ; campagnes, démarches et données financières se décident une par une. Panneau de détail :
    informations, différences avec la version confirmée, pièces jointes, historique, puis élément suivant.
--}}
@php
    use App\Support\AValider;

    $v = __('admin.a_valider');
    $total = collect($groupes)->flatten(1)->where('moi', false)->count();
    $rubriques = AValider::rubriques();
    $slug = $rubrique ? $rubriques[$rubrique]['slug'] : null;
    // Adresse de la page courante (vue d'ensemble ou rubrique), avec un élément ouvert en détail.
    $page = fn (array $parametres = []) => $slug ? route('admin.a-valider.rubrique', ['rubrique' => $slug, ...$parametres]) : route('admin.a-valider', $parametres);
    $titrePage = $rubrique ? $v['menus'][$rubrique] : $v['titre'];
@endphp

<x-admin.layout :titre="$titrePage" :sous-titre="$v['intro']" :fil="$rubrique ? [['libelle' => $v['titre'], 'url' => route('admin.a-valider')], ['libelle' => $titrePage]] : []">
    <div x-data="{
             n: 0,
             ligne: { cle: '', libelle: '', decision: 'confirmer' },
             compter() { this.n = this.$refs.lot.querySelectorAll('input[name=\'elements[]\']:checked').length },
             toutCocher(groupe, coche) { this.$refs.lot.querySelectorAll(`input[data-groupe='${groupe}']`).forEach(c => c.checked = coche); this.compter() },
             decider(cle, libelle, decision) { this.ligne = { cle, libelle, decision }; this.$nextTick(() => this.$refs.dialogueLigne.showModal()) },
         }" x-init="$refs.detail && $refs.detail.showModal()">
        <p class="mb-4 flex items-center gap-2 font-bold text-encre">
            <span>{{ $titrePage }}</span><span class="adm-compteur !ml-0" data-total>{{ $total }}</span>
        </p>

        @if ($rubrique && $groupes[$rubrique] === [])
            {{-- Rubrique traitée : état vide, lien vers la rubrique suivante qui a des éléments. --}}
            <div class="bo-carte !mt-0" data-rubrique-vide>
                <p class="font-semibold text-encre">{{ $v['vide_rubrique'] }}</p>
                @if ($rubriqueSuivante)
                    <a href="{{ route('admin.a-valider.rubrique', $rubriques[$rubriqueSuivante]['slug']) }}" class="mt-2 inline-flex font-semibold text-vert-fonce underline underline-offset-2">
                        {{ __('admin.a_valider.rubrique_suivante', ['rubrique' => $v['menus'][$rubriqueSuivante], 'n' => collect(AValider::pour(auth()->user())[$rubriqueSuivante])->where('moi', false)->count()]) }}</a>
                @endif
            </div>
        @endif

        {{-- Décision groupée : les cases cochées de tous les groupes (jamais pour les éléments sensibles). --}}
        <form method="POST" action="{{ route('admin.a-valider.decider') }}" x-ref="lot" @change="compter()">
            @csrf
            <input type="hidden" name="mode" value="lot">
            @if ($slug)<input type="hidden" name="rubrique" value="{{ $slug }}">@endif
            @foreach ($groupes as $groupe => $elements)
                @continue($rubrique && $elements === [])
                @php($groupable = $rubriques[$groupe]['groupable'])
                {{-- Groupe dépliable : ouvert s'il contient des éléments. --}}
                <section class="bo-carte !mt-0 mb-4" data-groupe="{{ $groupe }}" x-data="{ deplie: @js(count($elements) > 0) }">
                    <h2 class="!text-[1.05rem]">
                        <button type="button" class="adm-groupe-bouton" aria-controls="groupe-{{ $groupe }}" :aria-expanded="deplie ? 'true' : 'false'"
                                aria-expanded="{{ $elements ? 'true' : 'false' }}" @click="deplie = ! deplie">
                            <svg class="adm-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 6 6 6-6 6"/></svg>
                            {{ $v['groupes'][$groupe] }} ({{ count($elements) }})
                        </button>
                    </h2>
                    <div id="groupe-{{ $groupe }}" x-show="deplie" @unless ($elements) style="display: none" @endunless>
                    @if ($elements)
                        @unless ($groupable)
                            <p class="texte-carte mt-1" data-non-groupable>{{ $v['une_par_une'] }}</p>
                        @endunless
                        <div class="mt-3 overflow-x-auto">
                            <table class="bo-tableau">
                                <thead><tr>
                                    @if ($groupable)
                                        <th scope="col" class="w-10"><input type="checkbox" aria-label="{{ __('admin.a_valider.tout_cocher', ['groupe' => $v['groupes'][$groupe]]) }}"
                                                                             @change="toutCocher('{{ $groupe }}', $event.target.checked)"></th>
                                    @endif
                                    @if ($groupe === 'referentiels')<th scope="col">{{ $v['liste'] }}</th>@endif
                                    <th scope="col">{{ $v['colonnes']['element'] }}</th>
                                    <th scope="col">{{ $v['colonnes']['par'] }}</th>
                                    <th scope="col">{{ $v['colonnes']['date'] }}</th>
                                    <th scope="col">{{ $v['colonnes']['attente'] }}</th>
                                    <th scope="col">{{ $v['colonnes']['actions'] }}</th>
                                </tr></thead>
                                <tbody>
                                    @foreach ($elements as $e)
                                        <tr @class(['adm-ligne-grisee' => $e['moi']]) @if ($e['moi']) data-moi @endif>
                                            @if ($groupable)
                                                <td>@unless ($e['moi'])<input type="checkbox" name="elements[]" value="{{ $e['cle'] }}" data-groupe="{{ $groupe }}"
                                                           aria-label="{{ __('admin.a_valider.cocher', ['element' => $e['libelle']]) }}">@endunless</td>
                                            @endif
                                            @if ($groupe === 'referentiels')<td>{{ $v['elements'][$e['element']] }}</td>@endif
                                            <td>@unless ($groupe === 'referentiels')<span class="texte-carte">{{ $v['elements'][$e['element']] }}</span> @endunless<span class="font-semibold text-encre">{{ $e['libelle'] }}</span>
                                                @if ($e['moi'])<br><span class="texte-carte" data-motif-moi>{{ $v['erreurs']['auto_validation'] }}</span>@endif</td>
                                            <td>{{ $e['par'] ?? '' }}</td>
                                            <td class="whitespace-nowrap">{{ $e['date']?->format('d/m H:i') }}</td>
                                            <td class="whitespace-nowrap">{{ $e['date']?->locale('fr')->diffForHumans() }}</td>
                                            <td class="whitespace-nowrap">
                                                <span class="inline-flex gap-1">
                                                    <a href="{{ $page(['detail' => $e['cle']]) }}" class="bo-bouton-icone" title="{{ __('admin.a_valider.voir', ['element' => $e['libelle']]) }}"
                                                       aria-label="{{ __('admin.a_valider.voir', ['element' => $e['libelle']]) }}"><x-icon name="voir" class="h-5 w-5" /></a>
                                                    @unless ($e['moi'])
                                                        <button type="button" class="bo-bouton-icone" title="{{ __('admin.a_valider.valider', ['element' => $e['libelle']]) }}"
                                                                aria-label="{{ __('admin.a_valider.valider', ['element' => $e['libelle']]) }}"
                                                                @click="decider(@js($e['cle']), @js($e['libelle']), 'confirmer')"><x-icon name="check" class="h-5 w-5 text-vert-fonce" /></button>
                                                        <button type="button" class="bo-bouton-icone" title="{{ __('admin.a_valider.rejeter', ['element' => $e['libelle']]) }}"
                                                                aria-label="{{ __('admin.a_valider.rejeter', ['element' => $e['libelle']]) }}"
                                                                @click="decider(@js($e['cle']), @js($e['libelle']), 'rejeter')"><x-icon name="fermer" class="h-5 w-5 text-rouge" /></button>
                                                    @endunless
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="texte-carte mt-2">{{ $v['vide'] }}</p>
                    @endif
                    </div>
                </section>
            @endforeach

            {{-- Barre des actions groupées, fixe en bas de l'écran. --}}
            <div class="adm-barre-selection">
                <span class="font-semibold" aria-live="polite"
                      x-text="n === 0 ? @js(trans_choice('admin.a_valider.selection', 0)) : (n === 1 ? @js(trans_choice('admin.a_valider.selection', 1)) : @js(trans_choice('admin.a_valider.selection', 2, ['n' => '__N__'])).replace('__N__', n))">{{ trans_choice('admin.a_valider.selection', 0) }}</span>
                <x-admin.bouton variante="principal" type="button" x-bind:disabled="n === 0" x-on:click="$refs.dialogueValider.showModal()">{{ $v['valider_selection'] }}</x-admin.bouton>
                <x-admin.bouton variante="contour" type="button" x-bind:disabled="n === 0" x-on:click="$refs.dialogueRejeter.showModal()">{{ $v['rejeter_selection'] }}</x-admin.bouton>
            </div>

            <dialog x-ref="dialogueValider" class="bo-dialogue" aria-labelledby="valider-titre">
                <h2 id="valider-titre" class="!text-[1.2rem]">{{ $v['valider_selection'] }}</h2>
                <p class="mt-2">{{ $v['confirmer_message'] }}</p>
                <div class="mt-5 flex justify-end gap-3">
                    <x-admin.bouton variante="contour" type="button" x-on:click="$refs.dialogueValider.close()">{{ $v['annuler'] }}</x-admin.bouton>
                    <x-admin.bouton variante="principal" type="submit" name="decision" value="confirmer">{{ $v['valider_selection'] }}</x-admin.bouton>
                </div>
            </dialog>

            <dialog x-ref="dialogueRejeter" class="bo-dialogue" aria-labelledby="rejeter-titre">
                <h2 id="rejeter-titre" class="!text-[1.2rem]">{{ $v['rejeter_selection'] }}</h2>
                <label for="motif-lot" class="mt-2 block">{{ $v['rejeter_message'] }}</label>
                <textarea id="motif-lot" name="motif" rows="3" minlength="5" maxlength="1000" class="champ mt-2"></textarea>
                <div class="mt-5 flex justify-end gap-3">
                    <x-admin.bouton variante="contour" type="button" x-on:click="$refs.dialogueRejeter.close()">{{ $v['annuler'] }}</x-admin.bouton>
                    <x-admin.bouton variante="danger" type="submit" name="decision" value="rejeter">{{ $v['rejeter_selection'] }}</x-admin.bouton>
                </div>
            </dialog>
        </form>
        @error('elements') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
        @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror

        {{-- Décision sur une seule ligne (✓ ou ✗). --}}
        <dialog x-ref="dialogueLigne" class="bo-dialogue" aria-labelledby="ligne-titre">
            <form method="POST" action="{{ route('admin.a-valider.decider') }}">
                @csrf
                <input type="hidden" name="mode" value="ligne">
                @if ($slug)<input type="hidden" name="rubrique" value="{{ $slug }}">@endif
                <input type="hidden" name="elements[]" :value="ligne.cle">
                <input type="hidden" name="decision" :value="ligne.decision">
                <h2 id="ligne-titre" class="!text-[1.2rem]" x-text="ligne.libelle"></h2>
                <p class="mt-2" x-show="ligne.decision === 'confirmer'">{{ $v['confirmer_ligne'] }}</p>
                <template x-if="ligne.decision === 'rejeter'">
                    <div>
                        <label for="motif-ligne" class="mt-2 block">{{ $v['rejeter_ligne'] }}</label>
                        <textarea id="motif-ligne" name="motif" rows="3" minlength="5" maxlength="1000" required class="champ mt-2"></textarea>
                    </div>
                </template>
                <div class="mt-5 flex justify-end gap-3">
                    <x-admin.bouton variante="contour" type="button" x-on:click="$refs.dialogueLigne.close()">{{ $v['annuler'] }}</x-admin.bouton>
                    <button type="submit" class="adm-btn adm-btn-normal" :class="ligne.decision === 'rejeter' ? 'adm-btn-danger' : 'adm-btn-principal'"
                            x-text="ligne.decision === 'rejeter' ? @js(__('admin.parametrage.actions.rejeter')) : @js(__('admin.parametrage.actions.confirmer'))"></button>
                </div>
            </form>
        </dialog>

        @if ($detail)
            @include('admin.a-valider._detail', ['d' => $detail, 'suivant' => $suivant, 'slug' => $slug, 'retour' => $page()])
        @endif
    </div>
</x-admin.layout>
