/** Copies the example theme files next to the built stylesheet. */
import { cpSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const target = resolve(here, '../dist/themes');

mkdirSync(target, { recursive: true });
cpSync(resolve(here, '../src/shared/css/themes'), target, { recursive: true });
console.log(`[copy-themes] themes -> ${target}`);
