import { inject, shallowRef, type App, type InjectionKey, type Ref } from 'vue';
import { createApiClient, createTranslator, sanitizeHtml, type ApiClient, type ApiOptions } from './shared';
import type { Option, ProductCard, Sticker, Translate, TranslateChoice } from './types';


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
    /** The active sticker library; without it the picker loads it from GET stickers when first opened. */
    stickers?: Sticker[];
    /** Emoji choices for the emoji tool. */
    emojis?: string[];
    /** Uploads an editor image and returns its URL. Default: the package's POST uploads; a host callback overrides it. */
    uploadImage?: (file: File) => Promise<{ url: string; alt?: string | null }>;
    /** Product search for the `#` card trigger; without it `#type:id` is resolved through the API. */
    searchCards?: (query: string) => Promise<ProductCard[]>;
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
    stickers: Sticker[];
    /** The sticker library loaded from the API (empty until loadStickers() resolved; unused when `stickers` was given). */
    loadedStickers: Ref<Sticker[]>;
    /** Loads the active stickers from GET stickers once (resolves to `stickers` when the host gave them). */
    loadStickers: () => Promise<Sticker[]>;
    emojis: string[] | null;
    uploadImage: NonNullable<RatingsAndReviewsOptions['uploadImage']> | null;
    searchCards: RatingsAndReviewsOptions['searchCards'] | null;
    sanitize: (html: string, extraTags?: string[]) => string;
}

export const RR_CONTEXT: InjectionKey<RatingsAndReviewsContext> = Symbol('ratings-and-reviews');

function isClient(value: unknown): value is ApiClient {
    return typeof value === 'object' && value !== null && typeof (value as ApiClient).request === 'function';
}

export function createContext(options: RatingsAndReviewsOptions): RatingsAndReviewsContext {
    const { t, tc } = createTranslator(options.messages ?? {});
    const api = isClient(options.api) ? options.api : createApiClient({ locale: options.locale, ...(options.api ?? {}) });
    const mediaPrefixes = options.mediaPrefixes ?? ['/storage/'];
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
        stickers: options.stickers ?? [],
        loadedStickers,
        loadStickers,
        emojis: options.emojis ?? null,
        uploadImage: options.uploadImage ?? ((file: File) => api.uploadImage(file)),
        searchCards: options.searchCards ?? null,
        sanitize: (html, extraTags) => sanitizeHtml(html, { mediaPrefixes, extraTags }),
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
