{{--
    Soumission groupée d'une liste : barre collante « n éléments sélectionnés » (Soumettre à validation, Retirer de la
    validation), fenêtre de contrôle préalable (✓ prêt ou ✗ raison, commentaire facultatif pour le VALIDATEUR, envoi des
    seuls éléments prêts) et fenêtre de retrait. Chaque élément passe par l'action « Soumettre » de sa fiche.
--}}
@php($s = __('admin.soumission'))

<div class="adm-barre-selection" data-barre-soumission x-show="total > 0" x-cloak>
    <span class="font-semibold" aria-live="polite"
          x-text="total === 1 ? @js(trans_choice('admin.soumission.selection', 1)) : @js(trans_choice('admin.soumission.selection', 2, ['n' => '__N__'])).replace('__N__', total)"></span>
    <x-admin.bouton variante="principal" type="button" x-show="aSoumettre > 0" x-on:click="ouvrirSoumission()">{{ $s['soumettre'] }}</x-admin.bouton>
    <x-admin.bouton variante="contour" type="button" x-show="aRetirer > 0" x-on:click="$refs.dialogueRetirer.showModal()">{{ $s['retirer'] }}</x-admin.bouton>
</div>

<dialog x-ref="dialogueSoumettre" class="bo-dialogue" aria-labelledby="soumission-titre">
    <form method="POST" action="{{ route('admin.soumission.soumettre') }}">
        @csrf
        <h2 id="soumission-titre" class="!text-[1.2rem]">{{ $s['soumettre'] }}</h2>
        <p class="mt-2">{{ $s['controle'] }}</p>
        <p class="texte-carte mt-2" role="status" x-show="enCours">{{ $s['chargement'] }}</p>
        <p class="message-erreur" role="alert" x-show="erreur" x-cloak>{{ $s['erreurs']['controle'] }}</p>
        <ul class="mt-2 max-h-72 space-y-1 overflow-y-auto" data-controles>
            <template x-for="c in controles" :key="c.cle">
                <li>
                    <span x-show="c.pret" class="font-bold text-vert-fonce" aria-hidden="true">✓</span>
                    <span x-show="! c.pret" class="font-bold text-rouge" aria-hidden="true">✗</span>
                    <span class="font-semibold" x-text="c.libelle"></span>
                    <span x-text="c.pret ? @js($s['pret']) : c.raison" :class="c.pret ? 'texte-carte' : 'text-rouge'"></span>
                    <template x-if="c.pret"><input type="hidden" name="elements[]" :value="c.cle"></template>
                </li>
            </template>
        </ul>
        <label for="soumission-commentaire" class="mt-4 block font-semibold">{{ $s['commentaire'] }}</label>
        <textarea id="soumission-commentaire" name="commentaire" rows="3" maxlength="1000" class="champ mt-1"></textarea>
        <div class="mt-5 flex flex-wrap justify-end gap-3">
            <x-admin.bouton variante="contour" type="button" x-on:click="$refs.dialogueSoumettre.close()">{{ $s['annuler'] }}</x-admin.bouton>
            <x-admin.bouton variante="principal" type="submit" x-bind:disabled="prets.length === 0"
                            x-text="@js($s['envoyer']).replace(':n', prets.length)">{{ __('admin.soumission.envoyer', ['n' => 0]) }}</x-admin.bouton>
        </div>
    </form>
</dialog>

<dialog x-ref="dialogueRetirer" class="bo-dialogue" aria-labelledby="retrait-titre">
    <form method="POST" action="{{ route('admin.soumission.retirer') }}">
        @csrf
        <h2 id="retrait-titre" class="!text-[1.2rem]">{{ $s['retirer'] }}</h2>
        <p class="mt-2">{{ $s['retrait_message'] }}</p>
        <template x-for="cle in (aRetirer, selectionnes('retirer'))" :key="cle"><input type="hidden" name="elements[]" :value="cle"></template>
        <div class="mt-5 flex flex-wrap justify-end gap-3">
            <x-admin.bouton variante="contour" type="button" x-on:click="$refs.dialogueRetirer.close()">{{ $s['annuler'] }}</x-admin.bouton>
            <x-admin.bouton variante="principal" type="submit">{{ $s['retirer'] }}</x-admin.bouton>
        </div>
    </form>
</dialog>
