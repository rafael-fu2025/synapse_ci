const { getDefaultConfig, mergeConfig } = require('@react-native/metro-config');

/**
 * Metro configuration
 * https://reactnative.dev/docs/metro
 *
 * @type {import('@react-native/metro-config').MetroConfig}
 */
const config = {
  // The design-reference prototype ships its own package tree; keep it out of
  // resolution and file watching so it can never leak into the app bundle.
  resolver: {
    blockList: [/mobileScreenReference[\\/].*/],
  },

};

module.exports = mergeConfig(getDefaultConfig(__dirname), config);
