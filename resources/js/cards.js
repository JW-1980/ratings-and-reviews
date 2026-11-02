/**
 * Turns stored product-card references (`<a data-rr-card="type:id">`) in rendered HTML into
 * cards with image, price and score from `GET cards/{type}/{id}`. Built with DOM APIs and
 * textContent only; a card that cannot be loaded stays the plain link or text it was.
 */

import { CARD_PATTERN } from './sanitize.js';
import { formatMoney, formatScore } from './format.js';

/**
 * @param {ParentNode} container
 * @param {import('./api.js').ApiClient} api
 * @param {{ locale?: string, t?: (key: string, replace?: object) => string, cache?: Map<string, Promise<unknown>> }} [options]
 */
export async function hydrateCards(container, api, options = {}) {
    const cache = options.cache ?? new Map();
    const anchors = [...container.querySelectorAll('a[data-rr-card]:not([data-rr-card-ready])')];

    await Promise.all(anchors.map(async (anchor) => {
        const reference = anchor.getAttribute('data-rr-card') ?? '';

        anchor.setAttribute('data-rr-card-ready', '');

        if (!CARD_PATTERN.test(reference)) {
            return;
        }

        const [type, id] = reference.split(':');

        if (!cache.has(reference)) {
            cache.set(reference, api.card(type, id).catch(() => null));
        }

        const card = await cache.get(reference);

        if (!card) {
            return;
        }

        anchor.classList.add('rr-card');

        if (card.url && !anchor.getAttribute('href')) {
            anchor.setAttribute('href', card.url);
        }

        anchor.replaceChildren();

        if (card.imageUrl) {
            const image = document.createElement('img');
            image.src = card.imageUrl;
            image.alt = '';
            image.loading = 'lazy';
            image.className = 'rr-card__image';
            anchor.append(image);
        }

        const body = document.createElement('span');
        body.className = 'rr-card__body';
        const title = document.createElement('span');
        title.className = 'rr-card__title';
        title.textContent = card.title;
        body.append(title);

        const meta = [];

        if (card.price) {
            meta.push(formatMoney(card.price, options.locale));
        }

        if (card.score !== null && card.scaleMax) {
            meta.push(options.t ? options.t('card.score', { score: formatScore(card.score, options.locale), max: card.scaleMax }) : `${formatScore(card.score, options.locale)}/${card.scaleMax}`);
        }

        if (meta.length) {
            const line = document.createElement('span');
            line.className = 'rr-card__meta';
            line.textContent = meta.join(' · ');
            body.append(line);
        }

        anchor.append(body);
    }));
}
