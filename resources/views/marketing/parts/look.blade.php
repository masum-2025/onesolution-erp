{{-- A look link's Customize panel: only for whoever holds the link. A plain GET form back to this page (no inline code); site.js sends it on every change. Nothing is saved. --}}
<details class="look" open>
    <summary>{{ $ui('look_button') }}</summary>
    <form class="look-form" method="get" action="{{ $look['action'] }}">
        <input type="hidden" name="look" value="{{ $look['token'] }}">
        <fieldset>
            <legend>{{ $ui('look_template') }}</legend>
            <div class="look-opts">
                @foreach ($look['templates'] as $value)
                    <label><input type="radio" name="template" value="{{ $value }}" @checked($look['template'] === $value)><span>{{ $ui('look_tpl_'.$value) }}</span></label>
                @endforeach
            </div>
        </fieldset>
        @if ($look['effect'] !== null)
            <fieldset>
                <legend>{{ $ui('look_effect') }}</legend>
                <div class="look-opts">
                    @foreach ($look['effects'] as $value)
                        <label><input type="radio" name="effect" value="{{ $value }}" @checked($look['effect'] === $value)><span>{{ $ui('look_fx_'.$value) }}</span></label>
                    @endforeach
                </div>
            </fieldset>
        @endif
        @if ($look['product'] !== null)
            <fieldset>
                <legend>{{ $ui('look_product') }}</legend>
                <div class="look-opts">
                    @foreach ($look['products'] as $value)
                        <label><input type="radio" name="product" value="{{ $value }}" @checked($look['product'] === $value)><span>{{ $ui('look_pr_'.$value) }}</span></label>
                    @endforeach
                </div>
            </fieldset>
        @endif
        <fieldset>
            <legend>{{ $ui('look_colors') }}</legend>
            <div class="look-sw">
                <label><input type="radio" name="palette" value="" @checked($look['palette'] === null)><span class="sw sw-own">{{ $ui('look_own') }}</span></label>
                @foreach ($look['palettes'] as $value)
                    <label><input type="radio" name="palette" value="{{ $value }}" @checked($look['palette'] === $value)><span class="sw sw{{ $value }}" aria-label="{{ $ui('look_palette', ['n' => $value + 1]) }}"></span></label>
                @endforeach
            </div>
        </fieldset>
        <button class="btn btn-p look-go" type="submit">{{ $ui('look_show') }}</button>
        <p class="look-note">{{ $ui('look_note') }}</p>
    </form>
</details>
