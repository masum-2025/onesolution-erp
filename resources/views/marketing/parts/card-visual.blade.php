{{-- A small moving picture inside a bento card (decoration only). --}}
@switch($visual)
    @case('punch')
        <div class="vis punch" aria-hidden="true">
            @foreach ([['RA', 'demo_person_1', 'demo_role_1', '08:02'], ['KH', 'demo_person_2', 'demo_role_2', '08:05'], ['NS', 'demo_person_3', 'demo_role_3', '08:11'], ['JA', 'demo_person_4', 'demo_role_4', '08:24']] as [$initials, $person, $role, $time])
                <div class="pr"><i>{{ $initials }}</i><span>{{ $ui($person) }}<small>{{ $ui($role) }}</small></span><b @class(['late' => $loop->last])>{{ $time }}</b></div>
            @endforeach
        </div>
        @break
    @case('line')
        <div class="vis line" aria-hidden="true">
            <b class="amt">৳ {{ $number(1240500) }} <small>▲ {{ $number(18) }}%</small></b>
            <svg viewBox="0 0 300 90" preserveAspectRatio="none"><defs><linearGradient id="lg" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="currentColor" stop-opacity=".3"/><stop offset="1" stop-color="currentColor" stop-opacity="0"/></linearGradient></defs><path class="area" d="M0 70 C30 64 45 50 70 54 S120 30 150 38 210 14 240 22 280 8 300 6 V90 H0Z" fill="url(#lg)"/><path class="stroke" d="M0 70 C30 64 45 50 70 54 S120 30 150 38 210 14 240 22 280 8 300 6" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
        </div>
        @break
    @case('grade')
        <div class="vis grade" aria-hidden="true"><b>A+</b><small>{{ $ui('demo_gpa') }}</small></div>
        @break
    @case('ring')
        <div class="vis ring" aria-hidden="true"><svg viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="none" stroke="currentColor" stroke-opacity=".12" stroke-width="10"/><circle class="arc" cx="50" cy="50" r="40" fill="none" stroke="currentColor" stroke-width="10" stroke-linecap="round" pathLength="100" stroke-dasharray="78 100" transform="rotate(-90 50 50)"/></svg><b>{{ $number(78) }}%<small>{{ $ui('demo_in_stock') }}</small></b></div>
        @break
    @case('pipe')
        <div class="vis pipe" aria-hidden="true"><span>{{ $ui('demo_lead') }}</span><span>{{ $ui('demo_quote') }}</span><span>{{ $ui('demo_won') }}</span><i></i></div>
        @break
    @case('sync')
        <div class="vis sync" aria-hidden="true"><span class="dev">📱</span><span class="wire"><i></i><i></i><i></i></span><span class="dev">☁️</span><small>{{ $ui('demo_synced', ['count' => $number(24)]) }}</small></div>
        @break
@endswitch
