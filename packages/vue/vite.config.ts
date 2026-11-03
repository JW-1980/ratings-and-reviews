import { resolve } from 'node:path';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';

const peers = [/^vue$/, /^@tiptap\//, /^dompurify$/];

export default defineConfig({
    plugins: [vue()],
    build: {
        lib: {
            entry: resolve(import.meta.dirname, 'src/index.ts'),
            formats: ['es'],
            fileName: () => 'ratings-and-reviews-vue.js',
            cssFileName: 'ratings-and-reviews-vue',
        },
        sourcemap: true,
        rollupOptions: {
            external: (id) => peers.some((pattern) => pattern.test(id)),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        include: ['tests/**/*.spec.ts'],
        restoreMocks: true,
    },
});
