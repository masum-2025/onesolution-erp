{{-- The drawn picture beside a slide (no image files): the dashboard, a report card, or partner brands. --}}
@switch($visual)
    @case('report_card')
        <div class="mock" aria-hidden="true">
            <div class="win rc">
                <div class="win-bar"><i></i><i></i><i></i></div>
                <div class="rc-body">
                    <div class="rc-head"><div><small>{{ $ui('demo_exam') }}</small><b>{{ $ui('demo_student') }}</b></div><span class="gpa">{{ $ui('demo_gpa') }}</span></div>
                    @foreach (['english', 'maths', 'science', 'bangla'] as $subject)
                        <div class="rc-row"><span>{{ $ui('demo_'.$subject) }}</span><span class="bar"><i></i></span><b>A+</b></div>
                    @endforeach
                    <div class="rc-foot"><span>{{ $ui('demo_position') }}</span><span class="pill">{{ $ui('demo_published') }}</span></div>
                </div>
            </div>
            <div class="float f1"><span class="ic">✉</span><div><b>{{ $ui('demo_result_sent') }}</b><small>{{ $ui('demo_just_now') }}</small></div></div>
            <div class="float f2"><span class="ic">৳</span><div><b>{{ $ui('demo_fee_paid') }}</b><small>{{ $ui('demo_online') }}</small></div></div>
        </div>
        @break
    @case('brands')
        <div class="mock brands" aria-hidden="true">
            <div class="win bw b1"><div class="bw-top"><i></i>{{ $ui('demo_brand_1') }}</div><div class="bw-lines"><i></i><i></i><i></i></div></div>
            <div class="win bw b2"><div class="bw-top"><i></i>{{ $ui('demo_brand_2') }}</div><div class="bw-lines"><i></i><i></i><i></i></div></div>
            <div class="win bw b3"><div class="bw-top"><i></i>{{ $ui('demo_brand_3') }}</div><div class="bw-lines"><i></i><i></i><i></i></div></div>
            <div class="float f1"><span class="ic">🌐</span><div><b>{{ $ui('demo_domain') }}</b><small>{{ $ui('demo_domain_text') }}</small></div></div>
        </div>
        @break
    @default
        <div class="mock" aria-hidden="true">
            <div class="win">
                <div class="win-bar"><i></i><i></i><i></i></div>
                <div class="app">
                    <div class="side">
                        <div class="b">@if ($brand['mark'])<img src="{{ $brand['mark'] }}" alt="">@endif{{ $brand['name'] }}</div>
                        @foreach (['overview', 'people', 'attendance', 'payroll', 'accounting', 'inventory', 'students'] as $item)
                            <p @class(['on' => $loop->first])>{{ $ui('demo_menu_'.$item) }}</p>
                        @endforeach
                    </div>
                    <div class="main">
                        <h4>{{ $ui('demo_greeting') }}</h4>
                        <div class="kpis">
                            <div class="kpi"><small>{{ $ui('demo_present') }}</small><b>{{ $number(96) }}%</b></div>
                            <div class="kpi"><small>{{ $ui('demo_fees') }}</small><b>৳ {{ $number(8) }}.{{ $number(4) }}L</b></div>
                            <div class="kpi"><small>{{ $ui('demo_menu_payroll') }}</small><b>{{ $ui('demo_ready') }}</b></div>
                        </div>
                        <div class="chart">@for ($i = 0; $i < 9; $i++)<span></span>@endfor</div>
                        <div class="rows">
                            <div><span>{{ $ui('demo_invoice') }}</span><span class="pill">{{ $ui('demo_paid') }}</span></div>
                            <div><span>{{ $ui('demo_leave') }}</span><span class="pill r">{{ $ui('demo_waiting') }}</span></div>
                            <div><span>{{ $ui('demo_stock') }}</span><span>{{ $ui('demo_left') }}</span></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="float f1"><span class="ic">✓</span><div><b>{{ $ui('demo_salary') }}</b><small>{{ $ui('demo_salary_text') }}</small></div></div>
            <div class="float f2"><span class="ic">অ</span><div><b>{{ $ui('demo_bangla_title') }}</b><small>{{ $ui('demo_bangla_text') }}</small></div></div>
        </div>
@endswitch
