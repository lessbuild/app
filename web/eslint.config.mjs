import withNuxt from './.nuxt/eslint.config.mjs';

export default withNuxt(
    { ignores: ['messages/**', '.output/**'] },
    {
        rules: {
            // Pages are named after their URL segment (login, register), not multi-word.
            'vue/multi-word-component-names': 'off',
            // Icons are trusted SVG paths generated from the design system.
            'vue/no-v-html': 'off',
        },
    },
);
