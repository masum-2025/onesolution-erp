@php
    $ui = fn (string $key, array $replace = []) => __("edu_website::public.{$key}", $replace, $locale);
    $state = $enquiry ?? [];
    $old = fn (string $field) => $state['old'][$field] ?? '';
    $error = fn (string $field) => $state['errors'][$field][0] ?? null;
    // A preview carries a signature; its form is shown but sends nothing (it works on the published site).
    $preview = ! empty($query);
@endphp
<div class="enquiry" id="enquiry">
    @include('edu_website::blocks.title', ['eyebrow' => 'admission'])
    @if ($text($data['text'] ?? null) !== '')
        <p class="prose enquiry-text">{{ $text($data['text']) }}</p>
    @endif

    @if ($state['sent'] ?? false)
        <div class="enquiry-done" role="status">
            <span class="tile">@include('edu_website::icon', ['name' => 'check'])</span>
            <h3>{{ $ui('enquiry_sent') }}</h3>
            <p>{{ $text($data['thanks'] ?? null) !== '' ? $text($data['thanks']) : $ui('enquiry_thanks') }}</p>
        </div>
    @else
        <form class="enquiry-form" method="post" action="{{ $base }}/enquiry" novalidate>
            @if (! empty($state['errors']['form']))
                <p class="enquiry-alert" role="alert">{{ $state['errors']['form'][0] }}</p>
            @endif
            <input type="hidden" name="t" value="{{ $extra['token'] }}">
            <input type="hidden" name="back" value="{{ $page['is_home'] ?? true ? '' : $page['slug'] }}">
            <input type="hidden" name="lang" value="{{ $locale }}">
            {{-- Left empty by people (hidden from them); robots fill it. --}}
            <div class="hp" aria-hidden="true">
                <label for="enquiry-website">Website</label>
                <input id="enquiry-website" type="text" name="website" tabindex="-1" autocomplete="off">
            </div>
            <div class="fields">
                <label class="field">
                    <span>{{ $ui('enquiry_student') }}</span>
                    <input name="student_name" value="{{ $old('student_name') }}" maxlength="100" required autocomplete="off" @if ($error('student_name')) aria-invalid="true" @endif>
                    @if ($error('student_name'))<small class="err">{{ $error('student_name') }}</small>@endif
                </label>
                <label class="field">
                    <span>{{ $ui('enquiry_guardian') }}</span>
                    <input name="guardian_name" value="{{ $old('guardian_name') }}" maxlength="100" required autocomplete="name" @if ($error('guardian_name')) aria-invalid="true" @endif>
                    @if ($error('guardian_name'))<small class="err">{{ $error('guardian_name') }}</small>@endif
                </label>
                <label class="field">
                    <span>{{ $ui('enquiry_phone') }}</span>
                    <input name="phone" value="{{ $old('phone') }}" maxlength="30" required inputmode="tel" autocomplete="tel" dir="ltr" @if ($error('phone')) aria-invalid="true" @endif>
                    @if ($error('phone'))<small class="err">{{ $error('phone') }}</small>@endif
                </label>
                @if (count($extra['levels'] ?? []))
                    <label class="field">
                        <span>{{ $ui('enquiry_level') }}</span>
                        <select name="level_id" @if ($error('level_id')) aria-invalid="true" @endif>
                            <option value="">{{ $ui('enquiry_level_any') }}</option>
                            @foreach ($extra['levels'] as $level)
                                <option value="{{ $level['id'] }}" @selected($old('level_id') === $level['id'])>{{ $text($level['name']) }}</option>
                            @endforeach
                        </select>
                        @if ($error('level_id'))<small class="err">{{ $error('level_id') }}</small>@endif
                    </label>
                @endif
                <label class="field wide">
                    <span>{{ $ui('enquiry_message') }} <em>{{ $ui('optional') }}</em></span>
                    <textarea name="message" rows="3" maxlength="1000">{{ $old('message') }}</textarea>
                    @if ($error('message'))<small class="err">{{ $error('message') }}</small>@endif
                </label>
            </div>
            <div class="enquiry-actions">
                <button class="btn" type="submit" @disabled($preview)>{{ $ui('enquiry_send') }} @include('edu_website::icon', ['name' => 'arrow'])</button>
                <small class="muted">{{ $preview ? $ui('enquiry_preview') : $ui('enquiry_privacy') }}</small>
            </div>
        </form>
    @endif
</div>
