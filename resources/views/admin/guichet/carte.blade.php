{{--
    Carte scannée au guichet : VALIDE (vert) ou NON VALIDE (rouge) en grand, photo, nom, type, emplacements, statut.
    Jamais de montant ni de nombre d'actions. Chaque affichage est tracé au journal d'audit.
--}}
@php
    $g = __('admin.guichet');
    $valide = (bool) $carte?->estValide();
    $c = $carte?->contenu ?? [];
@endphp

<x-admin.layout :titre="$g['carte_titre']" :fil="[['libelle' => $g['titre'], 'url' => route('admin.guichet')], ['libelle' => $g['carte_titre']]]">
    <section class="bo-carte !mt-0 guichet-verdict {{ $valide ? 'guichet-verdict-valide' : 'guichet-verdict-invalide' }}" aria-labelledby="verdict"
             data-verdict="{{ $valide ? 'valide' : 'non_valide' }}">
        <p id="verdict" class="guichet-verdict-texte">{{ $valide ? $g['valide'] : $g['non_valide'] }}</p>
        @if ($carte)
            <p class="guichet-verdict-statut">{{ $g['statut'] }} : {{ $carte->statut->label() }}
                @if ($carte->statut === \App\Enums\StatutCarte::Active && ! $valide) · {{ $g['echue'] }} @endif</p>
        @else
            <p class="guichet-verdict-statut">{{ $g['inconnue'] }}</p>
        @endif
    </section>

    @if ($carte)
        <section class="bo-carte guichet-titulaire" aria-labelledby="titulaire-titre">
            <div class="guichet-photo">
                @if (! empty($c['photo']))
                    <img src="{{ route('cartes.photo', $carte) }}" alt="{{ __('public.cartes.carte.photo') }}">
                @else
                    <span aria-hidden="true">{{ mb_substr($c['nom'] ?? '', 0, 1) }}</span>
                @endif
            </div>
            <div class="guichet-infos">
                <h2 id="titulaire-titre" class="guichet-nom">{{ $c['nom'] ?? $carte->user?->name }}</h2>
                <dl class="bo-definitions mt-3">
                    <dt>{{ $g['type'] }}</dt><dd>{{ $carte->type->label() }}</dd>
                    <dt>{{ $g['numero'] }}</dt><dd>{{ $carte->numero }}</dd>
                    @if (! empty($c['emplacements']))
                        <dt>{{ $g['emplacements'] }}</dt>
                        <dd data-emplacements>
                            @foreach ($c['emplacements'] as $e)
                                <span class="block"><strong>{{ $e['code'] }}</strong> · {{ $e['site'] }}</span>
                            @endforeach
                        </dd>
                    @endif
                    @if (! empty($c['numero_actionnaire']))
                        <dt>{{ $g['numero_actionnaire'] }}</dt><dd>{{ $c['numero_actionnaire'] }}</dd>
                    @endif
                    @if (! empty($c['fonction']))
                        <dt>{{ $g['fonction'] }}</dt><dd>{{ $c['fonction'] }}</dd>
                    @endif
                    @if ($carte->valide_jusqu_au)
                        <dt>{{ $g['valide_jusqu_au'] }}</dt><dd>{{ $carte->valide_jusqu_au->format('d/m/Y') }}</dd>
                    @endif
                </dl>
            </div>
        </section>
    @endif

    <div class="mt-[18px] flex flex-wrap gap-3">
        @if ($carte)
            <x-admin.bouton :href="route('admin.guichet.titulaire', $carte->user_id)" icone="utilisateur">{{ $g['dossier'] }}</x-admin.bouton>
        @endif
        <x-admin.bouton :href="route('admin.guichet')" variante="secondaire" icone="recherche">{{ $g['autre'] }}</x-admin.bouton>
    </div>
    <p class="texte-carte mt-3">{{ $g['trace'] }}</p>
</x-admin.layout>
