@include('edu_website::blocks.title', ['eyebrow' => 'notices'])
@include('edu_website::blocks.notice-list', ['notices' => $extra['notices']])
@if (count($extra['notices']))
    <p class="more"><a href="{{ $base }}/notices{{ $query ?? '' }}">{{ __('edu_website::public.all_notices', [], $locale) }} @include('edu_website::icon', ['name' => 'arrow'])</a></p>
@endif
