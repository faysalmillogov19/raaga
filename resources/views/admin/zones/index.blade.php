@php
    use App\Enums\Droit;

    $z = __('admin.zones');
    $gerer = auth()->user()->can(Droit::SitesGerer->value);
    $valider = auth()->user()->can(Droit::SitesConfirmer->value);
@endphp

<x-admin.layout :titre="$z['titre']" :fil="[['libelle' => $z['titre']]]">
    <p class="texte-carte max-w-3xl">{{ $z['intro'] }}</p>
    @error('motif') <p class="message-erreur" role="alert">{{ $message }}</p> @enderror
    @if ($errors->any() && ! $errors->has('motif'))
        <p class="bo-message bo-message-erreur" role="alert">{{ $errors->first() }}</p>
    @endif

    {{-- Deux listes côte à côte --}}
    <div class="mt-4 grid gap-[18px] xl:grid-cols-2">
        @include('admin.zones._liste', ['liste' => 'niveaux', 'elements' => $niveaux])
        @include('admin.zones._liste', ['liste' => 'zones', 'elements' => $zones])
    </div>
</x-admin.layout>
