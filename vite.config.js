import tailwindcss from '@tailwindcss/vite'
import laravel from 'laravel-vite-plugin'
import { defineConfig } from 'vite'

// The panel's theme is the only entrypoint, and it is not a matter of taste.
// Filament ships its stylesheet pre-compiled, but that build contains only the
// `fi-*` classes Filament's own views use — no utility layer at all. Any Blade
// view of ours that reaches for `grid`, `p-4` or `text-sm` gets nothing back
// unless those utilities are generated here, against our own templates.
//
// See resources/css/filament/admin/theme.css for which files are scanned.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/filament/admin/theme.css'],
            refresh: true,
        }),
        tailwindcss(),
    ],
})
