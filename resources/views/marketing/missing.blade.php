@extends('marketing.layout')

@section('content')
    <section class="miss wrap">
        <h1 class="h1">{{ $ui('not_found') }}</h1>
        <p class="lead mt">{{ $ui('not_found_text') }}</p>
        <div class="cta"><a class="btn btn-p" href="{{ $home }}">{{ $ui('go_home') }}</a></div>
    </section>
@endsection
