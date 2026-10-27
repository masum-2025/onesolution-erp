@extends('recruitment::careers.layout')

@section('content')
    <section class="hero">
        <div class="wrap">
            <h1>{{ $ui('heading', ['company' => $company]) }}</h1>
            <p>{{ $ui('intro') }}</p>
        </div>
    </section>
    <main class="wrap">
        <div class="lift">
            @if (count($jobs) === 0)
                <div class="card done">
                    <p><strong>{{ $ui('no_jobs') }}</strong></p>
                    <p class="muted">{{ $ui('no_jobs_text') }}</p>
                </div>
            @else
                <ul class="jobs">
                    @foreach ($jobs as $job)
                        <li>
                            <a class="card job" href="/careers/{{ $slug }}/jobs/{{ $job['id'] }}?lang={{ $locale }}">
                                <span>
                                    <h2>{{ $job['title'] }}</h2>
                                    <span class="meta">
                                        @if ($job['unit'] !== '')<span>{{ $job['unit'] }}</span>@endif
                                        @if ($job['salary'])<span>{{ $job['salary'] }}</span>@endif
                                        @if ($job['closes_on'])<span>{{ $ui('closes', ['date' => $job['closes_on']]) }}</span>@endif
                                    </span>
                                </span>
                                <span class="chip">{{ $ui('view') }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </main>
@endsection
