/**
 * "Quote and reply": when the reader selects text inside one comment body, offer to quote
 * it. The quote keeps the source comment id (`data-rr-quote`), so it links back. The server
 * accepts a quote only as an exact substring of the comment's plain text (whitespace
 * collapsed) of at most `discussions.max_quote_length` characters, so a longer selection is
 * cut, never altered.
 */

export const MAX_QUOTE_LENGTH = 500;

/**
 * The quotable selection, if it lies inside a single `[data-rr-quote-source="id"]` element.
 *
 * @param {Selection|null} selection
 * @param {number} [maxLength]
 * @returns {{ commentId: string, text: string, rect: DOMRect|null }|null}
 */
export function quotableSelection(selection, maxLength = MAX_QUOTE_LENGTH) {
    if (!selection || selection.isCollapsed || selection.rangeCount === 0) {
        return null;
    }

    const range = selection.getRangeAt(0);
    const startEl = range.startContainer.nodeType === 1 ? range.startContainer : range.startContainer.parentElement;
    const endEl = range.endContainer.nodeType === 1 ? range.endContainer : range.endContainer.parentElement;
    const source = startEl?.closest?.('[data-rr-quote-source]');

    if (!source || source !== endEl?.closest?.('[data-rr-quote-source]')) {
        return null;
    }

    let text = selection.toString().replace(/\s+/gu, ' ').trim();

    if (text === '') {
        return null;
    }

    if ([...text].length > maxLength) {
        text = [...text].slice(0, maxLength).join('').trim();
    }

    let rect = null;

    try {
        rect = typeof range.getBoundingClientRect === 'function' ? range.getBoundingClientRect() : null;
    } catch {
        rect = null;
    }

    return { commentId: source.getAttribute('data-rr-quote-source'), text, rect };
}
