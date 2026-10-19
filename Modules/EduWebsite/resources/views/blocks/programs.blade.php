@include('edu_website::blocks.title', ['eyebrow' => 'programs'])
<div class="cards programs">
    @if (($data['source'] ?? 'manual') === 'education')
        @forelse ($extra['programs'] as $program)
            <div class="card">
                <span class="bubble" aria-hidden="true"></span>
                <span class="tile">@include('edu_website::icon', ['name' => 'book'])</span>
                <h3>{{ $text($program['name']) }}</h3>
                <div class="chips">
                    @foreach ($program['levels'] as $level)
                        @if ($text($level) !== '')
                            <span>{{ $text($level) }}</span>
                        @endif
                    @endforeach
                </div>
            </div>
        @empty
            <p class="muted">{{ __('edu_website::public.no_programs', [], $locale) }}</p>
        @endforelse
    @else
        @foreach ($data['items'] ?? [] as $item)
            <div class="card">
                <span class="bubble" aria-hidden="true"></span>
                <span class="tile">@include('edu_website::icon', ['name' => 'book'])</span>
                <h3>{{ $text($item['name'] ?? null) }}</h3>
                @if ($text($item['text'] ?? null) !== '')
                    <p class="muted">{{ $text($item['text'] ?? null) }}</p>
                @endif
            </div>
        @endforeach
    @endif
</div>
