import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
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
