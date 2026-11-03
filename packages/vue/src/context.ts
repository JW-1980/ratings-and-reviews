import { inject, markRaw, shallowRef, type App, type Component, type InjectionKey, type Ref } from 'vue';
import { createApiClient, createTranslator, sanitizeHtml, type ApiClient, type ApiOptions } from './shared';
import type { CardExtensionItem, CardExtensionPosition, Option, ProductCard, Sticker, Translate, TranslateChoice } from './types';

/**
 * A host component rendered on every card at a position (props `item` and `position`), with an
 * optional `preload` that the list components call once per loaded page with all its items
 * (the first page, every further page, a page of replies, a newly posted comment), so the
 * host fills its own store in one request instead of one per card. Its return value is not
 * passed on: the component reads the host's store.
 */
export interface CardExtensionDefinition<P extends CardExtensionPosition = CardExtensionPosition> {
    component: Component;
    preload?: (items: Array<CardExtensionItem<P>>, position: P) => unknown;
}

export type CardExtension<P extends CardExtensionPosition = CardExtensionPosition> = Component | CardExtensionDefinition<P>;

export type CardExtensionOptions = { [P in CardExtensionPosition]?: CardExtension<P> | Array<CardExtension<P>> };

export type CardExtensionRegistry = { [P in CardExtensionPosition]: Array<CardExtensionDefinition<P>> };

export const CARD_EXTENSION_POSITIONS: readonly CardExtensionPosition[] = Object.freeze(['review.meta', 'review.footer', 'comment.meta', 'comment.footer']);

function isDefinition(value: unknown): value is CardExtensionDefinition {
    return typeof value === 'object' && value !== null && 'component' in value && !('setup' in value) && !('render' in value) && !('template' in value);
}

/** Normalises the plugin option to a list per position; an unknown position is an error. */
export function normalizeCardExtensions(options: CardExtensionOptions | undefined): CardExtensionRegistry {
    const registry = Object.fromEntries(CARD_EXTENSION_POSITIONS.map((position) => [position, []])) as unknown as CardExtensionRegistry;

    for (const [position, value] of Object.entries(options ?? {})) {
        if (!CARD_EXTENSION_POSITIONS.includes(position as CardExtensionPosition)) {
            throw new Error(`[ratings-and-reviews] Unknown card extension position "${position}"; use one of: ${CARD_EXTENSION_POSITIONS.join(', ')}.`);
        }

        const list = (Array.isArray(value) ? value : [value]).filter((entry) => entry !== null && entry !== undefined);
        (registry[position as CardExtensionPosition] as CardExtensionDefinition[]) = list.map((entry) => (isDefinition(entry)
            ? { component: markRaw(entry.component as object) as Component, preload: entry.preload as CardExtensionDefinition['preload'] }
            : { component: markRaw(entry as object) as Component }));
    }

    return registry;
}


export interface RatingsAndReviewsOptions {
    /** `trans('ratings-and-reviews::ui')` of the current locale, injected by the host. */
    messages: Record<string, unknown>;
    /** BCP 47 locale for number and date formatting, for example 'nl' or 'en'. */
    locale: string;
    /** An existing client, or the options to create one (baseUrl, csrfToken, headers…). */
    api?: ApiClient | ApiOptions;
    /** Whether a user is signed in (write actions are offered only then). */
    isAuthenticated?: boolean;
    /** Where "log in" links go. */
    loginUrl?: string | null;
    /** `[{ value, label }]` from config('ratings-and-reviews.reports.reasons') and the enum labels. */
    reportReasons?: Option[];
    /** Reasons that require details (config `reports.details_required_for`). */
    reportDetailsRequiredFor?: string[];
    /** URL prefixes of own uploads and stickers (config `uploads.url_prefix`, `stickers.url_prefix`). */
    mediaPrefixes?: string[];
    /** Image MIME types the host accepts (config `uploads.mime_types`), for file inputs. */
    uploadTypes?: string[];
    /** The active sticker library; without it the picker loads it from GET stickers when first opened. */
    stickers?: Sticker[];
    /** Emoji choices for the emoji tool. */
    emojis?: string[];
    /** Uploads an editor image and returns its URL. Default: the package's POST uploads; a host callback overrides it. */
    uploadImage?: (file: File) => Promise<{ url: string; alt?: string | null }>;
    /** Product search for the `#` card trigger; without it `#type:id` is resolved through the API. */
    searchCards?: (query: string) => Promise<ProductCard[]>;
    /**
     * Host components on every review or comment card: `{ 'review.footer': Reactions }` or
     * `{ 'comment.footer': [{ component: Reactions, preload: (comments) => store.load(comments) }] }`.
     * A card slot (`#footer`, `#meta`, or `#review-footer`/`#comment-footer` on the lists) replaces them on that card.
     */
    cardExtensions?: CardExtensionOptions;
}

