@php
    use App\Enums\Droit;
    use App\Enums\StatutTypeBoutique;

    $t = __('admin.types');
    $gerer = auth()->user()->can(Droit::SitesGerer->value);
@endphp

<x-admin.layout :titre="$t['titre']" :fil="[['libelle' => __('admin.sites.titre'), 'url' => route('admin.sites')], ['libelle' => $t['titre']]]">
    <div class="bo-carte !mt-0">
        <p class="texte-carte max-w-3xl">{{ $t['intro'] }}</p>

        <div class="mt-4 overflow-x-auto">
            <table class="bo-tableau">
                <thead><tr>
                    @foreach ($t['colonnes'] as $cle => $libelle)
                        <th scope="col" @class(['montant' => in_array($cle, ['ordre', 'utilise_par'], true)])>{{ $libelle }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @forelse ($types as $type)
                        <tr @class(['opacity-70' => ! $type->actif])>
                            <td class="font-mono font-semibold">{{ $type->code ?? '—' }}</td>
                            <td><a href="{{ route('admin.sites.types.show', $type) }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $type->libelle }}</a>
                                @if ($type->par_defaut) <span class="bo-etiquette bo-etiquette-neutre">{{ $t['par_defaut'] }}</span>@endif
                                @if ($type->description)<br><span class="texte-carte">{{ $type->description }}</span>@endif</td>
                            <td>{{ $type->attribuable ? $t['oui'] : $t['non'] }}
                                @if ($type->attribuable_demande !== null)<br><span class="bo-etiquette bo-etiquette-attention">{{ $type->attribuable_demande ? $t['demande_oui'] : $t['demande_non'] }}</span>@endif</td>
                            <td class="montant">{{ $type->ordre }}</td>
                            <td>
                                <span @class(['bo-etiquette', 'bo-etiquette-succes' => $type->statut === StatutTypeBoutique::Valide, 'bo-etiquette-attention' => $type->statut === StatutTypeBoutique::AValider, 'bo-etiquette-echec' => $type->statut === StatutTypeBoutique::Rejete])>{{ $type->statut->label() }}</span>
                                @unless ($type->actif)<span class="bo-etiquette bo-etiquette-neutre">{{ $t['desactive'] }}</span>@endunless
                            </td>
                            <td class="montant">{{ trans_choice('admin.types.boutiques', $type->boutiques_count, ['n' => format_nombre($type->boutiques_count)]) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">{{ $t['aucun'] }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($gerer)
        <section class="bo-carte" aria-labelledby="ajout-titre">
            <h2 id="ajout-titre" class="!text-[1.15rem]">{{ $t['ajouter_titre'] }}</h2>
            <p class="texte-carte mt-1">{{ $t['ajouter_aide'] }}</p>
            <form method="POST" action="{{ route('admin.sites.types.store') }}" class="mt-3 grid gap-4 lg:grid-cols-3" novalidate>
                @csrf
                <x-admin.champ nom="code" :libelle="$t['champs']['code']" maxlength="5" required autocomplete="off" :aide="$t['code_aide']" />
                <x-admin.champ nom="libelle" :libelle="$t['champs']['libelle']" maxlength="80" required />
                <x-admin.champ nom="ordre" :libelle="$t['champs']['ordre']" type="number" min="0" max="9999" />
                <x-admin.champ nom="description" :libelle="$t['champs']['description']" maxlength="300" class="lg:col-span-3" />
                <div class="formulaire-champ !mt-0">
                    <label for="attribuable">{{ $t['champs']['attribuable'] }}</label>
                    <select id="attribuable" name="attribuable" class="champ @error('attribuable') est-invalide @enderror" required>
                        <option value="1" @selected(old('attribuable', '1') === '1')>{{ $t['oui'] }}</option>
                        <option value="0" @selected(old('attribuable') === '0')>{{ $t['non'] }}</option>
                    </select>
                    @error('attribuable') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-end"><button type="submit" class="bo-bouton bo-bouton-principal">{{ $t['ajouter'] }}</button></div>
            </form>
        </section>
    @endif
</x-admin.layout>
