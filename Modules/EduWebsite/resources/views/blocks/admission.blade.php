@include('edu_website::blocks.title', ['eyebrow' => 'admission'])
<div class="admit">
    <div class="prose">{{ $text($data['body'] ?? null) }}</div>
    @if (! empty($data['deadline']))
        <div class="deadline">@include('edu_website::icon', ['name' => 'calendar']) {{ __('edu_website::public.deadline', [], $locale) }}: {{ $date($data['deadline']) }}</div>
    @endif
    @if (! empty($data['button_url']) && $text($data['button_label'] ?? null) !== '')
        <p class="more"><a class="btn" href="{{ $data['button_url'] }}">{{ $text($data['button_label'] ?? null) }} @include('edu_website::icon', ['name' => 'arrow'])</a></p>
    @endif
</div>
