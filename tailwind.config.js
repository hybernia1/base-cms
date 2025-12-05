/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './themes/**/*.twig',
    './themes/**/*.html',
    './themes/**/*.md',
    './src/App/View/**/*.twig',
    './assets/js/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          DEFAULT: '#0d6efd',
          dark: '#0a58ca',
          muted: '#e0e7ff',
        },
        surface: '#ffffff',
        muted: '#64748b',
      },
      boxShadow: {
        dropdown: '0 12px 32px rgba(15, 23, 42, 0.18)',
        card: '0 10px 40px rgba(15, 23, 42, 0.08)',
      },
      borderRadius: {
        card: '14px',
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
      },
    },
  },
  plugins: [require('@tailwindcss/forms')],
};
