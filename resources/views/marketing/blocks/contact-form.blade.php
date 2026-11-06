{{-- The contact form (MKT-3b). Without script: the site answers the post with this page again,
     the form replaced by a thank-you, or with the visitor's answers and what to fix. --}}
@php($mine = ($form['block'] ?? null) === $block['id'])
@php($state = $mine ? $form['state'] : null)
@php($old = $mine ? ($form['old'] ?? []) : [])
@php($errors = $mine ? ($form['errors'] ?? []) : [])
<section class="sec" id="write-to-us">
    <div class="wrap">
        @include('marketing.parts.head')
        <div @class(['lead-grid', 'lead-solo' => ! $d['show_contact']])>
            @if ($state === 'sent')
                <div class="lead-form lead-done" role="status">
                    <h3>{{ $ui('form.sent_title') }}</h3>
                    <p>{{ $tx($d['thanks'] ?? null) ?: $ui('form.sent_text') }}</p>
                </div>
            @else
                <form class="lead-form" method="post" action="{{ $form_action ?? '#' }}" novalidate>
                    @if ($state !== null && $state !== 'invalid')<p class="lead-note bad" role="alert">{{ $ui('form.'.$state) }}</p>@endif
                    @if ($state === 'invalid')<p class="lead-note bad" role="alert">{{ $ui('form.check') }}</p>@endif
                    @if ($preview)<p class="lead-note">{{ $ui('form.preview') }}</p>@endif
                    <input type="hidden" name="block" value="{{ $block['id'] }}">
                    <input type="hidden" name="page" value="{{ $page['slug'] ?? '' }}">
                    <input type="hidden" name="lang" value="{{ $locale }}">
                    <input type="hidden" name="stamp" value="{{ $form_stamp }}">
                    {{-- People never see this one; a bot fills it in. --}}
                    <div class="trap" aria-hidden="true"><label>{{ $ui('form.trap') }}<input type="text" name="{{ \App\Platform\Marketing\Services\Leads::TRAP }}" tabindex="-1" autocomplete="off"></label></div>
                    @php($fields = array_filter([
                        'name' => ['type' => 'text', 'auto' => 'name', 'required' => true],
                        'phone' => ['type' => 'tel', 'auto' => 'tel', 'required' => true],
                        'email' => $d['ask_email'] ? ['type' => 'email', 'auto' => 'email', 'required' => false] : null,
                        'company' => $d['ask_company'] ? ['type' => 'text', 'auto' => 'organization', 'required' => false] : null,
                    ]))
                    @foreach ($fields as $name => $field)
                        <label class="lf">
                            <span>{{ $ui('form.'.$name) }} @unless ($field['required'])<small>({{ $ui('form.optional') }})</small>@endunless</span>
                            <input type="{{ $field['type'] }}" name="{{ $name }}" value="{{ $old[$name] ?? '' }}" autocomplete="{{ $field['auto'] }}" @if ($field['required']) required @endif @if (isset($errors[$name])) aria-invalid="true" aria-describedby="lf-{{ $name }}" @endif @if ($name === 'phone') dir="ltr" inputmode="tel" @endif maxlength="{{ $name === 'phone' ? 25 : 150 }}">
                            @isset($errors[$name])<em id="lf-{{ $name }}">{{ $errors[$name] }}</em>@endisset
                        </label>
                    @endforeach
                    @if ($d['ask_message'])
                        <label class="lf">
                            <span>{{ $ui('form.message') }} <small>({{ $ui('form.optional') }})</small></span>
                            <textarea name="message" rows="4" maxlength="2000" @if (isset($errors['message'])) aria-invalid="true" aria-describedby="lf-message" @endif>{{ $old['message'] ?? '' }}</textarea>
                            @isset($errors['message'])<em id="lf-message">{{ $errors['message'] }}</em>@endisset
                        </label>
                    @endif
                    <button class="btn btn-p" type="submit" @disabled($preview)>{{ $tx($d['button_label'] ?? null) ?: $ui('form.send') }} →</button>
                </form>
            @endif
            @if ($d['show_contact'])
                <ul class="lead-info">
                    @if (filled($contact['phone'] ?? null))<li><b>{{ $ui('form.call') }}</b><a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['phone']) }}" dir="ltr">{{ $contact['phone'] }}</a></li>@endif
                    @if (filled($contact['email'] ?? null))<li><b>{{ $ui('form.write') }}</b><a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a></li>@endif
                    @if ($tx($contact['address'] ?? null) !== '')<li><b>{{ $ui('form.visit') }}</b><span>{{ $tx($contact['address']) }}</span></li>@endif
                </ul>
            @endif
        </div>
    </div>
</section>
