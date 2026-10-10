@php
    $t = fn (string $cle, array $p = []) => __('institutionnel.tableau.'.$cle, $p);
    $user = auth()->user();
    $aFaire = array_filter([
        $piecesValidees < $piecesTotal && ! $institution->estVerifiee() ? [$t('faire_kyb'), route('institutionnel.organisation')] : null,
        $convention && ! $convention->estSignee() && ! $convention->signatureEnAttente() ? [$t('faire_signer'), route('institutionnel.convention')] : null,
        $prochain && $convention?->estSignee() ? [$t('faire_virement', ['n' => $prochain->numero, 'date' => $prochain->echeance->format('d/m/Y')]), route('institutionnel.appels.show', $prochain)] : null,
    ]);
@endphp

<x-institutionnel.layout :titre="__('institutionnel.menu.tableau')">
    <div class="bo-carte !mt-0">
        <p class="text-[1.1rem] font-bold text-encre">{{ $t('bonjour', ['nom' => $user->name]) }}</p>
        <p class="mt-1">{{ $t('institution', ['institution' => $institution->denomination, 'role' => mb_strtolower(__('institutionnel.statuts_role.'.$user->codeRole()->value))]) }}</p>
    </div>

    <section class="bo-carte" aria-labelledby="chiffres-titre">
        <h2 id="chiffres-titre" class="sr-only">{{ __('institutionnel.menu.tableau') }}</h2>
        <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ([
                [$t('actions'), format_nombre($actions)],
                [$t('engage'), format_fcfa($engage)],
                [$t('paye'), format_fcfa($paye)],
                [$t('restant'), format_fcfa(max(0, $engage - $paye))],
                [$t('part'), format_nombre($part, 2)."\u{00A0}%"],
                [$t('dividendes'), format_fcfa(0)],
            ] as [$libelle, $valeur])
                <div class="flex flex-col-reverse rounded-charte border border-ligne p-4">
                    <dt class="texte-carte">{{ $libelle }}</dt>
                    <dd class="text-[1.15rem] font-extrabold text-encre">{{ $valeur }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <div class="mt-[18px] grid gap-[18px] xl:grid-cols-2">
        <section class="bo-carte !mt-0" aria-labelledby="prochain-titre">
            <h2 id="prochain-titre" class="!text-[1.15rem]">{{ $t('prochain') }}</h2>
            @if ($prochain)
                <p class="mt-2"><a href="{{ route('institutionnel.appels.show', $prochain) }}" class="font-bold text-vert-fonce underline underline-offset-2">n° {{ $prochain->numero }} — {{ $prochain->libelle }}</a></p>
                <p class="mt-1">{{ format_fcfa($prochain->resteAPayer()) }} · {{ $prochain->echeance->translatedFormat('j F Y') }} · <x-institutionnel.statut :statut="$prochain->statut" /></p>
            @else
                <p class="texte-carte mt-2">{{ $t('aucun_appel') }}</p>
            @endif
            <p class="texte-carte mt-4">{{ $t('kyb') }} : <x-institutionnel.statut :statut="$institution->statut" /> · {{ __('institutionnel.bo.institutions.pieces', ['valides' => $piecesValidees, 'total' => $piecesTotal]) }}</p>
        </section>

        <section class="bo-carte !mt-0" aria-labelledby="a-faire-titre">
            <h2 id="a-faire-titre" class="!text-[1.15rem]">{{ $t('a_faire') }}</h2>
            @if ($aFaire)
                <ul class="mt-2 divide-y divide-ligne">
                    @foreach ($aFaire as [$texte, $lien])
                        <li class="py-2"><a href="{{ $lien }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $texte }}</a></li>
                    @endforeach
                </ul>
            @else
                <p class="texte-carte mt-2">{{ $t('rien') }}</p>
            @endif
        </section>
    </div>
</x-institutionnel.layout>
