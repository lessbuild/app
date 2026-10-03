// Builds web/messages/<locale>.json from the Laravel app's translations (api/lang/<locale>.json), keeping only the
// strings the Next.js app uses. Strings are found as t('…'), t(i18n, '…') and tc(…) calls (single or double quotes),
// keyed by their English text exactly as Laravel's __() keys them. Exits with an error listing any string a language
// doesn't translate yet: add it to api/lang so both frontends share one set of translations.

import { readFileSync, readdirSync, statSync, writeFileSync, mkdirSync } from 'node:fs';
import { join, dirname, extname } from 'node:path';
import { fileURLToPath } from 'node:url';

const web = join(dirname(fileURLToPath(import.meta.url)), '..');
const lang = join(web, '..', 'api', 'lang');
const locales = ['es', 'fr', 'de', 'pt'];
const call = /\btc?\(\s*(?:[A-Za-z_$][\w$]*\s*,\s*)?(['"])((?:\\.|(?!\1).)*)\1/g;

/** Every .ts and .tsx file under a folder. */
function sources(folder) {
    return readdirSync(folder).flatMap((name) => {
        const path = join(folder, name);
        if (statSync(path).isDirectory()) {
            return name === 'node_modules' || name.startsWith('.') ? [] : sources(path);
        }
        return ['.ts', '.tsx'].includes(extname(name)) ? [path] : [];
    });
}

const keys = new Set();
for (const file of ['app', 'components', 'lib'].flatMap((folder) => sources(join(web, folder)))) {
    for (const match of readFileSync(file, 'utf8').matchAll(call)) {
        keys.add(match[2].replace(/\\(['"\\])/g, '$1'));
    }
}

mkdirSync(join(web, 'messages'), { recursive: true });
const missing = [];
for (const locale of locales) {
    const all = JSON.parse(readFileSync(join(lang, `${locale}.json`), 'utf8'));
    const used = {};
    for (const key of [...keys].sort()) {
        if (typeof all[key] === 'string') {
            used[key] = all[key];
        } else {
            missing.push(`${locale}: ${key}`);
        }
    }
    writeFileSync(join(web, 'messages', `${locale}.json`), JSON.stringify(used, null, 4) + '\n');
}

if (missing.length > 0) {
    console.error(`These strings aren't translated in api/lang yet:\n${missing.join('\n')}`);
    process.exit(1);
}
console.log(`Translations: ${keys.size} strings in ${locales.length} languages.`);
