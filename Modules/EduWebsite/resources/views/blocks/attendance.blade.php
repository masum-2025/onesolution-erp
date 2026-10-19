@include('edu_website::blocks.title', ['eyebrow' => 'today'])
@php($icons = ['present' => 'check', 'late' => 'alarm', 'absent' => 'x', 'leave' => 'sun'])
@foreach (['students', 'staff'] as $who)
    @if ($extra[$who] ?? null)
        <h3 class="who">{{ __("edu_website::public.attendance_{$who}", [], $locale) }}</h3>
        @if ($extra[$who]['taken'] === 0)
            <p class="muted">{{ __('edu_website::public.attendance_none', [], $locale) }}</p>
        @else
            <div class="counts">
                @foreach ($icons as $state => $icon)
                    <div class="count {{ $state }}">
                        <span class="tile">@include('edu_website::icon', ['name' => $icon])</span>
                        <div><b>{{ \Illuminate\Support\Number::format($extra[$who][$state], locale: $locale) }}</b>{{ __("edu_website::public.attendance_{$state}", [], $locale) }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
@endforeach
<p class="muted note">{{ __('edu_website::public.attendance_note', [], $locale) }}</p>
