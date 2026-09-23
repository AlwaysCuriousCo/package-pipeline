import { existsSync } from 'node:fs';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

/*
 * The Flux theme is an optional, separately licensed package: installations
 * that hold a licence require it themselves and get the styling, everyone
 * else runs the stock Filament theme.
 *
 * Tailwind resolves `@import` with enhanced-resolve, which throws on a missing
 * file and never reaches Vite's resolver — so an alias or a virtual module
 * cannot stand in for the absent package. Strip the import from the stylesheet
 * source instead, before Tailwind is handed it, which `enforce: 'pre'` buys us.
 */
const fluxTheme = 'vendor/alwayscurious/filament-flux-theme/resources/css/theme.css';

const optionalFluxTheme = {
    name: 'package-pipeline:optional-flux-theme',
    enforce: 'pre',
    transform(code, id) {
        if (! id.includes('resources/css/filament/admin/theme.css')) {
            return null;
        }

        if (existsSync(fluxTheme)) {
            return null;
        }

        return code.replace(/^@import\s+'[^']*filament-flux-theme[^']*';\s*$/m, '');
    },
};

export default defineConfig({
    plugins: [
        optionalFluxTheme,
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/filament/admin/theme.css',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
