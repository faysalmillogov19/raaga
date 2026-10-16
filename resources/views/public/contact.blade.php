{{-- Nous contacter : coordonnées des paramètres de la plateforme + formulaire de demande (numéro de suivi par SMS). --}}
<x-public.layout :titre="__('public.contact.titre')">
    <x-public.page-entete :titre="__('public.contact.titre')" :intro="__('public.contact.intro')" :fil="[['libelle' => __('public.contact.titre')]]" />

    <div class="conteneur grid gap-8 py-8 tab:py-12 lg:grid-cols-[minmax(0,1fr)_360px]">
        <section aria-labelledby="formulaire-titre">
            @if ($numero = session('contact.numero'))
                <x-public.alerte variante="info" :titre="__('public.contact.formulaire.envoye_titre')" class="mb-6" role="status">
                    {{ __('public.contact.formulaire.envoye', ['numero' => $numero]) }}
                </x-public.alerte>
            @endif

            <h2 id="formulaire-titre">{{ __('public.contact.formulaire.titre') }}</h2>
            <p class="mt-1 texte-carte">{{ __('public.contact.formulaire.intro') }}</p>

            <form method="POST" action="{{ route('contact.store') }}" class="mt-5 space-y-5" novalidate>
                @csrf
                <x-public.champ nom="nom" :libelle="__('public.contact.formulaire.attributs.nom')" :valeur="$user?->name" autocomplete="name" required />
                <x-public.champ nom="telephone" type="tel" :libelle="__('public.contact.formulaire.attributs.telephone')" :aide="__('public.contact.formulaire.aide_telephone')"
                                :valeur="$user?->telephone ? \App\Support\Telephone::formaterLocal($user->telephone) : null" inputmode="tel" autocomplete="tel" required />
                <x-public.champ nom="email" type="email" :libelle="__('public.contact.formulaire.email_facultatif')" :valeur="$user?->email" autocomplete="email" />

                <div>
                    <label for="objet" class="block font-semibold text-encre"><span class="champ-requis" aria-hidden="true">*</span> {{ __('public.contact.formulaire.attributs.objet') }}</label>
                    <select id="objet" name="objet" class="champ mt-1.5 @error('objet') est-invalide @enderror" @error('objet') aria-invalid="true" @enderror aria-describedby="objet-erreur" required>
                        <option value="">{{ __('public.contact.formulaire.choisir') }}</option>
                        @foreach (\App\Models\DemandeContact::OBJETS as $objet)
                            <option value="{{ $objet }}" @selected(old('objet') === $objet)>{{ __('public.contact.formulaire.objets.'.$objet) }}</option>
                        @endforeach
                    </select>
                    <x-public.erreur :messages="$errors->get('objet')" id="objet-erreur" />
                </div>

                <div>
                    <label for="message" class="block font-semibold text-encre"><span class="champ-requis" aria-hidden="true">*</span> {{ __('public.contact.formulaire.attributs.message') }}</label>
                    <p id="message-aide" class="texte-carte">{{ __('public.contact.formulaire.aide_message') }}</p>
                    <textarea id="message" name="message" rows="6" maxlength="2000" required
                              class="champ mt-1.5 min-h-[140px] py-3 @error('message') est-invalide @enderror"
                              @error('message') aria-invalid="true" @enderror aria-describedby="message-aide message-erreur">{{ old('message') }}</textarea>
                    <x-public.erreur :messages="$errors->get('message')" id="message-erreur" />
                </div>

                <button type="submit" class="btn-primaire">{{ __('public.contact.formulaire.envoyer') }}</button>
            </form>
        </section>

        <aside aria-labelledby="coordonnees-titre">
            <div class="carte p-5">
                <h2 id="coordonnees-titre" class="!text-lg">{{ __('public.faq.contact.titre') }}</h2>
                <x-public.contacts />

                @if ($antiFraude = plateforme('messages.messageAntiFraude'))
                    <x-public.alerte variante="attention" class="mt-4">{{ $antiFraude }}</x-public.alerte>
                @endif

                <a href="{{ route('guichets') }}" class="lien-fort mt-4 inline-flex items-center gap-2">
                    <x-public.icone nom="lieu" class="h-5 w-5" />{{ __('public.guichets.titre') }}
                </a>
            </div>
        </aside>
    </div>
</x-public.layout>
