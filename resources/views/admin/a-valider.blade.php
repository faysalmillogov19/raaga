{{--
    File « À valider » du VALIDATEUR : groupes dépliables par type d'élément, une ligne par élément (voir, valider,
    rejeter), cases à cocher et décision groupée (rejet : motif commun). Chaque décision passe par l'action existante.
--}}
@php
    $v = __('admin.a_valider');
    $total = array_sum(array_map('count', $groupes));
@endphp

<x-admin.layout :titre="$v['titre']" :sous-titre="$v['intro']">
    <div x-data="{
             n: 0,
             ligne: { cle: '', libelle: '', decision: 'confirmer' },
             compter() { this.n = this.$refs.lot.querySelectorAll('input[name=\'elements[]\']:checked').length },
             toutCocher(groupe, coche) { this.$refs.lot.querySelectorAll(`input[data-groupe='${groupe}']`).forEach(c => c.checked = coche); this.compter() },
             decider(cle, libelle, decision) { this.ligne = { cle, libelle, decision }; this.$nextTick(() => this.$refs.dialogueLigne.showModal()) },
         }">
        <p class="mb-4 flex items-center gap-2 font-bold text-encre">
            <span>{{ $v['titre'] }}</span><span class="adm-compteur !ml-0" data-total>{{ $total }}</span>
        </p>

        {{-- Décision groupée : les cases cochées de tous les groupes. --}}
        <form method="POST" action="{{ route('admin.a-valider.decider') }}" x-ref="lot" @change="compter()">
            @csrf
            @foreach ($groupes as $groupe => $elements)
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
                        <div class="mt-3 overflow-x-auto">
                            <table class="bo-tableau">
                                <thead><tr>
                                    <th scope="col" class="w-10"><input type="checkbox" aria-label="{{ __('admin.a_valider.tout_cocher', ['groupe' => $v['groupes'][$groupe]]) }}"
                                                                         @change="toutCocher('{{ $groupe }}', $event.target.checked)"></th>
                                    <th scope="col">{{ $v['colonnes']['element'] }}</th>
                                    <th scope="col">{{ $v['colonnes']['par'] }}</th>
                                    <th scope="col">{{ $v['colonnes']['date'] }}</th>
                                    <th scope="col">{{ $v['colonnes']['attente'] }}</th>
                                    <th scope="col">{{ $v['colonnes']['actions'] }}</th>
                                </tr></thead>
                                <tbody>
                                    @foreach ($elements as $e)
                                        <tr>
                                            <td><input type="checkbox" name="elements[]" value="{{ $e['cle'] }}" data-groupe="{{ $groupe }}"
                                                       aria-label="{{ __('admin.a_valider.cocher', ['element' => $e['libelle']]) }}"></td>
                                            <td><span class="texte-carte">{{ $v['elements'][$e['element']] }}</span> <span class="font-semibold text-encre">{{ $e['libelle'] }}</span></td>
                                            <td>{{ $e['par'] ?? '' }}</td>
                                            <td class="whitespace-nowrap">{{ $e['date']?->format('d/m H:i') }}</td>
                                            <td class="whitespace-nowrap">{{ $e['date']?->locale('fr')->diffForHumans() }}</td>
                                            <td class="whitespace-nowrap">
                                                <span class="inline-flex gap-1">
                                                    <a href="{{ $e['url'] }}" class="bo-bouton-icone" title="{{ __('admin.a_valider.voir', ['element' => $e['libelle']]) }}"
                                                       aria-label="{{ __('admin.a_valider.voir', ['element' => $e['libelle']]) }}"><x-icon name="voir" class="h-5 w-5" /></a>
                                                    <button type="button" class="bo-bouton-icone" title="{{ __('admin.a_valider.valider', ['element' => $e['libelle']]) }}"
                                                            aria-label="{{ __('admin.a_valider.valider', ['element' => $e['libelle']]) }}"
                                                            @click="decider(@js($e['cle']), @js($e['libelle']), 'confirmer')"><x-icon name="check" class="h-5 w-5 text-vert-fonce" /></button>
                                                    <button type="button" class="bo-bouton-icone" title="{{ __('admin.a_valider.rejeter', ['element' => $e['libelle']]) }}"
                                                            aria-label="{{ __('admin.a_valider.rejeter', ['element' => $e['libelle']]) }}"
                                                            @click="decider(@js($e['cle']), @js($e['libelle']), 'rejeter')"><x-icon name="fermer" class="h-5 w-5 text-rouge" /></button>
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
                      x-text="n === 0 ? @js(trans_choice('admin.a_valider.selection', 0)) : (n === 1 ? @js(trans_choice('admin.a_valider.selection', 1)) : @js(trans_choice('admin.a_valider.selection', 2, ['n' => '__N__'])).replace('__N__', n))"">{{ trans_choice('admin.a_valider.selection', 0) }}</span>
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
    </div>
</x-admin.layout>
