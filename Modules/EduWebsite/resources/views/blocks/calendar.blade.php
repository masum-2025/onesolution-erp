@php
    // Events by month, in date order; past ones quieter.
    $months = collect($data['items'] ?? [])->sortBy('from')->groupBy(fn ($item) => substr($item['from'], 0, 7));
@endphp
@include('edu_website::blocks.title', ['eyebrow' => 'calendar'])
@if ($months->isEmpty())
    <p class="muted note">{{ __('edu_website::public.no_events', [], $locale) }}</p>
@else
    <div class="calendar">
        @foreach ($months as $month => $items)
            <section class="month">
                <h3>{{ $date($month.'-01', 'MMMM YYYY') }}</h3>
                <ol class="plain">
                    @foreach ($items as $item)
                        <li class="event {{ ($item['to'] ?? $item['from']) < $today ? 'past' : '' }}">
                            <time datetime="{{ $item['from'] }}">
                                <b>{{ $date($item['from'], 'D') }}</b>
                                <span>{{ $date($item['from'], 'MMM') }}</span>
                            </time>
                            <div>
                                <p>{{ $text($item['label'] ?? null) }}</p>
                                @if (! empty($item['to']) && $item['to'] !== $item['from'])
                                    <small class="muted">{{ $date($item['from'], 'D MMM') }} – {{ $date($item['to'], 'D MMM') }}</small>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endforeach
    </div>
@endif
