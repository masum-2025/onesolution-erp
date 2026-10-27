@extends('recruitment::careers.layout', ['title' => $job['title'].' · '.$company])

@php
    $old = fn (string $field) => $form['old'][$field] ?? '';
    $error = fn (string $field) => $form['errors'][$field][0] ?? null;
@endphp

@section('content')
    <section class="hero">
        <div class="wrap">
            <p><a href="/careers/{{ $slug }}?lang={{ $locale }}">{{ $ui('all_jobs') }}</a></p>
            <h1>{{ $job['title'] }}</h1>
            <p class="meta">
                @if ($job['unit'] !== '')<span>{{ $job['unit'] }}</span>@endif
                @if ($job['vacancies'] > 1)<span>{{ $ui('vacancies', ['count' => $job['vacancies']]) }}</span>@endif
                @if ($job['salary'])<span>{{ $job['salary'] }}</span>@endif
                @if ($job['closes_on'])<span>{{ $ui('closes', ['date' => $job['closes_on']]) }}</span>@endif
            </p>
        </div>
    </section>
    <main class="wrap">
        <div class="lift card">
            @if ($job['description'] !== '')
                <div class="text">{{ $job['description'] }}</div>
            @else
                <p class="muted">{{ $ui('no_description') }}</p>
            @endif
        </div>

        <h2 class="section" id="apply">{{ $ui('apply_heading') }}</h2>
        <div class="card">
            @if ($form['applied'])
                <div class="done" role="status">
                    <div class="tick" aria-hidden="true">✓</div>
                    <p><strong>{{ $ui('check_mail') }}</strong></p>
                    <p class="muted">{{ $ui('check_mail_text') }}</p>
                </div>
            @else
                <form method="post" action="/careers/{{ $slug }}/jobs/{{ $job['id'] }}/apply" enctype="multipart/form-data" novalidate>
                    @if (! empty($form['errors']['form']))
                        <p class="alert" role="alert">{{ $form['errors']['form'][0] }}</p>
                    @endif
                    <input type="hidden" name="t" value="{{ $form['token'] }}">
                    <input type="hidden" name="lang" value="{{ $locale }}">
                    {{-- Left empty by people (hidden from them); robots fill it. --}}
                    <div class="hp" aria-hidden="true">
                        <label for="apply-website">Website</label>
                        <input id="apply-website" type="text" name="website" tabindex="-1" autocomplete="off">
                    </div>
                    <div class="fields">
                        <label class="field">
                            <span>{{ $ui('name') }}</span>
                            <input name="name" value="{{ $old('name') }}" maxlength="150" required autocomplete="name" @if ($error('name')) aria-invalid="true" @endif>
                            @if ($error('name'))<small class="err">{{ $error('name') }}</small>@endif
                        </label>
                        <label class="field">
                            <span>{{ $ui('email') }}</span>
                            <input name="email" type="email" value="{{ $old('email') }}" maxlength="190" required autocomplete="email" dir="ltr" @if ($error('email')) aria-invalid="true" @endif>
                            @if ($error('email'))<small class="err">{{ $error('email') }}</small>@endif
                        </label>
                        <label class="field">
                            <span>{{ $ui('phone') }} <em>{{ $ui('optional') }}</em></span>
                            <input name="phone" value="{{ $old('phone') }}" maxlength="30" inputmode="tel" autocomplete="tel" dir="ltr" @if ($error('phone')) aria-invalid="true" @endif>
                            @if ($error('phone'))<small class="err">{{ $error('phone') }}</small>@endif
                        </label>
                        <label class="field">
                            <span>{{ $ui('cv') }}</span>
                            <input name="cv" type="file" required accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" @if ($error('cv')) aria-invalid="true" @endif>
                            <small class="muted">{{ $ui('cv_hint', ['kb' => $form['max_kb']]) }}</small>
                            @if ($error('cv'))<small class="err">{{ $error('cv') }}</small>@endif
                        </label>
                        <label class="field wide">
                            <span>{{ $ui('cover_note') }} <em>{{ $ui('optional') }}</em></span>
                            <textarea name="cover_note" rows="4" maxlength="3000">{{ $old('cover_note') }}</textarea>
                            @if ($error('cover_note'))<small class="err">{{ $error('cover_note') }}</small>@endif
                        </label>
                        <label class="consent wide">
                            <input type="checkbox" name="consent" value="1" required @if ($error('consent')) aria-invalid="true" @endif>
                            <span>{{ $ui('consent', ['company' => $company]) }} @if ($error('consent'))<small class="err">{{ $error('consent') }}</small>@endif</span>
                        </label>
                    </div>
                    <p class="actions"><button class="btn" type="submit">{{ $ui('send') }}</button></p>
                    <p class="muted">{{ $ui('privacy') }}</p>
                </form>
            @endif
        </div>
    </main>
@endsection
