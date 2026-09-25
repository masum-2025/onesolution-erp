import { nextTick, onBeforeUnmount, watch } from 'vue';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

let openCount = 0;

/**
 * Accessible modal behaviour for dialogs and drawers: focus moves inside
 * (to [data-autofocus] when present), Tab stays inside, Escape closes,
 * focus returns to the opener, and the page behind does not scroll.
 */
export function useModal(isOpen, panel, close) {
    let opener = null;

    function focusables() {
        return [...(panel.value?.querySelectorAll(FOCUSABLE) ?? [])].filter((el) => el.offsetParent !== null || el === document.activeElement);
    }

    function onKeydown(event) {
        if (event.key === 'Escape') {
            event.stopPropagation();
            close();
            return;
        }
        if (event.key !== 'Tab') return;

        const items = focusables();
        if (items.length === 0) {
            event.preventDefault();
            return;
        }
        const first = items[0];
        const last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function lock(on) {
        openCount = Math.max(0, openCount + (on ? 1 : -1));
        document.body.style.overflow = openCount > 0 ? 'hidden' : '';
    }

    watch(
        isOpen,
        async (open, wasOpen) => {
            if (open) {
                opener = document.activeElement;
                lock(true);
                await nextTick();
                // The panel itself unless a field asks for focus: no stray focus ring on open,
                // and screen readers start at the dialog's title.
                const target = panel.value?.querySelector('[data-autofocus]') ?? panel.value;
                target?.focus({ preventScroll: true });
            } else if (wasOpen) {
                lock(false);
                opener?.focus?.({ preventScroll: true });
            }
        },
        { immediate: true },
    );

    onBeforeUnmount(() => {
        if (isOpen.value) lock(false);
    });

    return { onKeydown };
}
