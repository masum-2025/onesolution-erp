@include('edu_website::blocks.title', ['eyebrow' => 'contact'])
<div class="cards contact">
    @if ($contact['address'] !== '')
        <div class="card">
            <span class="tile">@include('edu_website::icon', ['name' => 'pin'])</span>
            <div><h3>{{ __('edu_website::public.address', [], $locale) }}</h3><div class="muted lines">{{ $contact['address'] }}</div></div>
        </div>
    @endif
    @if ($contact['phone'] || $contact['email'])
        <div class="card">
            <span class="tile">@include('edu_website::icon', ['name' => 'phone'])</span>
            <div>
                <h3>{{ __('edu_website::public.call_or_write', [], $locale) }}</h3>
                @if ($contact['phone'])
                    <div><a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['phone']) }}">{{ $contact['phone'] }}</a></div>
                @endif
                @if ($contact['email'])
                    <div><a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a></div>
                @endif
            </div>
        </div>
    @endif
    @if ($contact['hours'] !== '')
        <div class="card">
            <span class="tile">@include('edu_website::icon', ['name' => 'clock'])</span>
            <div><h3>{{ __('edu_website::public.office_hours', [], $locale) }}</h3><div class="muted">{{ $contact['hours'] }}</div></div>
        </div>
    @endif
    @if (($data['show_map'] ?? false) && $contact['map_url'])
        <div class="card">
            <span class="tile">@include('edu_website::icon', ['name' => 'map'])</span>
            <div><h3>{{ __('edu_website::public.find_us', [], $locale) }}</h3><a href="{{ $contact['map_url'] }}" rel="noopener" target="_blank">{{ __('edu_website::public.open_map', [], $locale) }}</a></div>
        </div>
    @endif
</div>
