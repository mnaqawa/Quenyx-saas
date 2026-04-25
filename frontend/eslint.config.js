import eslint from '@eslint/js'
import tseslint from 'typescript-eslint'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'

/**
 * ESLint 9 flat config. Replaces legacy .eslintrc.cjs to drop ESLint 8 and deprecated
 * @humanwhocodes/* / glob@7 / rimraf@3 transitive deps from the lint toolchain.
 */
export default tseslint.config(
  {
    ignores: [
      'dist',
      'node_modules',
      'postcss.config.cjs',
      '**/*.cjs',
      'package-lock.json',
    ],
  },
  eslint.configs.recommended,
  ...tseslint.configs.recommended,
  {
    files: ['**/*.{ts,tsx}'],
    plugins: {
      'react-hooks': reactHooks,
      'react-refresh': reactRefresh,
    },
    rules: {
      'react-hooks/rules-of-hooks': 'error',
      'react-hooks/exhaustive-deps': 'warn',
      'react-refresh/only-export-components': [
        'warn',
        { allowConstantExport: true },
      ],
    },
  }
)
