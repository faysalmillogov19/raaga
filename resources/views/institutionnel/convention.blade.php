@php
    $c = fn (string $cle, array $p = []) => __('institutionnel.convention.'.$cle, $p);
    $user = auth()->user();
@endphp

<x-institutionnel.layout :titre="__('institutionnel.menu.convention')" :fil="[['libelle' => __('institutionnel.menu.convention')]]">
    @if (! $convention)
        <div class="bo-carte !mt-0"><p class="texte-carte">{{ $c('aucune') }}</p></div>
    @else
        <div class="grid gap-[18px] xl:grid-cols-2">
            <section class="bo-carte !mt-0" aria-labelledby="convention-titre">
                <h2 id="convention-titre" class="!text-[1.15rem]">{{ $convention->reference }}</h2>
                <p class="texte-carte mt-1">{{ $c('version', ['n' => $convention->version, 'date' => $convention->confirmee_le?->format('d/m/Y')]) }}</p>
                <dl class="bo-definitions mt-3">
                    <dt>{{ $c('actions') }}</dt><dd>{{ format_nombre($convention->nb_actions) }}</dd>
                    <dt>{{ $c('prix') }}</dt><dd>{{ format_fcfa($convention->prix_unitaire) }}</dd>
                    <dt>{{ $c('engage') }}</dt><dd>{{ format_fcfa($convention->montant_engage) }}</dd>
                    <dt>{{ $c('mode') }}</dt><dd>{{ $convention->mode_inscription->label() }}</dd>
                    @if ($convention->droits_particuliers)
                        <dt>{{ $c('droits') }}</dt><dd class="whitespace-pre-line">{{ $convention->droits_particuliers }}</dd>
                    @endif
                </dl>
                @if ($convention->pdf_chemin)
                    <a href="{{ route('institutionnel.convention.pdf', $convention) }}" class="bo-bouton bo-bouton-secondaire mt-4">{{ $c('telecharger') }}</a>
                @endif
            </section>

            <section class="bo-carte !mt-0" aria-labelledby="signature-titre">
                <h2 id="signature-titre" class="!text-[1.15rem]">{{ $c('signature') }}</h2>
                @if ($convention->estSignee())
                    <p class="bo-message mt-3 !mb-0">{{ $c('signee', ['date' => $convention->signee_le->format('d/m/Y à H:i'), 'nom' => $convention->signataire?->name, 'mode' => $convention->signature_mode->court()]) }}</p>
                    <p class="texte-carte mt-2 break-all">{{ $c('empreinte', ['empreinte' => $convention->signature_empreinte]) }}</p>
                @elseif ($convention->signatureEnAttente())
                    <p class="bo-message mt-3 !mb-0">{{ $c('depot_attente', ['date' => $convention->signature_deposee_le->format('d/m/Y')]) }}</p>
                @elseif (! $institution->estVerifiee())
                    <p class="texte-carte mt-2">{{ $c('kyb_requis') }}</p>
                @elseif (! $user->estSignataire())
                    <p class="texte-carte mt-2">{{ $c('lecteur') }}</p>
                @else
                    <p class="mt-2">{{ $c('signer_intro', ['n' => $convention->version]) }}</p>
                    <form method="POST" action="{{ route('institutionnel.convention.signer', $convention) }}" class="mt-3 flex flex-wrap items-end gap-3">
                        @csrf
                        <div class="formulaire-champ !mt-0">
                            <label for="code"><x-requis /> {{ $c('code') }}</label>
                            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required class="champ w-40 @error('code') est-invalide @enderror">
                        </div>
                        <button type="submit" class="bo-bouton bo-bouton-principal">{{ $c('signer') }}</button>
                    </form>
                    @error('code') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror

                    <form method="POST" action="{{ route('institutionnel.convention.deposer', $convention) }}" enctype="multipart/form-data" class="mt-5 border-t border-ligne pt-4">
                        @csrf
                        <div class="formulaire-champ !mt-0">
                            <label for="convention_signee"><x-requis /> {{ $c('deposer') }}</label>
                            <input id="convention_signee" name="convention_signee" type="file" accept="application/pdf" class="champ">
                        </div>
                        <button type="submit" class="bo-bouton bo-bouton-secondaire mt-3">{{ $c('deposer_bouton') }}</button>
                        @error('convention_signee') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
                    </form>
                @endif
            </section>
        </div>

        <section class="bo-carte" aria-labelledby="echeancier-titre">
            <h2 id="echeancier-titre" class="!text-[1.15rem]">{{ $c('echeancier') }}</h2>
            @include('institutionnel.appels._tableau', ['appels' => $convention->appels])
        </section>

        @if ($avenants->isNotEmpty())
            <section class="bo-carte" aria-labelledby="avenants-titre">
                <h2 id="avenants-titre" class="!text-[1.15rem]">{{ $c('avenants') }}</h2>
                <ul class="mt-2 space-y-1">
                    @foreach ($avenants as $avenant)
                        <li>{{ __('admin.parametrage.avenant_numero', ['n' => $loop->remaining + 1]) }} · {{ $avenant->decide_le?->format('d/m/Y') }} · {{ $avenant->motif }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endif
</x-institutionnel.layout>
