@php($saved = collect($block['plans'])->max('months_saved'))
<section class="sec mods center" id="pricing">
    <div class="wrap">
        @include('marketing.parts.head')
        <input type="radio" name="per-{{ $block['id'] }}" id="mo-{{ $block['id'] }}" class="per-mo" checked>
        <input type="radio" name="per-{{ $block['id'] }}" id="yr-{{ $block['id'] }}" class="per-yr">
        <div class="toggle">
            <label for="mo-{{ $block['id'] }}">{{ $ui('monthly') }}</label>
            <label for="yr-{{ $block['id'] }}">{{ $ui('yearly') }}@if ($saved > 0) <span class="save">{{ $ui('months_free', ['count' => $number($saved)]) }}</span>@endif</label>
        </div>
        <div class="plans">
            @foreach ($block['plans'] as $plan)
                <div @class(['plan', 'hot' => $plan['highlight']])>
                    @if ($plan['highlight'])<span class="tag">{{ $ui('most_popular') }}</span>@endif
                    <h3>{{ $plan['name'] }}</h3>
                    <p class="d">{{ $plan['description'] }}</p>
                    <div class="price">
                        @if ($plan['free'])
                            {{ $plan['monthly'] }}
                        @else
                            <span class="m">{{ $plan['monthly'] }}</span>@if ($plan['yearly'])<span class="y">{{ $plan['yearly'] }}</span>@endif
                            <small><span class="m">{{ $ui('per_month') }}</span>@if ($plan['yearly'])<span class="y">{{ $ui('per_year') }}</span>@endif</small>
                        @endif
                    </div>
                    @if ($plan['features'] !== [])
                        <ul>@foreach ($plan['features'] as $feature)<li>{{ $tx($feature) }}</li>@endforeach</ul>
                    @endif
                    <a @class(['btn', 'btn-p' => $plan['highlight'], 'btn-g' => ! $plan['highlight']]) href="{{ $link('app:/signup?plan='.$plan['key']) }}">{{ $plan['free'] ? $ui('start_free') : $ui('choose_plan', ['plan' => $plan['name']]) }}</a>
                </div>
            @endforeach
        </div>
        @if ($tx($d['note'] ?? null) !== '')<p class="note">{{ $tx($d['note']) }}</p>@endif
    </div>
</section>
