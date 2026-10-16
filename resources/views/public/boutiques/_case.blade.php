{{-- Contenu d'une case du plan : code, sigle du type, statut en toutes lettres, surface ; limite atteinte pour ce citoyen. --}}
<span class="plan-ligne"><span class="plan-code">{{ $boutique->code }}</span><span class="plan-sigle">{{ $boutique->type?->code }}</span></span>
<span class="plan-etat">{{ __('public.boutiques.plan.statuts.'.$statut) }}</span>
@if ($boutique->surface)<span class="plan-surface">{{ __('public.boutiques.plan.surface', ['surface' => format_nombre((float) $boutique->surface, 1)]) }}</span>@endif
@if ($limite ?? null)<span class="plan-limite" data-limite-atteinte>{{ $limite }}</span>@endif
