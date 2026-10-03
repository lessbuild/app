import next from 'eslint-config-next';

const config = [
    ...next,
    { ignores: ['.next/**', 'messages/**'] },
    {
        rules: {
            // Full page loads are deliberate here: after signing in or out (so every server component sees the new
            // session) and from helpers outside React components, which can't use the router.
            '@next/next/no-location-assign-relative-destination': 'off',
        },
    },
];

export default config;
