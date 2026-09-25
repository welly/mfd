// ESLint for component and custom-module JavaScript (task fe:lint).
// Drupal behaviours use the Drupal, drupalSettings and once globals.
import js from '@eslint/js';
import globals from 'globals';

export default [
  {
    ignores: ['node_modules/**', 'vendor/**', 'web/core/**', 'qa/**', '.storybook/**', 'storybook-static/**'],
  },
  js.configs.recommended,
  {
    files: ['web/themes/custom/**/*.js', 'web/modules/custom/**/*.js'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'script',
      globals: {
        ...globals.browser,
        Drupal: 'readonly',
        drupalSettings: 'readonly',
        once: 'readonly',
      },
    },
  },
];