export interface RatingsAndReviewsContext {
    api: ApiClient;
    t: Translate;
    tc: TranslateChoice;
    locale: string;
    isAuthenticated: boolean;
    loginUrl: string | null;
    reportReasons: Option[];
    reportDetailsRequiredFor: string[];
    mediaPrefixes: string[];
    uploadTypes: string[];
    stickers: Sticker[];
    /** The sticker library loaded from the API (empty until loadStickers() resolved; unused when `stickers` was given). */
    loadedStickers: Ref<Sticker[]>;
    /** Loads the active stickers from GET stickers once (resolves to `stickers` when the host gave them). */
    loadStickers: () => Promise<Sticker[]>;
    emojis: string[] | null;
    uploadImage: NonNullable<RatingsAndReviewsOptions['uploadImage']> | null;
    searchCards: RatingsAndReviewsOptions['searchCards'] | null;
    sanitize: (html: string, extraTags?: string[]) => string;
    /** The registered card extensions per position (always every position, possibly empty). */
    cardExtensions: CardExtensionRegistry;
}

export const RR_CONTEXT: InjectionKey<RatingsAndReviewsContext> = Symbol('ratings-and-reviews');

function isClient(value: unknown): value is ApiClient {
    return typeof value === 'object' && value !== null && typeof (value as ApiClient).request === 'function';
}

export function createContext(options: RatingsAndReviewsOptions): RatingsAndReviewsContext {
    const { t, tc } = createTranslator(options.messages ?? {});
    const api = isClient(options.api) ? options.api : createApiClient({ locale: options.locale, ...(options.api ?? {}) });
    const mediaPrefixes = options.mediaPrefixes ?? ['/storage/'];
    const uploadTypes = options.uploadTypes ?? ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    const loadedStickers = shallowRef<Sticker[]>([]);
    let pendingStickers: Promise<Sticker[]> | null = null;
    const loadStickers = (): Promise<Sticker[]> => {
        if (options.stickers) {
            return Promise.resolve(options.stickers);
        }

        pendingStickers ??= api.stickers().then((stickers) => {
            loadedStickers.value = stickers;

            return stickers;
        }, (failure: unknown) => {
            pendingStickers = null;
            throw failure;
        });

        return pendingStickers;
    };

    return {
        api,
        t,
        tc,
        locale: options.locale,
        isAuthenticated: options.isAuthenticated ?? false,
        loginUrl: options.loginUrl ?? null,
        reportReasons: options.reportReasons ?? [],
        reportDetailsRequiredFor: options.reportDetailsRequiredFor ?? ['other'],
        mediaPrefixes,
        uploadTypes,
        stickers: options.stickers ?? [],
        loadedStickers,
        loadStickers,
        emojis: options.emojis ?? null,
        uploadImage: options.uploadImage ?? ((file: File) => api.uploadImage(file)),
        searchCards: options.searchCards ?? null,
        sanitize: (html, extraTags) => sanitizeHtml(html, { mediaPrefixes, extraTags }),
        cardExtensions: normalizeCardExtensions(options.cardExtensions),
    };
}

/**
 * Vue plugin: `app.use(createRatingsAndReviews({ messages, locale, api: { baseUrl: '/rr' } }))`.
 */
export function createRatingsAndReviews(options: RatingsAndReviewsOptions) {
    const context = createContext(options);

    return {
        context,
        install(app: App) {
            app.provide(RR_CONTEXT, context);
        },
    };
}

export function useRr(): RatingsAndReviewsContext {
    const context = inject(RR_CONTEXT, null);

    if (context === null) {
        throw new Error('[ratings-and-reviews] Install the plugin first: app.use(createRatingsAndReviews({ … })).');
    }

    return context;
}

/**
 * Runs the `preload` hooks of the card extensions at the positions of one kind of card, once,
 * with the items of a freshly loaded page. A failing hook is logged and does not stop the list.
 */
export function preloadCardExtensions<P extends CardExtensionPosition>(context: RatingsAndReviewsContext, positions: readonly P[], items: Array<CardExtensionItem<P>>): void {
    if (items.length === 0) {
        return;
    }

    for (const position of positions) {
        for (const extension of context.cardExtensions[position] as Array<CardExtensionDefinition<P>>) {
            if (!extension.preload) {
                continue;
            }

            try {
                Promise.resolve(extension.preload(items, position)).catch((failure: unknown) => console.error(`[ratings-and-reviews] card extension preload at ${position} failed`, failure));
            } catch (failure) {
                console.error(`[ratings-and-reviews] card extension preload at ${position} failed`, failure);
            }
        }
    }
}
