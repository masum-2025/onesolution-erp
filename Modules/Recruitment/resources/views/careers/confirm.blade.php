@extends('recruitment::careers.layout', ['robots' => 'noindex, nofollow'])

@section('content')
    <section class="hero">
        <div class="wrap">
            <h1>{{ $ui('confirm_heading') }}</h1>
        </div>
    </section>
    <main class="wrap">
        <div class="lift card done">
            @if ($state === 'ask')
                <p>{{ $ui('confirm_text') }}</p>
                <form method="post" action="/careers/{{ $slug }}/confirm/{{ $token }}">
                    <input type="hidden" name="lang" value="{{ $locale }}">
                    <button class="btn" type="submit">{{ $ui('confirm_button') }}</button>
                </form>
            @elseif ($state === 'done')
                <div class="tick" aria-hidden="true">✓</div>
                <p role="status"><strong>{{ $ui('confirmed') }}</strong></p>
                <p class="muted">{{ $ui('confirmed_text', ['company' => $company]) }}</p>
            @else
                <p role="alert"><strong>{{ $ui('link_expired') }}</strong></p>
                <p class="muted">{{ $ui('link_expired_text') }}</p>
                <p><a class="btn" href="/careers/{{ $slug }}?lang={{ $locale }}">{{ $ui('all_jobs') }}</a></p>
            @endif
        </div>
    </main>
@endsection
