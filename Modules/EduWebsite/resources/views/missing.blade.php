@extends('edu_website::layout', ['pageTitle' => __('edu_website::public.missing', [], $locale)])

@section('content')
    <section class="block">
        <div class="wrap head">
            <h1 class="title">{{ __('edu_website::public.missing', [], $locale) }}</h1>
            <p class="more"><a class="btn" href="{{ ($base === '' ? '/' : $base).($query ?? '') }}">{{ __('edu_website::public.go_home', [], $locale) }}</a></p>
        </div>
    </section>
@endsection
