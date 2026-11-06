module.exports = {
  preset: '@react-native/jest-preset',
  // Navigation and Firebase packages ship ESM builds the preset's ignore list
  // does not transform; widen it so Jest can parse them.
  transformIgnorePatterns: [
    'node_modules/(?!(react-native|@react-native(-community)?|@react-navigation|@react-native-firebase)/)',
  ],
};
