module.exports = {
  content: ['./index.html'],
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
      keyframes: {
        'fade-up': { '0%': { opacity: 0, transform: 'translateY(16px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } },
        'float':   { '0%,100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-10px)' } },
      },
      animation: {
        'fade-up': 'fade-up .6s ease-out both',
        'float': 'float 6s ease-in-out infinite',
      },
    },
  },
};
