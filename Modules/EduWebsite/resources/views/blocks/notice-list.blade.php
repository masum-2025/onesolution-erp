@if (! count($notices))
    <p class="muted note">{{ __('edu_website::public.no_notices', [], $locale) }}</p>
@else
    <div class="board">
        <div class="board-head">@include('edu_website::icon', ['name' => 'bell']) {{ __('edu_website::public.notice_board', [], $locale) }}</div>
        <ul class="plain">
            @foreach ($notices as $notice)
                <li class="notice {{ $notice['pinned'] ? 'pinned' : '' }}">
                    <time class="date" datetime="{{ $notice['date'] }}"><b>{{ $date($notice['date'], 'D') }}</b><span>{{ $date($notice['date'], 'MMM') }}</span></time>
                    <div>
                        <a href="{{ $notice['url'] }}{{ $query ?? '' }}">{{ $text($notice['title']) }}</a>
                        @if ($notice['fresh'] ?? false)
                            <span class="new">{{ __('edu_website::public.new', [], $locale) }}</span>
                        @endif
                        @if ($notice['pinned'])
                            <span class="pin">{{ __('edu_website::public.pinned', [], $locale) }}</span>
                        @endif
                        @if ($notice['file'])
                            <div class="muted"><a href="{{ $notice['file'] }}">@include('edu_website::icon', ['name' => 'file']) {{ __('edu_website::public.open_file', [], $locale) }}</a></div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
@endif
