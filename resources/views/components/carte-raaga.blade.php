@props(['carte'])

{{--
    Carte d'accès Raaga (aperçu à l'écran) : logo et couleurs Raaga, type, nom (identité validée), photo (selfie validé),
    codes de boutique et site (commerçant), numéro d'actionnaire (actionnaire) ou fonction (personnel), numéro de carte,
    validité, QR code. Jamais de montant ni de nombre d'actions.
--}}
@php
    $c = $carte->contenu ?? [];
    $t = __('public.cartes.carte');
@endphp
<article {{ $attributes->merge(['class' => 'carte-raaga carte-raaga-'.strtolower($carte->type->value)]) }} data-carte="{{ $carte->numero }}">
    <header class="carte-raaga-entete">
        <span class="carte-raaga-logo" aria-hidden="true">R</span>
        <span class="carte-raaga-marque">Raaga</span>
        <span class="carte-raaga-type">{{ $carte->type->label() }}</span>
    </header>
    <div class="carte-raaga-corps">
        <div class="carte-raaga-photo">
            @if (! empty($c['photo']))
                <img src="{{ route('cartes.photo', $carte) }}" alt="{{ $t['photo'] }}">
            @else
                <span aria-hidden="true">{{ mb_substr($c['nom'] ?? '', 0, 1) }}</span>
            @endif
        </div>
        <div class="carte-raaga-infos">
            <p class="carte-raaga-nom">{{ $c['nom'] ?? '' }}</p>
            @if (! empty($c['emplacements']))
                <ul class="carte-raaga-emplacements" data-emplacements>
                    @foreach ($c['emplacements'] as $e)
                        <li><strong>{{ $e['code'] }}</strong> · {{ $e['site'] }}</li>
                    @endforeach
                </ul>
            @endif
            @if (! empty($c['numero_actionnaire']))
                <p data-numero-actionnaire>{{ $t['numero_actionnaire'] }} <strong>{{ $c['numero_actionnaire'] }}</strong></p>
            @endif
            @if (! empty($c['fonction']))
                <p data-fonction>{{ $c['fonction'] }}</p>
            @endif
            <p class="carte-raaga-numero">{{ $t['numero'] }} <strong>{{ $carte->numero }}</strong></p>
            @if ($carte->valide_jusqu_au)
                <p>{{ $t['valide_jusqu_au'] }} <strong>{{ $carte->valide_jusqu_au->format('d/m/Y') }}</strong></p>
            @endif
        </div>
        @isset($qr)
            <div class="carte-raaga-qr">{{ $qr }}</div>
        @endisset
    </div>
</article>
