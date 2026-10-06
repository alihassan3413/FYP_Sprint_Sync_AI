import js from '@eslint/js';
import vue from 'eslint-plugin-vue';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default tseslint.config(
    { ignores: ['out', 'dist', 'node_modules'] },
    js.configs.recommended,
    ...tseslint.configs.recommended,
    ...vue.configs['flat/recommended'],
    {
        files: ['**/*.vue'],
        languageOptions: { parserOptions: { parser: tseslint.parser }, globals: globals.browser },
    },
    {
        files: ['src/main/**/*.ts', 'src/preload/**/*.ts', 'tests/**/*.ts', '*.ts', 'scripts/**/*.mjs'],
        languageOptions: { globals: globals.node },
    },
    {
        rules: {
            'vue/multi-word-component-names': 'off',
            'vue/max-attributes-per-line': 'off',
            'vue/singleline-html-element-content-newline': 'off',
            'vue/html-indent': ['error', 4],
            'vue/html-self-closing': 'off',
            'no-restricted-syntax': [
                'error',
                { selector: "MemberExpression[object.name='process'][property.name='env'][parent.property.name='NODE_TLS_REJECT_UNAUTHORIZED']", message: 'Never disable TLS verification.' },
            ],
        },
    },
);
