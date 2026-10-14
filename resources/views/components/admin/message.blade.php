@props(['type' => 'ok', 'titre' => null])

{{-- Message : ok et info annoncés poliment (role="status"), avertissement et erreur immédiatement (role="alert"). --}}
<div {{ $attributes->merge(['class' => 'adm-message adm-message-'.$type, 'role' => in_array($type, ['erreur', 'avertissement'], true) ? 'alert' : 'status']) }}>
    @if ($titre)<strong>{{ $titre }}</strong> @endif{{ $slot }}
</div>
