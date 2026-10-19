@extends('edu_website::layout', ['pageTitle' => $page['is_home'] ? '' : $text($page['title'])])

@section('content')
    @foreach ($blocks as $block)
        <section class="block block-{{ $block['type'] }}">
            @if ($block['type'] === 'hero')
                @include('edu_website::blocks.hero', ['data' => $block['data'], 'extra' => $block['extra']])
            @else
                <div class="wrap">
                    @include('edu_website::blocks.'.$block['type'], ['data' => $block['data'], 'extra' => $block['extra']])
                </div>
            @endif
        </section>
    @endforeach
@endsection
