/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './themes/blue/front/**/*.twig',
    './themes/blue/front/**/*.html',
    './themes/blue/front/**/*.md',
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          DEFAULT: '#0d6efd',
          dark: '#0a58ca',
        },
      },
    },
  },
  plugins: [],
}

