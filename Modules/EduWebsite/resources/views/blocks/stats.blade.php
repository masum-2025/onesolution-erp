@include('edu_website::blocks.title', ['eyebrow' => 'stats'])
{{-- The numbers are written in full (in the visitor's digits); the script counts up to them once they come into view. --}}
<ul class="plain stats">
    @foreach ($data['items'] ?? [] as $item)
        <li class="stat">
            <span class="figure"><b data-count="{{ (int) ($item['value'] ?? 0) }}">{{ $number((int) ($item['value'] ?? 0)) }}</b>@if (($item['suffix'] ?? '') !== '')<span class="suffix">{{ $item['suffix'] }}</span>@endif</span>
            <span class="label">{{ $text($item['label'] ?? null) }}</span>
        </li>
    @endforeach
</ul>
