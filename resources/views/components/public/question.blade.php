@props(['question'])

{{-- Question / réponse dépliable (accordéon natif, fonctionne sans JavaScript). « + » fermé, « – » ouvert. --}}
<details {{ $attributes->merge(['class' => 'faq-item']) }}>
    <summary>{{ $question }}</summary>
    <div class="faq-reponse">{{ $slot }}</div>
</details>
