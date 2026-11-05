{{--
    Greffes d'immatriculation au RCCM (05/11/2026) : liste (SUPER_ADMIN : ajout, modification, activation ; ADMIN : lecture)
    et greffes saisis en « Autre (préciser) » par les titulaires, que le SUPER_ADMIN peut ajouter à la liste.
--}}
@php
    $g = __('admin.greffes');
    $gerer = auth()->user()->can(\App\Enums\Droit::GreffesGerer->value);
@endphp

<x-admin.layout :titre="$g['titre']" :sous-titre="$g['intro']" :fil="[['libelle' => $g['titre']]]">
    @if ($gerer)
        <x-slot:actions>
            <x-admin.fenetre id="ajouter-greffe" :titre="$g['ajouter']" :action="route('admin.greffes.store')" :bouton="$g['ajouter_bouton']" declencheur-icone="plus">
                <x-slot:declencheur>{{ $g['ajouter'] }}</x-slot:declencheur>
                <div class="adm-fenetre-grille">
                    <x-admin.champ nom="libelle" id="ajout-libelle" :libelle="$g['colonnes']['libelle']" maxlength="160" required class="adm-plein" />
                    <x-admin.champ nom="ordre" id="ajout-ordre" :libelle="$g['colonnes']['ordre']" type="number" min="0" max="998" :aide="$g['ordre_aide']" />
                </div>
            </x-admin.fenetre>
        </x-slot:actions>
    @endif

    <section class="bo-carte !mt-0" aria-labelledby="greffes-liste" data-greffes>
        <h2 id="greffes-liste" class="!text-[1.15rem]">{{ $g['liste'] }}</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr><th scope="col">{{ $g['colonnes']['libelle'] }}</th><th scope="col">{{ $g['colonnes']['ordre'] }}</th><th scope="col">{{ $g['colonnes']['comptes'] }}</th>
                    <th scope="col">{{ $g['colonnes']['statut'] }}</th>@if ($gerer)<th scope="col"><span class="sr-only">{{ $g['colonnes']['actions'] }}</span></th>@endif</tr></thead>
                <tbody>
                    @foreach ($greffes as $greffe)
                        <tr data-greffe="{{ $greffe->code }}">
                            <td class="font-semibold">{{ $greffe->libelle }}</td>
                            <td>{{ $greffe->code === \App\Models\Greffe::AUTRE ? '' : $greffe->ordre }}</td>
                            <td>{{ format_nombre((int) ($comptes[$greffe->id] ?? 0)) }}</td>
                            <td>{!! \App\Support\BadgeStatut::html($greffe->actif ? $g['actif'] : $g['desactive']) !!}</td>
                            @if ($gerer)
                                <td>
                                    @unless ($greffe->code === \App\Models\Greffe::AUTRE)
                                        <div class="flex justify-end gap-2">
                                            <x-admin.fenetre :id="'modifier-greffe-'.$greffe->id" :titre="$g['modifier'].' · '.$greffe->libelle" :action="route('admin.greffes.update', $greffe)" methode="PUT"
                                                             declencheur-variante="secondaire" declencheur-taille="petit" :declencheur-libelle="$g['modifier'].' '.$greffe->libelle">
                                                <x-slot:declencheur>{{ $g['modifier'] }}</x-slot:declencheur>
                                                <div class="adm-fenetre-grille">
                                                    <x-admin.champ nom="libelle" :id="'greffe-'.$greffe->id.'-libelle'" :libelle="$g['colonnes']['libelle']" :valeur="$greffe->libelle" maxlength="160" required class="adm-plein" />
                                                    <x-admin.champ nom="ordre" :id="'greffe-'.$greffe->id.'-ordre'" :libelle="$g['colonnes']['ordre']" :valeur="$greffe->ordre" type="number" min="0" max="998" />
                                                </div>
                                            </x-admin.fenetre>
                                            @if ($greffe->actif)
                                                <x-admin.confirmation :id="'desactiver-greffe-'.$greffe->id" taille="petit" :titre="$g['desactiver'].' · '.$greffe->libelle" :message="$g['confirmer_desactivation']"
                                                                      :action="route('admin.greffes.desactiver', $greffe)" :bouton="$g['desactiver']" :libelle="$g['desactiver'].' '.$greffe->libelle">
                                                    <x-slot:declencheur>{{ $g['desactiver'] }}</x-slot:declencheur>
                                                </x-admin.confirmation>
                                            @else
                                                <x-admin.confirmation :id="'activer-greffe-'.$greffe->id" taille="petit" :titre="$g['activer'].' · '.$greffe->libelle" :message="$g['confirmer_activation']"
                                                                      :action="route('admin.greffes.activer', $greffe)" :bouton="$g['activer']" :libelle="$g['activer'].' '.$greffe->libelle">
                                                    <x-slot:declencheur>{{ $g['activer'] }}</x-slot:declencheur>
                                                </x-admin.confirmation>
                                            @endif
                                        </div>
                                    @endunless
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="bo-carte" aria-labelledby="greffes-autres" data-greffes-autres>
        <h2 id="greffes-autres" class="!text-[1.15rem]">{{ $g['autres_titre'] }}</h2>
        <p class="texte-carte mt-1">{{ $g['autres_intro'] }}</p>
        @if ($autres->isEmpty())
            <p class="texte-carte mt-3">{{ $g['autres_aucun'] }}</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="bo-tableau">
                    <thead><tr><th scope="col">{{ $g['autres_valeur'] }}</th><th scope="col">{{ $g['colonnes']['comptes'] }}</th>@if ($gerer)<th scope="col"><span class="sr-only">{{ $g['colonnes']['actions'] }}</span></th>@endif</tr></thead>
                    <tbody>
                        @foreach ($autres as $i => $autre)
                            <tr data-greffe-autre>
                                <td class="font-semibold">{{ $autre->valeur }}</td>
                                <td>{{ format_nombre((int) $autre->n) }}</td>
                                @if ($gerer)
                                    <td class="text-right">
                                        <x-admin.confirmation :id="'ajouter-autre-'.$i" taille="petit" :titre="$g['autres_ajouter']" :message="__('admin.greffes.autres_confirmer', ['valeur' => $autre->valeur, 'n' => $autre->n])"
                                                              :action="route('admin.greffes.autre')" :bouton="$g['autres_ajouter']" :libelle="$g['autres_ajouter'].' '.$autre->valeur">
                                            <x-slot:declencheur>{{ $g['autres_ajouter'] }}</x-slot:declencheur>
                                            <input type="hidden" name="valeur" value="{{ $autre->valeur }}">
                                        </x-admin.confirmation>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-admin.layout>
