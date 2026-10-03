import next from 'eslint-config-next';

const config = [...next, { ignores: ['.next/**', 'messages/**'] }];

export default config;
