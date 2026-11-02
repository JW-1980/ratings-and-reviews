/** Request caches shared by all component instances of one page. */
export const authorCardCache = new Map<string, Promise<unknown>>();
export const productCardCache = new Map<string, Promise<unknown>>();
