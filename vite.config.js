import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,

            /*
             * Fonts are self-hosted, not linked from a CDN.
             *
             * This is a point-of-sale terminal: it has to keep selling when the
             * shop's connection drops, and a third-party stylesheet in <head> is
             * a render-blocking request to a host we do not control. Bundling the
             * files means the register renders identically online and off, and the
             * whole shift runs from cache.
             *
             * `subsets` matters more here than in a Latin-only app — Bunny's
             * default is ['latin'], which would ship a Cairo that cannot draw a
             * single Arabic glyph. `optimizedFallbacks` (fontaine) generates a
             * metric-matched local fallback face, so text laid out in the fallback
             * occupies the same box as the real font and nothing jumps on swap.
             */
            fonts: [
                bunny('Cairo', {
                    weights: [400, 500, 600, 700],
                    subsets: ['arabic', 'latin'],

                    /* Only the body weight is preloaded. Preloading all four across
                       both subsets would put eight font requests ahead of the CSS
                       and JS the screen actually needs first; the metric-matched
                       fallback covers the rest for the few hundred ms they take. */
                    preload: [{ weight: 400 }],
                    fallbacks: ['system-ui', 'sans-serif'],
                }),

                /* Figures only — every price, quantity and total goes through
                   .cell-numeric / .stat-value / .pos-total-value. Latin subset is
                   sufficient because those elements force direction: ltr and
                   render ASCII digits. Not preloaded: the ui-monospace fallback
                   is already tabular, so the swap moves no columns. */
                bunny('JetBrains Mono', {
                    weights: [400, 600, 700],
                    subsets: ['latin'],
                    preload: false,
                    fallbacks: ['ui-monospace', 'monospace'],
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
