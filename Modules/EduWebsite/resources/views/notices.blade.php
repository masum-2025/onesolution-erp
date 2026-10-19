@extends('edu_website::layout', ['pageTitle' => __('edu_website::public.notices', [], $locale)])

@section('content')
    <section class="block">
        <div class="wrap">
            <div class="head">
                <span class="eyebrow">{{ __('edu_website::public.eyebrow_notices', [], $locale) }}</span>
                <h1 class="title">{{ __('edu_website::public.notices', [], $locale) }}</h1>
                <span class="line" aria-hidden="true"></span>
            </div>
            @include('edu_website::blocks.notice-list', ['notices' => $notices])
        </div>
    </section>
@endsection
