@php
    use App\Support\Plateforme\Affichage;
    use App\Support\Plateforme\Catalogue;

    $p = fn (string $cle, array $r = []) => __('admin.plateforme.'.$cle, $r);
    $titreSection = $p('sections.'.$section);
    $champs = Catalogue::champs()[$section];
    $sensibles = array_values(array_intersect(Catalogue::SENSIBLES, array_keys($champs)));
@endphp

<x-admin.layout :titre="__('admin.menu.plateforme')" :fil="[['libelle' => __('admin.menu.plateforme'), 'url' => route('admin.plateforme')], ['libelle' => $titreSection]]">
    <p class="texte-carte mb-4 max-w-4xl">{{ $p('intro') }}</p>

    <nav class="bo-onglets flex-wrap" aria-label="{{ __('admin.menu.plateforme') }}">
        @foreach (array_keys(Catalogue::SECTIONS) as $i => $cle)
            @php($ouvert = auth()->user()->can(Catalogue::droitModification($cle)->value))
            <a href="{{ route('admin.plateforme', ['section' => $cle]) }}" @class(['bo-onglet inline-flex items-center gap-1.5', 'bo-onglet-actif' => $cle === $section])
               @if ($cle === $section) aria-current="page" @endif>
                {{ $i + 1 }}. {{ $p('sections.'.$cle) }}
                @unless ($ouvert)
                    <x-admin.icone nom="cadenas" class="h-3.5 w-3.5" /><span class="sr-only">({{ $p('lecture_seule') }})</span>
                @endunless
            </a>
        @endforeach
    </nav>

    <section class="bo-carte !mt-0" aria-labelledby="section-titre">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 id="section-titre" class="!text-[1.2rem]">{{ $titreSection }}</h2>
                <p class="texte-carte mt-1">
                    {{ $derniere ? $p('derniere_modification', ['nom' => $derniere['nom'] ?? '—', 'date' => \Carbon\Carbon::parse($derniere['date'])->format('d/m/Y à H:i')]) : $p('jamais_modifiee') }}
                </p>
            </div>
            @unless ($modifiable)
                <span class="bo-etiquette inline-flex items-center gap-1.5"><x-admin.icone nom="cadenas" class="h-4 w-4" /> {{ $p('lecture_seule') }}</span>
            @endunless
        </div>

        <nav class="mt-4 flex gap-5 border-b border-ligne" aria-label="{{ $titreSection }}">
            @foreach (['parametres', 'historique'] as $o)
                <a href="{{ route('admin.plateforme', ['section' => $section, 'onglet' => $o]) }}"
                   @class(['-mb-px border-b-2 pb-2 font-semibold no-underline', 'border-vert text-vert-fonce' => $onglet === $o, 'border-transparent text-gris' => $onglet !== $o])
                   @if ($onglet === $o) aria-current="page" @endif>{{ $p('onglets.'.$o) }}</a>
            @endforeach
        </nav>

        @if ($onglet === 'parametres')
            @if ($section === 'projet')
                <x-admin.message type="ok" class="mt-4 !mb-0">{{ $p('aides.pas_de_tranche') }}</x-admin.message>
            @endif

            @if ($modifiable)
                <form method="POST" action="{{ route('admin.plateforme.update', $section) }}" @if (in_array($section, ['identite', 'site_public'], true)) enctype="multipart/form-data" @endif
                      class="mt-5" novalidate x-ref="formulaire"
                      x-data="{
                          sensibles: @js($sensibles),
                          initiales: {},
                          cout: @js(old('coutTotalProjet', $valeurs['coutTotalProjet'] ?? null) === null ? null : (int) old('coutTotalProjet', $valeurs['coutTotalProjet'] ?? null)),
                          totalLignes: 0,
                          confirme: false,
                          fcfa(n) { return new Intl.NumberFormat('fr-FR').format(n || 0).replace(/\s/g, ' ') + ' FCFA' },
                          valeur(nom) { return Array.from(this.$refs.formulaire.querySelectorAll(`[name='${nom}'],[name^='${nom}[']`)).map(c => c.type === 'checkbox' ? c.checked : c.value).join('|') },
                          init() { this.sensibles.forEach(n => this.initiales[n] = this.valeur(n)) },
                          soumettre(e) {
                              if (e.submitter && e.submitter.hasAttribute('formaction')) return;
                              if (! this.confirme && this.sensibles.some(n => this.valeur(n) !== this.initiales[n])) {
                                  e.preventDefault();
                                  this.$refs.dialogueSensible.showModal();
                              }
                          },
                      }"
                      @submit="soumettre($event)">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="confirmation_sensible" :value="confirme ? 1 : 0" value="{{ old('confirmation_sensible', 0) }}">

                    @error('confirmation_sensible')
                        <x-admin.message type="erreur">
                            <p>{{ $message }}</p>
                            <label class="mt-2 inline-flex items-center gap-2 font-semibold">
                                <input type="checkbox" x-model="confirme"> {{ $p('sensible.confirmer') }}
                            </label>
                        </x-admin.message>
                    @enderror

                    @if ($errors->any() && ! $errors->has('confirmation_sensible'))
                        <x-admin.message type="erreur">
                            <p class="font-bold">{{ $p('erreurs.non_enregistre') }}</p>
                            @if ($errors->has('armoiries'))<p class="mt-1">{{ $p('erreurs.armoiries_refusees') }}</p>@endif
                        </x-admin.message>
                    @endif

                    <div class="grid gap-x-6 gap-y-5 lg:grid-cols-2">
                        @foreach ($champs as $champ => $definition)
                            @include('admin.plateforme._champ', ['champ' => $champ, 'type' => $definition[0], 'max' => $definition[1] ?? 255, 'valeur' => $valeurs[$champ]])
                        @endforeach
                    </div>

                    <div class="mt-6 flex flex-wrap gap-3">
                        <x-admin.bouton variante="principal" type="submit">{{ $p('enregistrer') }}</x-admin.bouton>
                        <x-admin.bouton variante="contour" type="submit"  formaction="{{ route('admin.plateforme.apercu', $section) }}" formtarget="_blank">{{ $p('apercu') }}</x-admin.bouton>
                    </div>

                    <dialog x-ref="dialogueSensible" class="bo-dialogue" aria-labelledby="sensible-titre">
                        <h2 id="sensible-titre" class="!text-[1.2rem]">{{ $p('sensible.titre') }}</h2>
                        <p class="mt-2">{{ $p('sensible.message') }}</p>
                        <div class="mt-5 flex justify-end gap-3">
                            <x-admin.bouton variante="contour" type="button"  x-on:click="$refs.dialogueSensible.close()">{{ __('admin.confirmation.annuler') }}</x-admin.bouton>
                            <x-admin.bouton x-on:click="confirme = true; $refs.dialogueSensible.close(); $nextTick(() => $refs.formulaire.requestSubmit())">{{ $p('sensible.confirmer') }}</x-admin.bouton>
                        </div>
                    </dialog>
                </form>
            @else
                <dl class="bo-definitions mt-5">
                    @foreach ($champs as $champ => $definition)
                        <dt>{{ $p('champs.'.$champ) }}</dt>
                        <dd>{{ Affichage::valeur($section, $champ, $valeurs[$champ]) }}</dd>
                    @endforeach
                </dl>
            @endif
        @else
            @forelse ($versions as $version)
                <article class="mt-5 rounded-charte border border-ligne p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <p class="font-bold text-encre">
                            {{ $p('historique.version', ['n' => $version->id, 'date' => $version->date->format('d/m/Y à H:i'), 'nom' => $version->auteur ?? '—']) }}
                            @if ($version->restauree_depuis)<span class="texte-carte font-normal">{{ $p('historique.restauration', ['n' => $version->restauree_depuis]) }}</span>@endif
                        </p>
                        @if ($peutRestaurer)
                            <x-admin.confirmation :id="'restaurer-'.$version->id" :titre="$p('historique.restaurer')" :message="$p('historique.restaurer_message')"
                                                  :action="route('admin.plateforme.restaurer', $version->id)" :bouton="$p('historique.restaurer')">
                                <x-slot:declencheur>{{ $p('historique.restaurer') }}</x-slot:declencheur>
                            </x-admin.confirmation>
                        @endif
                    </div>
                    <div class="mt-3 overflow-x-auto">
                        <table class="bo-tableau">
                            <thead><tr><th scope="col">{{ $p('historique.champ') }}</th><th scope="col">{{ $p('historique.avant') }}</th><th scope="col">{{ $p('historique.apres') }}</th></tr></thead>
                            <tbody>
                                @foreach ($version->differentiel as $champ => [$avant, $apres])
                                    <tr>
                                        <td class="font-semibold">{{ $p('champs.'.$champ) }}</td>
                                        <td class="texte-carte">{{ Affichage::valeur($section, $champ, $avant) }}</td>
                                        <td>{{ Affichage::valeur($section, $champ, $apres) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </article>
            @empty
                <p class="texte-carte mt-5">{{ $p('historique.aucune') }}</p>
            @endforelse
        @endif
    </section>
</x-admin.layout>
