@extends('recruitment::careers.layout', ['robots' => 'noindex, nofollow', 'title' => $ui('offer_title', ['company' => $company])])

@section('content')
    <section class="hero">
        <div class="wrap">
            <h1>{{ $ui('offer_heading', ['company' => $company]) }}</h1>
        </div>
    </section>
    <main class="wrap">
        <div class="lift card">
            <div class="text">{{ $offer['letter'] }}</div>
        </div>

        <h2 class="section" id="answer">{{ $ui('offer_answer') }}</h2>
        <div class="card done">
            @if ($offer['status'] === 'open')
                <p>{{ $ui('offer_question') }}</p>
                <form method="post" action="/careers/{{ $slug }}/offer/{{ $token }}" class="choices">
                    <input type="hidden" name="lang" value="{{ $locale }}">
                    <button class="btn" type="submit" name="answer" value="accept">{{ $ui('offer_accept') }}</button>
                    <button class="btn ghost" type="submit" name="answer" value="decline">{{ $ui('offer_decline') }}</button>
                </form>
                <p class="muted">{{ $ui('offer_note') }}</p>
            @elseif ($offer['status'] === 'accepted')
                <div class="tick" aria-hidden="true">✓</div>
                <p role="status"><strong>{{ $ui('offer_accepted') }}</strong></p>
                <p class="muted">{{ $ui('offer_accepted_text', ['company' => $company]) }}</p>
            @elseif ($offer['status'] === 'declined')
                <p role="status"><strong>{{ $ui('offer_declined') }}</strong></p>
                <p class="muted">{{ $ui('offer_declined_text') }}</p>
            @else
                <p role="alert"><strong>{{ $ui('offer_closed') }}</strong></p>
                <p class="muted">{{ $ui('offer_closed_text', ['company' => $company]) }}</p>
            @endif
        </div>
    </main>
@endsection
