/**
 * Small DOM helpers for the Blade enhancement. Text is always set with textContent; the
 * only HTML that is inserted comes from the server's own rendering or passes DOMPurify.
 */

/** @returns {HTMLElement} */
export function el(tag, attributes = {}, children = []) {
    const element = document.createElement(tag);

    for (const [name, value] of Object.entries(attributes)) {
        if (value === null || value === undefined || value === false) {
            continue;
        }

        if (name === 'class') {
            element.className = value;
        } else if (name === 'text') {
            element.textContent = value;
        } else if (name.startsWith('on') && typeof value === 'function') {
            element.addEventListener(name.slice(2), value);
        } else {
            element.setAttribute(name, value === true ? '' : String(value));
        }
    }

    for (const child of [].concat(children)) {
        if (child !== null && child !== undefined && child !== false) {
            element.append(child);
        }
    }

    return element;
}

export function readJson(selector, fallback = null, scope = document) {
    const node = scope.querySelector(selector);

    if (!node) {
        return fallback;
    }

    try {
        return JSON.parse(node.textContent || 'null') ?? fallback;
    } catch {
        return fallback;
    }
}

let uid = 0;

export function uniqueId(prefix = 'rr') {
    uid += 1;

    return `${prefix}-${Date.now().toString(36)}-${uid}`;
}

export function prefersReducedMotion() {
    return typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** Scrolls to and focuses an element (comments carry tabindex="-1"). */
export function focusTarget(element) {
    if (!element) {
        return;
    }

    element.scrollIntoView?.({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' });
    element.focus?.({ preventScroll: true });
}

/** Announces text in the component's polite live region. */
export function announce(root, text) {
    const region = root?.querySelector('[data-rr-live]') ?? document.querySelector('[data-rr-live]');

    if (!region || !text) {
        return;
    }

    region.textContent = '';
    requestAnimationFrame(() => {
        region.textContent = text;
    });
}

export function setBusy(button, busy) {
    if (!button) {
        return;
    }

    if (busy) {
        button.dataset.rrLabel = button.textContent;
        button.textContent = button.dataset.busyLabel || button.textContent;
        button.setAttribute('aria-busy', 'true');
        button.disabled = true;
    } else {
        if (button.dataset.rrLabel !== undefined) {
            button.textContent = button.dataset.rrLabel;
        }

        button.removeAttribute('aria-busy');
        button.disabled = false;
    }
}

/** Opens a native dialog and returns focus to the opener when it closes. */
export function openDialog(dialog) {
    const opener = document.activeElement;

    if (typeof dialog.showModal === 'function') {
        dialog.showModal();
    } else {
        dialog.setAttribute('open', '');
    }

    dialog.addEventListener('close', () => opener?.focus?.(), { once: true });
    dialog.querySelector('input, select, textarea, button:not([data-rr-dialog-close])')?.focus();
}

export function closeDialog(dialog) {
    if (typeof dialog.close === 'function' && dialog.open) {
        dialog.close();
    } else {
        dialog.removeAttribute('open');
        dialog.dispatchEvent(new Event('close'));
    }
}
