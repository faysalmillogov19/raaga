{{-- Photos des cartes du personnel, en attente de validation par l'ADMIN. --}}
@php($p = __('admin.cartes.photos'))

<x-admin.layout :titre="$p['titre']" :fil="[['libelle' => $p['titre']]]">
    <div class="bo-carte !mt-0">
        <p class="texte-carte max-w-3xl">{{ $p['intro'] }}</p>
        @if ($photos->isEmpty())
            <p class="mt-4">{{ $p['vide'] }}</p>
        @else
            <ul class="photos-personnel mt-4" data-photos>
                @foreach ($photos as $photo)
                    <li class="photos-personnel-element">
                        <img src="{{ route('admin.kyc.piece', $photo) }}" alt="{{ $photo->user->name }}" class="photos-personnel-image">
                        <p class="mt-2 font-semibold">{{ $photo->user->name }}</p>
                        <p class="texte-carte">{{ $photo->user->codeRole()->label() }} · {{ __('admin.cartes.photos.deposee_le', ['date' => $photo->created_at->format('d/m/Y H:i')]) }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('admin.cartes.photos.valider', $photo) }}">
                                @csrf
                                <x-admin.bouton type="submit" taille="petit" icone="check">{{ $p['valider'] }}</x-admin.bouton>
                            </form>
                            <x-admin.confirmation id="refuser-photo-{{ $photo->id }}" :titre="$p['refuser']" :message="$photo->user->name"
                                                  :action="route('admin.cartes.photos.refuser', $photo)" :bouton="$p['refuser']" motif danger taille="petit">
                                <x-slot:declencheur>{{ $p['refuser'] }}</x-slot:declencheur>
                            </x-admin.confirmation>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-admin.layout>
