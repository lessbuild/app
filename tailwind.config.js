const defaultTheme = require('tailwindcss/defaultTheme')

module.exports = {
    content: [
        "./resources/**/*.{blade.php,js,vue}",
    ],
    theme: {
        extend: {
            fontFamily: {
                'sans': [...defaultTheme.fontFamily.sans],
            },
            colors: {
                'primary': 'var(--bg-primary)',
                'secondary': 'var(--bg-secondary)',
                'tertiary': 'var(--bg-tertiary)',
                'ternary': 'var(--bg-ternary)',
            },
            textColor: {
                'primary': 'var(--text-primary)',
                'secondary': 'var(--text-secondary)',
                'tertiary': 'var(--text-tertiary)',
                'ternary': 'var(--text-ternary)',
            },
            borderColor: {
                'primary': 'var(--border-primary)',
                'secondary': 'var(--border-secondary)',
                'ternary': 'var(--border-ternary)',
                'tertiary': 'var(--border-tertiary)',
            },
            divideColor: {
                'primary': 'var(--border-primary)',
                'secondary': 'var(--border-secondary)',
                'ternary': 'var(--border-ternary)',
                'tertiary': 'var(--border-tertiary)',
            },
            placeholderColor: {
                'primary': 'var(--placeholder-primary)',
                'secondary': 'var(--placeholder-secondary)',
                'ternary': 'var(--placeholder-ternary)',
                'tertiary': 'var(--placeholder-tertiary)',
            },
        },
    },
    plugins: [
        require('@tailwindcss/forms'),
    ],
}
