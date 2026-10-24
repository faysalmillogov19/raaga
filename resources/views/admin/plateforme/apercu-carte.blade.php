{{--
    Aperçu de la carte d'identification (SUPER_ADMIN) : recto et verso avec les valeurs du formulaire et un titulaire
    fictif marqué SPÉCIMEN. Rien n'est enregistré, aucun compte n'existe. Variables : $valeurs, $images, $manquants.
--}}
@php
    use App\Support\ImagesPlateforme;

    $c = fn (string $cle, array $r = []) => __('admin.plateforme.carte.'.$cle, $r);
    $specimen = $c('specimen');
    $valeur = fn (?string $v) => filled($v) ? e($v) : '<span class="carte-id-manque">'.e($c('non_renseigne')).'</span>';
@endphp

<x-admin.layout :titre="$c('apercu_titre')" :fil="[['libelle' => __('admin.menu.plateforme'), 'url' => route('admin.plateforme', ['section' => 'carte'])], ['libelle' => $c('apercu_titre')]]">
    <x-admin.message type="info">{{ $c('apercu_bandeau') }}</x-admin.message>

    <div @class(['carte-id-etat mb-5', 'carte-id-etat-pret' => $manquants === [], 'carte-id-etat-manquant' => $manquants !== []]) role="status">
        <p class="font-bold">{{ $manquants === [] ? $c('pret') : $c('manquants') }}</p>
        @if ($manquants !== [])
            <p class="mt-1">{{ $c('manquants_detail') }} {{ collect($manquants)->map(fn ($m) => __('admin.plateforme.champs.'.$m))->join(', ') }}.</p>
        @endif
    </div>

    <div class="flex flex-wrap gap-8">
        {{-- Recto --}}
        <figure class="m-0">
            <figcaption class="mb-2 font-bold text-encre">{{ $c('recto') }}</figcaption>
            <div class="carte-id" data-face="recto">
                <div class="carte-id-entete">
                    @if ($a = ImagesPlateforme::url('armoiries'))<img src="{{ $a }}" alt="" class="carte-id-armoiries">@endif
                    <div class="carte-id-pays">
                        <strong>{{ $c('pays') }}</strong>
                        @if (filled(plateforme('identite.deviseNationale')))<span>{{ plateforme('identite.deviseNationale') }}</span>@endif
                    </div>
                    @if ($l = ImagesPlateforme::url('logoHorizontal'))<img src="{{ $l }}" alt="Raaga" class="carte-id-logo">@endif
                </div>
                <p class="carte-id-titre">{{ $c('titre_carte') }}</p>
                <div class="carte-id-corps">
                    <div class="carte-id-photo">{{ $c('photo') }}</div>
                    <dl class="carte-id-infos">
                        <dt>{{ $c('nom') }}</dt><dd>{{ $specimen }}</dd>
                        <dt>{{ $c('activite') }}</dt><dd>{{ $specimen }}</dd>
                        <dt>{{ $c('emplacement') }}</dt><dd>{{ $specimen }}</dd>
                        <dt>{{ $c('numero') }}</dt><dd>{{ $specimen }}</dd>
                        <dt>{{ $c('validite') }}</dt><dd>{{ $specimen }}</dd>
                    </dl>
                    <div class="carte-id-qr" aria-label="{{ $c('qr') }}">{{ $c('qr') }}</div>
                </div>
                <span class="carte-id-filigrane" aria-hidden="true">{{ $specimen }}</span>
                <span class="carte-id-lisere" aria-hidden="true"></span>
            </div>
        </figure>

        {{-- Verso --}}
        <figure class="m-0">
            <figcaption class="mb-2 font-bold text-encre">{{ $c('verso') }}</figcaption>
            <div class="carte-id" data-face="verso">
                <div class="carte-id-verso">
                    <p class="carte-id-mention">{{ $c('personnelle') }}</p>
                    <div class="carte-id-autorite">
                        <p class="carte-id-delivree">{{ $c('delivree_par') }}</p>
                        <p class="carte-id-qualite">{!! $valeur($valeurs['qualiteAutorite'] ?? null) !!}</p>
                        <div class="carte-id-visa">
                            @if ($images['cachetAutorite'])
                                <img src="{{ $images['cachetAutorite'] }}" alt="{{ __('admin.plateforme.champs.cachetAutorite') }}" class="carte-id-cachet">
                            @else
                                <span class="carte-id-manque carte-id-cachet-vide">{{ __('admin.plateforme.champs.cachetAutorite') }} : {{ $c('non_renseigne') }}</span>
                            @endif
                            @if ($images['signatureAutorite'])
                                <img src="{{ $images['signatureAutorite'] }}" alt="{{ __('admin.plateforme.champs.signatureAutorite') }}" class="carte-id-signature">
                            @else
                                <span class="carte-id-manque">{{ __('admin.plateforme.champs.signatureAutorite') }} : {{ $c('non_renseigne') }}</span>
                            @endif
                        </div>
                        <p class="carte-id-nom-autorite">{!! $valeur($valeurs['nomAutorite'] ?? null) !!}</p>
                    </div>
                    <p class="carte-id-perte">{{ $c('perte') }}<strong>{!! $valeur($valeurs['numeroVertCarte'] ?? null) !!}</strong></p>
                </div>
                <span class="carte-id-filigrane" aria-hidden="true">{{ $specimen }}</span>
                <span class="carte-id-lisere" aria-hidden="true"></span>
            </div>
        </figure>
    </div>
</x-admin.layout>
