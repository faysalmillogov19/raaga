{{--
    Champ éditable d'un paramètre de la plateforme, rendu selon son type (App\Support\Plateforme\Catalogue).
    Variables : $section, $champ, $type, $max, $valeur.
--}}
@php
    use App\Support\Plateforme\Catalogue;
    use App\Support\Telephone;

    $p = fn (string $cle) => __('admin.plateforme.'.$cle);
    $libelle = $p('champs.'.$champ);
    $aide = \Illuminate\Support\Facades\Lang::has('admin.plateforme.aides.'.$champ) ? $p('aides.'.$champ) : null;
    $erreurs = collect($errors->getMessages())->filter(fn ($m, $cle) => $cle === $champ || str_starts_with($cle, $champ.'.'))->flatten()->unique();
    $invalide = $erreurs->isNotEmpty();
    $id = 'c-'.$champ;
    $o = fn (string $sous, $defaut = null) => old("{$champ}.{$sous}", $defaut);
@endphp

<div @class(['formulaire-champ !mt-0', 'lg:col-span-2' => in_array($type, ['texte_long', 'horaires', 'contact', 'hebergeur', 'reseaux', 'liens', 'financement', 'bandeau', 'maintenance'], true)])>
    @switch($type)
        @case('texte')
        @case('url')
        @case('email')
        @case('expediteur')
        @case('tel_intl')
        @case('tel_bf')
        @case('entier')
            @php
                $affiche = match ($type) {
                    'tel_bf' => $valeur ? Telephone::formaterLocal($valeur) : null,
                    default => $valeur,
                };
            @endphp
            <label for="{{ $id }}">{{ $libelle }}</label>
            <div class="flex items-center gap-2">
                @if ($type === 'tel_bf')<span class="font-semibold text-encre">+226</span>@endif
                <input id="{{ $id }}" name="{{ $champ }}" value="{{ old($champ, $affiche) }}"
                       type="{{ ['url' => 'url', 'email' => 'email', 'entier' => 'number', 'tel_bf' => 'tel', 'tel_intl' => 'tel'][$type] ?? 'text' }}"
                       @if ($type === 'texte') maxlength="{{ $max }}" @endif
                       @if ($type === 'url') pattern="https://.*" placeholder="https://" @endif
                       @if ($type === 'expediteur') maxlength="11" pattern="[A-Za-z0-9]{1,11}" @endif
                       @if ($type === 'tel_bf') inputmode="numeric" pattern="[0-9 ]{8,11}" placeholder="XX XX XX XX" @endif
                       @if ($type === 'entier') min="0" step="1" inputmode="numeric" @endif
                       @if ($champ === 'coutTotalProjet') x-model.number="cout" @endif
                       @class(['champ', 'est-invalide' => $invalide]) @if ($aide) aria-describedby="{{ $id }}-aide" @endif>
            </div>
            @break

        @case('texte_long')
            <label for="{{ $id }}">{{ $libelle }}</label>
            <textarea id="{{ $id }}" name="{{ $champ }}" rows="3" maxlength="{{ $max }}" @class(['champ', 'est-invalide' => $invalide])>{{ old($champ, $valeur) }}</textarea>
            @break

        @case('image')
            <label for="{{ $id }}">{{ $libelle }}</label>
            <div class="flex items-center gap-4">
                <img src="{{ \App\Support\Armoiries::url() }}" alt="" class="h-14 w-auto rounded border border-ligne bg-white p-1">
                <input id="{{ $id }}" name="{{ $champ }}" type="file" accept=".svg,.png,image/svg+xml,image/png" @class(['champ', 'est-invalide' => $invalide])>
            </div>
            @break

        @case('horaires')
            <fieldset>
                <legend class="font-bold text-encre">{{ $libelle }}</legend>
                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-2">
                    @foreach (Catalogue::JOURS as $jour)
                        <label class="inline-flex items-center gap-1.5 font-normal">
                            <input type="checkbox" name="{{ $champ }}[jours][]" value="{{ $jour }}" @checked(in_array($jour, $o('jours', $valeur['jours'] ?? []), true))>
                            {{ $p('jours.'.$jour) }}
                        </label>
                    @endforeach
                </div>
                <div class="mt-3 flex flex-wrap gap-4">
                    <label class="font-normal">{{ $p('sous_champs.debut') }}
                        <input type="time" name="{{ $champ }}[debut]" value="{{ $o('debut', $valeur['debut'] ?? null) }}" class="champ mt-1 w-36"></label>
                    <label class="font-normal">{{ $p('sous_champs.fin') }}
                        <input type="time" name="{{ $champ }}[fin]" value="{{ $o('fin', $valeur['fin'] ?? null) }}" class="champ mt-1 w-36"></label>
                </div>
            </fieldset>
            @break

        @case('gps')
            <fieldset>
                <legend class="font-bold text-encre">{{ $libelle }}</legend>
                <div class="mt-2 flex flex-wrap gap-4">
                    @foreach (['latitude', 'longitude'] as $axe)
                        <label class="font-normal">{{ $p('sous_champs.'.$axe) }}
                            <input type="number" step="any" name="{{ $champ }}[{{ $axe }}]" value="{{ $o($axe, $valeur[$axe] ?? null) }}" class="champ mt-1 w-40"></label>
                    @endforeach
                </div>
            </fieldset>
            @break

        @case('contact')
            <fieldset class="rounded-charte border border-ligne p-4">
                <legend class="px-1 font-bold text-encre">{{ $libelle }}</legend>
                <div class="grid gap-3 lg:grid-cols-2">
                    @foreach (['libelle' => 'text', 'nom' => 'text', 'telephone' => 'tel', 'email' => 'email'] as $sous => $typeHtml)
                        <label class="font-normal">{{ $p('sous_champs.'.$sous) }}
                            <input type="{{ $typeHtml }}" name="{{ $champ }}[{{ $sous }}]" value="{{ $o($sous, $valeur[$sous] ?? null) }}" class="champ mt-1"></label>
                    @endforeach
                </div>
                <input type="hidden" name="{{ $champ }}[afficher]" value="0">
                <label class="mt-3 inline-flex items-center gap-2 font-normal">
                    <input type="checkbox" name="{{ $champ }}[afficher]" value="1" @checked($o('afficher', $valeur['afficher'] ?? false))>
                    {{ $p('sous_champs.afficher') }}
                </label>
            </fieldset>
            @break

        @case('personne')
        @case('hebergeur')
            <fieldset>
                <legend class="font-bold text-encre">{{ $libelle }}</legend>
                <div class="mt-2 grid gap-3 lg:grid-cols-2">
                    @foreach ($type === 'personne' ? ['nom' => 'nom_personne', 'fonction' => 'fonction'] : ['nom' => 'nom_personne', 'adresse' => 'adresse', 'contact' => 'contact'] as $sous => $cle)
                        <label class="font-normal">{{ $p('sous_champs.'.$cle) }}
                            <input type="text" name="{{ $champ }}[{{ $sous }}]" value="{{ $o($sous, $valeur[$sous] ?? null) }}" class="champ mt-1"></label>
                    @endforeach
                </div>
            </fieldset>
            @break

        @case('mois_annee')
            <fieldset>
                <legend class="font-bold text-encre">{{ $libelle }}</legend>
                <div class="mt-2 flex flex-wrap gap-4">
                    <label class="font-normal">{{ $p('sous_champs.mois') }}
                        <select name="{{ $champ }}[mois]" class="champ mt-1 w-44">
                            <option value="">—</option>
                            @foreach (range(1, 12) as $m)
                                <option value="{{ $m }}" @selected((int) $o('mois', $valeur['mois'] ?? null) === $m)>{{ \Carbon\Carbon::create(2000, $m, 1)->translatedFormat('F') }}</option>
                            @endforeach
                        </select></label>
                    <label class="font-normal">{{ $p('sous_champs.annee') }}
                        <input type="number" min="2020" max="2100" name="{{ $champ }}[annee]" value="{{ $o('annee', $valeur['annee'] ?? null) }}" class="champ mt-1 w-32"></label>
                </div>
            </fieldset>
            @break

        @case('bandeau')
            <fieldset class="rounded-charte border border-ligne p-4">
                <legend class="px-1 font-bold text-encre">{{ $libelle }}</legend>
                <label class="font-normal">{{ $p('sous_champs.texte') }}
                    <input type="text" maxlength="200" name="{{ $champ }}[texte]" value="{{ $o('texte', $valeur['texte'] ?? null) }}" class="champ mt-1"></label>
                <div class="mt-3 grid gap-3 lg:grid-cols-4">
                    <label class="font-normal">{{ $p('sous_champs.type') }}
                        <select name="{{ $champ }}[type]" class="champ mt-1">
                            @foreach (Catalogue::TYPES_BANDEAU as $t)
                                <option value="{{ $t }}" @selected($o('type', $valeur['type'] ?? 'information') === $t)>{{ $p('types_bandeau.'.$t) }}</option>
                            @endforeach
                        </select></label>
                    <label class="font-normal">{{ $p('sous_champs.debut_bandeau') }}
                        <input type="datetime-local" name="{{ $champ }}[debut]" value="{{ $o('debut', $valeur['debut'] ?? null) }}" class="champ mt-1"></label>
                    <label class="font-normal">{{ $p('sous_champs.fin_bandeau') }}
                        <input type="datetime-local" name="{{ $champ }}[fin]" value="{{ $o('fin', $valeur['fin'] ?? null) }}" class="champ mt-1"></label>
                    <label class="font-normal">{{ $p('sous_champs.lien') }}
                        <input type="url" pattern="https://.*" placeholder="https://" name="{{ $champ }}[lien]" value="{{ $o('lien', $valeur['lien'] ?? null) }}" class="champ mt-1"></label>
                </div>
            </fieldset>
            @break

        @case('maintenance')
            <fieldset class="rounded-charte border border-ligne p-4">
                <legend class="px-1 font-bold text-encre">{{ $libelle }}</legend>
                <input type="hidden" name="{{ $champ }}[actif]" value="0">
                <label class="inline-flex items-center gap-2 font-normal">
                    <input type="checkbox" name="{{ $champ }}[actif]" value="1" @checked($o('actif', $valeur['actif'] ?? false))>
                    {{ $p('sous_champs.actif') }}
                </label>
                <div class="mt-3 grid gap-3 lg:grid-cols-[2fr_1fr]">
                    <label class="font-normal">{{ $p('sous_champs.message') }}
                        <input type="text" maxlength="300" name="{{ $champ }}[message]" value="{{ $o('message', $valeur['message'] ?? null) }}" class="champ mt-1"></label>
                    <label class="font-normal">{{ $p('sous_champs.finPrevue') }}
                        <input type="datetime-local" name="{{ $champ }}[finPrevue]" value="{{ $o('finPrevue', $valeur['finPrevue'] ?? null) }}" class="champ mt-1"></label>
                </div>
            </fieldset>
            @break

        @case('reseaux')
        @case('liens')
        @case('financement')
            @php
                $colonnes = match ($type) {
                    'reseaux' => ['reseau', 'url', 'afficher', 'ordre'],
                    'liens' => ['libelle', 'url', 'ordre'],
                    'financement' => ['libelle', 'montant', 'couleur', 'approximatif', 'ordre'],
                };
                $nouvelle = match ($type) {
                    'reseaux' => ['reseau' => 'facebook', 'url' => '', 'afficher' => true, 'ordre' => ''],
                    'liens' => ['libelle' => '', 'url' => '', 'ordre' => ''],
                    'financement' => ['libelle' => '', 'montant' => '', 'couleur' => 'vert', 'approximatif' => false, 'ordre' => ''],
                };
                // Après une erreur, old() renvoie « 0 » / « 1 » : remis en booléens pour les cases à cocher.
                $lignes = array_map(fn (array $l) => array_replace(
                    array_intersect_key(array_replace($nouvelle, $l), $nouvelle),
                    array_intersect_key(['afficher' => (bool) ($l['afficher'] ?? false), 'approximatif' => (bool) ($l['approximatif'] ?? false)], $nouvelle),
                ), array_values(old($champ, $valeur ?? [])));
            @endphp
            <fieldset x-data="{ lignes: @js($lignes), nouvelle: @js($nouvelle) }" @if ($type === 'financement') x-effect="totalLignes = lignes.reduce((s, l) => s + (parseInt(l.montant) || 0), 0)" @endif>
                <legend class="font-bold text-encre">{{ $libelle }}</legend>
                <div class="mt-2 overflow-x-auto">
                    <table class="bo-tableau">
                        <thead><tr>
                            @foreach ($colonnes as $col)<th scope="col">{{ $p('sous_champs.'.$col) }}</th>@endforeach
                            <th scope="col"><span class="sr-only">{{ $p('retirer') }}</span></th>
                        </tr></thead>
                        <tbody>
                            <template x-for="(ligne, i) in lignes" :key="i">
                                <tr>
                                    @foreach ($colonnes as $col)
                                        <td>
                                            @switch($col)
                                                @case('reseau')
                                                    <select class="champ" :name="`{{ $champ }}[${i}][reseau]`" x-model="ligne.reseau" aria-label="{{ $p('sous_champs.reseau') }}">
                                                        @foreach (Catalogue::RESEAUX as $r)<option value="{{ $r }}">{{ $p('reseaux.'.$r) }}</option>@endforeach
                                                    </select>
                                                    @break
                                                @case('couleur')
                                                    <select class="champ" :name="`{{ $champ }}[${i}][couleur]`" x-model="ligne.couleur" aria-label="{{ $p('sous_champs.couleur') }}">
                                                        @foreach (Catalogue::COULEURS as $c)<option value="{{ $c }}">{{ $p('couleurs.'.$c) }}</option>@endforeach
                                                    </select>
                                                    @break
                                                @case('afficher')
                                                @case('approximatif')
                                                    <input type="hidden" :name="`{{ $champ }}[${i}][{{ $col }}]`" value="0">
                                                    <input type="checkbox" value="1" :name="`{{ $champ }}[${i}][{{ $col }}]`" x-model="ligne.{{ $col }}" aria-label="{{ $p('sous_champs.'.$col) }}">
                                                    @break
                                                @case('ordre')
                                                    <input type="number" min="0" class="champ w-20" :name="`{{ $champ }}[${i}][ordre]`" x-model="ligne.ordre" aria-label="{{ $p('sous_champs.ordre') }}">
                                                    @break
                                                @case('montant')
                                                    <input type="number" min="1" step="1" required class="champ w-44" :name="`{{ $champ }}[${i}][montant]`" x-model="ligne.montant" aria-label="{{ $p('sous_champs.montant') }}">
                                                    @break
                                                @case('url')
                                                    <input type="url" required pattern="https://.*" placeholder="https://" class="champ min-w-[260px]" :name="`{{ $champ }}[${i}][url]`" x-model="ligne.url" aria-label="{{ $p('sous_champs.url') }}">
                                                    @break
                                                @default
                                                    <input type="text" required maxlength="120" class="champ" :name="`{{ $champ }}[${i}][{{ $col }}]`" x-model="ligne.{{ $col }}" aria-label="{{ $p('sous_champs.'.$col) }}">
                                            @endswitch
                                        </td>
                                    @endforeach
                                    <td><button type="button" class="lien-action" @click="lignes.splice(i, 1)">{{ $p('retirer') }}</button></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="bo-bouton bo-bouton-secondaire mt-3" @click="lignes.push({ ...nouvelle })">{{ $p('ajouter_ligne') }}</button>
                @if ($type === 'financement')
                    <p class="texte-carte mt-3" aria-live="polite">
                        <span x-text="@js(__('admin.plateforme.aides.total_financement', ['total' => '__T__', 'cout' => '__C__'])).replace('__T__', fcfa(totalLignes)).replace('__C__', fcfa(cout || 0))"></span>
                        <strong x-show="lignes.length && totalLignes !== (cout || 0)" x-cloak class="block text-rouge"
                                x-text="@js(__('admin.plateforme.erreurs.financement_ecart', ['ecart' => '__E__'])).replace('__E__', fcfa(Math.abs((cout || 0) - totalLignes)))"></strong>
                    </p>
                @endif
            </fieldset>
            @break
    @endswitch

    @if ($aide)
        <p id="{{ $id }}-aide" class="aide-champ">{{ $aide }}</p>
    @elseif ($type === 'contact')
        <p class="aide-champ">{{ $p('aides.contact') }}</p>
    @elseif (in_array($type, ['reseaux', 'liens'], true))
        <p class="aide-champ">{{ $p('aides.reseaux') }}</p>
    @endif
    @foreach ($erreurs as $message)
        <p class="message-erreur" role="alert">{{ $message }}</p>
    @endforeach
</div>
