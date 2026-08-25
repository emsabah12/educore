import js from '@eslint/js';
import globals from 'globals';
import reactHooks from 'eslint-plugin-react-hooks';
import tseslint from 'typescript-eslint';

export default tseslint.config(
    {
        ignores: [
            'frontend/dist/**',
            'frontend/src/platform/api/generated/**',
        ],
    },

    {
        files: [
            'frontend/src/**/*.{ts,tsx}',
        ],

        extends: [
            js.configs.recommended,
            ...tseslint.configs.recommended,
        ],

        languageOptions: {
            globals: {
                ...globals.browser,
            },
        },

        plugins: {
            'react-hooks':
                reactHooks,
        },

        rules: {
            ...reactHooks
                .configs
                .flat
                .recommended
                .rules,

            '@typescript-eslint/no-unused-vars': [
                'error',
                {
                    argsIgnorePattern:
                        '^_',

                    caughtErrorsIgnorePattern:
                        '^_',
                },
            ],
        },
    },
);
