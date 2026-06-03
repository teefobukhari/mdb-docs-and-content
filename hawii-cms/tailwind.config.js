const path = require('path');
module.exports = {
  content: [
    path.join(__dirname, 'resources/views/**/*.blade.php'),
  ],
  darkMode: 'class',
  theme: {
    extend: {
      fontFamily: {
        sans: ['"IBM Plex Sans Arabic"', '"IBM Plex Sans"', 'system-ui', '-apple-system', '"Segoe UI"', 'Tahoma', 'sans-serif'],
      },
      colors: {
        brand:  { DEFAULT: '#2563EB', light: '#3B82F6', dark: '#1D4ED8' },
        accent: { DEFAULT: '#F97316', dark: '#EA580C' },
        ink:    { DEFAULT: '#1E293B', soft: '#475569', faint: '#64748B' },
      },
      boxShadow: {
        card: '0 1px 2px rgba(15,23,42,.04), 0 8px 24px -8px rgba(15,23,42,.10)',
        lift: '0 12px 40px -12px rgba(37,99,235,.35)',
      },
    },
  },
  plugins: [require('@tailwindcss/typography')],
};
