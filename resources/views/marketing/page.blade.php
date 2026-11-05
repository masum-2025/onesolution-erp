@extends('marketing.layout')

@section('content')
    @foreach ($blocks as $block)
        @include('marketing.blocks.'.str_replace('_', '-', $block['type']), ['block' => $block, 'd' => $block['data']])
    @endforeach
@endsection
