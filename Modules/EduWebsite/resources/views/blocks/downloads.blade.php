@include('edu_website::blocks.title', ['eyebrow' => 'downloads'])
<ul class="files plain">
    @foreach ($data['media_ids'] ?? [] as $id)
        @if (isset($extra['media'][$id]))
            <li>
                <a href="{{ $extra['media'][$id]['kind'] === 'pdf' ? $extra['media'][$id]['file'] : $extra['media'][$id]['large'] }}">
                    <span class="tile">@include('edu_website::icon', ['name' => 'file'])</span>
                    <span>{{ $extra['media'][$id]['name'] }} <span class="muted">({{ strtoupper($extra['media'][$id]['kind']) }})</span></span>
                </a>
            </li>
        @endif
    @endforeach
</ul>
