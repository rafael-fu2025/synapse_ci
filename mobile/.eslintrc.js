module.exports = {
  root: true,
  extends: '@react-native',
  ignorePatterns: ['mobileScreenReference/**'],
  overrides: [
    {
      // React Navigation's tabBarIcon and friends are render props by design.
      files: ['src/navigation/**/*.tsx'],
      rules: {
        'react/no-unstable-nested-components': 'off',
      },
    },
  ],
};
