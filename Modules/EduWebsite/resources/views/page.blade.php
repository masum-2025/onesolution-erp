@extends('edu_website::layout', ['pageTitle' => $page['is_home'] ? '' : $text($page['title'])])

@section('content')
    @foreach ($blocks as $index => $block)
        <section class="block block-{{ $block['type'] }} {{ $index > 0 ? 'reveal' : '' }}">
            @if (in_array($block['type'], ['hero', 'slider'], true))
                @include('edu_website::blocks.'.$block['type'], ['data' => $block['data'], 'extra' => $block['extra'], 'first' => $index === 0, 'key' => $index])
            @else
                <div class="wrap">
                    @include('edu_website::blocks.'.$block['type'], ['data' => $block['data'], 'extra' => $block['extra']])
                </div>
            @endif
        </section>
    @endforeach
@endsection
