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
                vert: { DEFAULT: '#00843D', fonce: '#005C2B', pale: '#E8F4EC' },
                rouge: { DEFAULT: '#CE1126', pale: '#FCEBED' },
                jaune: { DEFAULT: '#FCD116', pale: '#FFF8D9' },
                encre: '#16201A',
                texte: '#2E3A33',
                gris: { DEFAULT: '#5E6B63', clair: '#C9D2CC', inactif: '#B9C4BD' },
                ligne: '#D9E0DB',
                fond: '#F5F7F5',
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

    plugins: [forms],
};
