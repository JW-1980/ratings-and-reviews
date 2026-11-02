/**
 * Copies the package's shared browser modules (resources/js, the single source) into
 * src/shared so Vite, Vitest and vue-tsc resolve their peer imports from this package's
 * node_modules. src/shared is generated and git-ignored: edit resources/js instead.
 */
import { cpSync, existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const source = resolve(here, '../../../resources/js');
const target = resolve(here, '../src/shared');

if (!existsSync(source)) {
    console.error(`[sync-shared] ${source} not found`);
    process.exit(1);
}

rmSync(target, { recursive: true, force: true });
mkdirSync(target, { recursive: true });
cpSync(source, target, { recursive: true });
writeFileSync(resolve(target, 'README.md'), 'Generated from resources/js by scripts/sync-shared.mjs. Do not edit; edit resources/js.\n');
console.log(`[sync-shared] copied ${source} -> ${target}`);
