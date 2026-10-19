@extends('edu_website::layout', ['pageTitle' => $text($notice['title'])])

@section('content')
    <section class="block">
        <article class="wrap">
            <div class="board">
                <div class="board-head">@include('edu_website::icon', ['name' => 'calendar']) <time datetime="{{ $notice['date'] }}">{{ $date($notice['date']) }}</time></div>
                <div class="notice-body">
                    <h1>{{ $text($notice['title']) }}</h1>
                    @if ($text($notice['body']) !== '')
                        <div class="prose">{{ $text($notice['body']) }}</div>
                    @endif
                    @if ($notice['file'])
                        <p><a class="btn" href="{{ $notice['file'] }}">@include('edu_website::icon', ['name' => 'file']) {{ __('edu_website::public.open_file', [], $locale) }}</a></p>
                    @endif
                </div>
            </div>
            <p class="more"><a href="{{ $base }}/notices{{ $query ?? '' }}">{{ __('edu_website::public.all_notices', [], $locale) }} @include('edu_website::icon', ['name' => 'arrow'])</a></p>
        </article>
    </section>
@endsection
