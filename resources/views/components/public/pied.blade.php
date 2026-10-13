@php
    $identite = plateforme('identite');
    $contacts = plateforme('contacts');
    $reseaux = array_values(array_filter(plateforme('reseaux.reseauxSociaux'), fn ($r) => $r['afficher']));
    $liens = plateforme('reseaux.liensInstitutionnels');
    $antiFraude = plateforme('messages.messageAntiFraude');
    $titulaire = plateforme('mentions.denominationSociale') ?: $identite['nomPlateforme'];
@endphp

<footer class="bg-encre text-gris-clair">
    <div class="conteneur pied-grille py-10">
        <div>
            <a href="{{ route('accueil') }}" class="inline-flex min-h-[44px] items-center">
                <x-logo variante="blanc" class="h-[52px]" :alt="$identite['nomPlateforme'].' – '.__('public.menu.accueil')" />
            </a>
            <p class="mt-2 leading-relaxed">{{ __('public.pied.presentation') }}</p>
            @if ($identite['mentionTutelle'])
                <p class="mt-2 leading-relaxed">{{ $identite['mentionTutelle'] }}</p>
            @endif
        </div>

        <div>
            <h2 class="pied-titre">{{ __('public.pied.decouvrir') }}</h2>
            <ul>
                @foreach (\App\Support\Phase::actionsPubliques() ? ['offre', 'transparence', 'faq'] : ['boutiques', 'faq'] as $cle)
                    <li><a href="{{ route($cle) }}" class="pied-lien">{{ __('public.menu.'.$cle) }}</a></li>
                @endforeach
            </ul>
        </div>

        <div>
            <h2 class="pied-titre">{{ __('public.pied.mon_compte') }}</h2>
            <ul>
                <li><a href="{{ route('inscription') }}" class="pied-lien">{{ __('public.pied.creer_compte') }}</a></li>
                <li><a href="{{ route('espace') }}" class="pied-lien">{{ __('public.pied.mon_espace') }}</a></li>
                @if (\App\Support\Phase::actionsPubliques())
                    <li><a href="{{ route('souscrire') }}" class="pied-lien">{{ __('public.pied.souscrire') }}</a></li>
                @else
                    <li><a href="{{ route('boutiques') }}" class="pied-lien">{{ __('public.boutiques.candidater_boutique') }}</a></li>
                @endif
            </ul>
        </div>

        <div>
            <h2 class="pied-titre">{{ __('public.pied.assistance') }}</h2>
            <ul>
                @if ($contacts['numeroVert'])
                    <li><a href="tel:{{ $contacts['numeroVert'] }}" class="pied-lien inline-flex min-h-[44px] items-center gap-2 font-semibold !text-white">
                        <x-icon name="telephone" class="h-4 w-4" />{{ __('public.compte.numero_vert') }} : <span class="whitespace-nowrap">{{ \App\Support\Telephone::formaterLocal($contacts['numeroVert']) }}</span></a></li>
                @endif
                @if ($contacts['emailAssistance'])
                    <li><a href="mailto:{{ $contacts['emailAssistance'] }}" class="pied-lien break-all">{{ $contacts['emailAssistance'] }}</a></li>
                @endif
                <li><a href="{{ route('faq') }}" class="pied-lien">{{ __('public.pied.aide') }}</a></li>
                <li><a href="{{ route('guichets') }}" class="pied-lien">{{ __('public.pied.guichets') }}</a></li>
                <li><a href="{{ route('contact') }}" class="pied-lien">{{ __('public.pied.contact') }}</a></li>
            </ul>
        </div>
    </div>

    @if ($reseaux || $liens || $antiFraude)
        <div class="border-t border-white/10">
            <div class="conteneur flex flex-col gap-3 py-4 text-[.9rem] lg:flex-row lg:items-start lg:justify-between">
                @if ($antiFraude)
                    <p class="max-w-2xl"><strong class="text-white">{{ __('public.pied.anti_fraude') }}</strong> {{ $antiFraude }}</p>
                @endif
                <div class="flex flex-col gap-2">
                    @if ($reseaux)
                        <nav aria-label="{{ __('public.pied.reseaux') }}">
                            <ul class="flex flex-wrap gap-x-4">
                                @foreach ($reseaux as $r)
                                    <li><a href="{{ $r['url'] }}" class="pied-lien" rel="noopener" target="_blank">{{ __('admin.plateforme.reseaux.'.$r['reseau']) }}</a></li>
                                @endforeach
                            </ul>
                        </nav>
                    @endif
                    @if ($liens)
                        <nav aria-label="{{ __('public.pied.liens_officiels') }}">
                            <ul class="flex flex-wrap gap-x-4">
                                @foreach ($liens as $l)
                                    <li><a href="{{ $l['url'] }}" class="pied-lien" rel="noopener" target="_blank">{{ $l['libelle'] }}</a></li>
                                @endforeach
                            </ul>
                        </nav>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="border-t border-white/10">
        <div class="conteneur flex flex-col gap-2 py-4 text-[.9rem] sm:flex-row sm:items-center sm:justify-between">
            <p>© {{ now()->year }}{{ $titulaire ? ' '.$titulaire : '' }}</p>
            <nav aria-label="{{ __('public.pied.liens_legaux') }}">
                <ul class="flex flex-wrap gap-x-4">
                    @foreach (['mentions', 'donnees', 'accessibilite'] as $cle)
                        <li><a href="{{ route('legal.'.$cle) }}" class="pied-lien">{{ __('public.pied.'.$cle) }}</a></li>
                    @endforeach
                </ul>
            </nav>
        </div>
    </div>
</footer>
