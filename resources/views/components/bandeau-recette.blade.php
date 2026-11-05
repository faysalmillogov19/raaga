{{-- Bandeau de l'environnement de recette (réunion du 05/11/2026) : toutes les pages, uniquement avec APP_ENV=recette. --}}
@if (\App\Support\Recette::active())
    <div class="bandeau-recette" role="note" data-bandeau-recette>{{ __('recette.bandeau') }}</div>
@endif
