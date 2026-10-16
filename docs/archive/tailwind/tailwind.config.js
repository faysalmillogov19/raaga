import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        // Classes des colonnes de tableaux de données, déclarées dans les contrôleurs.
        './app/Http/Controllers/**/*.php',
        './app/Support/**/*.php',
    ],

    theme: {
        extend: {
            // Point de rupture des pages publiques : en dessous de 760px, affichage mobile.
            screens: {
                tab: '760px',
            },
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
                public: ['"Public Sans"', ...defaultTheme.fontFamily.sans],
            },
            // Charte des pages publiques (administration du Burkina Faso).
            colors: {
                // Variables --charte-* définies en tête de public/css/app.css (comme les adm-*) ; jamais redéfinies par les thèmes.
                vert: { DEFAULT: 'var(--charte-vert)', fonce: 'var(--charte-vert-fonce)', pale: 'var(--charte-vert-pale)' },
                rouge: { DEFAULT: 'var(--charte-rouge)', pale: 'var(--charte-rouge-pale)' },
                jaune: { DEFAULT: 'var(--charte-jaune)', pale: 'var(--charte-jaune-pale)' },
                encre: 'var(--charte-encre)',
                texte: 'var(--charte-texte)',
                gris: { DEFAULT: 'var(--charte-gris)', clair: 'var(--charte-gris-clair)', inactif: 'var(--charte-gris-inactif)' },
                ligne: 'var(--charte-ligne)',
                fond: 'var(--charte-fond)',
                // Charte du back-office : variables du thème (clair ou Forêt), définies dans resources/css/admin.css.
                adm: {
                    bg: 'var(--bg)', card: 'var(--card)', text: 'var(--text)', muted: 'var(--muted)', line: 'var(--line)', zebra: 'var(--zebra)', hover: 'var(--hover)',
                    primary: 'var(--primary)', 'primary-hover': 'var(--primary-hover)', soft: 'var(--soft)', 'soft-text': 'var(--soft-text)', danger: 'var(--danger)',
                    field: 'var(--field)', 'ok-bg': 'var(--ok-bg)', 'ok-text': 'var(--ok-text)', 'warn-bg': 'var(--warn-bg)', 'warn-text': 'var(--warn-text)',
                    'err-bg': 'var(--err-bg)', 'err-text': 'var(--err-text)', 'info-bg': 'var(--info-bg)', 'info-text': 'var(--info-text)',
                    'neutral-bg': 'var(--neutral-bg)', 'neutral-text': 'var(--neutral-text)', bar: 'var(--bar)', track: 'var(--track)',
                },
            },
            borderRadius: {
                charte: '6px',
                carte: '10px',
            },
            boxShadow: {
                legere: '0 1px 2px rgba(22, 32, 26, 0.06), 0 1px 3px rgba(22, 32, 26, 0.04)',
            },
            maxWidth: {
                contenu: '1180px',
            },
        },
    },

    // Dernière compilation avant le passage aux fichiers statiques : classes courantes conservées même si aucune vue
    // ne les emploie encore (espacements, flex et grid, tailles de texte, couleurs de la charte, tab: et hover/focus).
    safelist: [
        { pattern: /^-?(m|mx|my|mt|mr|mb|ml)-(0|px|0\.5|1|1\.5|2|2\.5|3|4|5|6|8|10|12|16|auto)$/, variants: ['tab'] },
        { pattern: /^(p|px|py|pt|pr|pb|pl)-(0|px|0\.5|1|1\.5|2|2\.5|3|4|5|6|8|10|12|16)$/, variants: ['tab'] },
        { pattern: /^(gap|gap-x|gap-y|space-x|space-y)-(0|1|2|3|4|5|6|8|10|12)$/, variants: ['tab'] },
        { pattern: /^(flex|inline-flex|grid|inline-grid|block|inline-block|inline|hidden|contents)$/, variants: ['tab'] },
        { pattern: /^flex-(row|col|wrap|nowrap|1|auto|none|row-reverse|col-reverse)$/, variants: ['tab'] },
        { pattern: /^(grow|shrink|grow-0|shrink-0)$/, variants: ['tab'] },
        { pattern: /^(items|justify|self|content)-(start|end|center|between|around|evenly|stretch|baseline)$/, variants: ['tab'] },
        { pattern: /^grid-cols-([1-6]|12)$/, variants: ['tab'] },
        { pattern: /^col-span-([1-6]|12|full)$/, variants: ['tab'] },
        { pattern: /^text-(xs|sm|base|lg|xl|2xl|3xl|4xl)$/, variants: ['tab'] },
        { pattern: /^font-(normal|medium|semibold|bold|extrabold)$/ },
        { pattern: /^text-(left|center|right)$/, variants: ['tab'] },
        { pattern: /^(text|bg|border)-(vert|vert-fonce|vert-pale|rouge|rouge-pale|jaune|jaune-pale|encre|texte|gris|gris-clair|gris-inactif|ligne|fond|white|transparent)$/, variants: ['hover', 'focus', 'focus-visible'] },
        { pattern: /^(text|bg|border)-adm-(bg|card|text|muted|line|primary|primary-hover|soft|soft-text|danger|ok-bg|ok-text|warn-bg|warn-text|err-bg|err-text|info-bg|info-text|neutral-bg|neutral-text)$/, variants: ['hover', 'focus'] },
    ],

    plugins: [forms],
};
