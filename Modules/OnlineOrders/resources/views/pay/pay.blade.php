@extends('online_orders::pay.layout')
@section('title', $ui('title', ['number' => $number]))
@section('body')
    <h1>{{ $company }}</h1>
    <p class="muted">{{ $ui('order', ['number' => $number]) }}</p>

    @if ($outcome === 'paid')
        <p class="note ok" role="status">{{ $ui('paid') }}</p>
    @elseif ($outcome === 'checking')
        <p class="note warn" role="status">{{ $ui('checking') }}</p>
    @elseif ($outcome === 'failed')
        <p class="note bad" role="status">{{ $ui('failed') }}</p>
    @endif
    @if ($error)
        <p class="note bad" role="alert">{{ $error }}</p>
    @endif

    <dl>
        <dt>{{ $ui('total') }}</dt><dd>{{ $total }}</dd>
        @if ($paid)<dt>{{ $ui('already_paid') }}</dt><dd>{{ $paid }}</dd>@endif
        @if ($amount)
            <dt class="now">{{ $ui('pay_now') }}</dt><dd class="now">{{ $amount }}</dd>
            <dt>{{ $ui('on_delivery') }}</dt><dd>{{ $rest }}</dd>
        @endif
    </dl>

    @if ($amount)
        <form method="post" action="{{ $action }}">
            <button type="submit">{{ $ui('button', ['amount' => $amount]) }}</button>
        </form>
    @elseif ($outcome !== 'paid')
        <p class="muted">{{ $ui('nothing') }}</p>
    @endif
@endsection
