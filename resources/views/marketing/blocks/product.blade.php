<section class="sec mods" id="product" data-product="{{ $d['style'] }}">
    <div class="wrap">
        @include('marketing.parts.head')
        @switch($d['style'])
            @case('orbit')
                <div class="pv pv-orbit orbit-wrap">
                    <div class="orbit-copy">
                        @if ($tx($d['orbit_title'] ?? null) !== '')<h3>{{ $tx($d['orbit_title']) }}</h3>@endif
                        @if ($tx($d['orbit_text'] ?? null) !== '')<p>{{ $tx($d['orbit_text']) }}</p>@endif
                        <ul>
                            @foreach ($d['groups'] as $group)
                                <li class="c-{{ $group['color'] }}"><i></i>{{ $tx($group['name']) }}<small>{{ $ui('module_count', ['count' => $number(count($group['labels']))]) }}</small></li>
                            @endforeach
                        </ul>
                        <a class="btn btn-p" href="{{ $link('/#product') }}">{{ $ui('all_modules', ['count' => $number($block['module_count'])]) }} →</a>
                    </div>
                    <div class="orbit" aria-hidden="true">
                        <div class="core">@if ($brand['mark'])<img src="{{ $brand['mark'] }}" alt="">@endif</div>
                        <div class="ringo r1">@foreach (array_slice($d['groups'], 0, 8) as $group)<span class="c-{{ $group['color'] }}"><em>{{ $tx($group['name']) }}</em></span>@endforeach</div>
                        <div class="ringo r2">@foreach (array_slice(collect($d['groups'])->flatMap(fn ($group) => array_slice($group['labels'], 0, 2))->all(), 0, 12) as $label)<span><em>{{ $label }}</em></span>@endforeach</div>
                    </div>
                </div>
                @break
            @case('simple')
                <div class="pv pv-chips groups">
                    @foreach ($d['groups'] as $group)
                        <div class="card group">
                            <h3><span class="ico c-{{ $group['color'] }}">@include('marketing.parts.icon', ['name' => 'box'])</span>{{ $tx($group['name']) }}</h3>
                            <div class="chips">@foreach ($group['labels'] as $label)<span>{{ $label }}</span>@endforeach</div>
                        </div>
                    @endforeach
                </div>
                @break
            @default
                <div class="pv pv-bento bento">
                    @foreach ($d['groups'] as $group)
                        <{{ filled($group['url'] ?? null) ? 'a' : 'div' }} class="bx c-{{ $group['color'] }}" @if (filled($group['url'] ?? null)) href="{{ $link($group['url']) }}" @endif>
                            <div class="bx-text">
                                <span class="bx-kicker">{{ $tx($group['name']) }}</span>
                                @if ($tx($group['title'] ?? null) !== '')<h3>{{ $tx($group['title']) }}</h3>@endif
                                @if ($tx($group['text'] ?? null) !== '')<p>{{ $tx($group['text']) }}</p>@endif
                            </div>
                            @include('marketing.parts.card-visual', ['visual' => $group['visual']])
                            <div class="chips">@foreach ($group['labels'] as $label)<span>{{ $label }}</span>@endforeach</div>
                        </{{ filled($group['url'] ?? null) ? 'a' : 'div' }}>
                    @endforeach
                </div>
        @endswitch
    </div>
</section>
