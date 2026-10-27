{{-- The jobs open on the careers page (Recruitment); each opens there to apply. --}}
@include('edu_website::blocks.title', ['eyebrow' => 'jobs'])
@if (! count($extra['jobs']))
    <p class="muted note">{{ __('edu_website::public.no_jobs', [], $locale) }}</p>
@else
    <div class="jobs">
        @foreach ($extra['jobs'] as $job)
            <a class="job" href="{{ $job['url'] }}?lang={{ $locale }}">
                <span>
                    <b>{{ $text($job['title']) }}</b>
                    @if ($job['closes_on'])
                        <span class="muted"> · {{ __('edu_website::public.apply_by', ['date' => $date($job['closes_on'])], $locale) }}</span>
                    @endif
                </span>
                <span class="apply">{{ __('edu_website::public.apply', [], $locale) }} @include('edu_website::icon', ['name' => 'arrow'])</span>
            </a>
        @endforeach
    </div>
    @if ($extra['url'])
        <p class="more"><a href="{{ $extra['url'] }}?lang={{ $locale }}">{{ __('edu_website::public.all_jobs', [], $locale) }} @include('edu_website::icon', ['name' => 'arrow'])</a></p>
    @endif
@endif
