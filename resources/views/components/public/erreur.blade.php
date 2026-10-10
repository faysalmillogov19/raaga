@props(['messages' => [], 'id' => null])

@if ($messages)
    <ul @if ($id) id="{{ $id }}" @endif {{ $attributes->merge(['class' => 'mt-1.5 space-y-1 text-[0.95rem] font-semibold text-rouge']) }}>
        @foreach ((array) $messages as $message)
            <li class="flex items-start gap-1.5">
                <x-public.icone nom="danger" class="mt-0.5 h-4 w-4" />
                <span>{{ $message }}</span>
            </li>
        @endforeach
    </ul>
@endif
