import React from 'react';
import { ActivityIndicator, Image, StyleSheet, View } from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import { palette } from '../../../theme/tokens';

export function SplashScreen() {
  return (
    <LinearGradient colors={[palette.brand900, palette.brand600]} style={styles.gradient}>
      <View style={styles.patternOne} />
      <View style={styles.patternTwo} />
      <View style={styles.center}>
        <View style={styles.mark}>
          <Image source={require('../../../assets/brand/FoundationU.png')} style={styles.markImage} />
        </View>
        <Image
          source={require('../../../assets/brand/synapse-white.png')}
          style={styles.logo}
          resizeMode="contain"
        />
        <ActivityIndicator color="#ffffff" style={styles.spinner} />
      </View>
    </LinearGradient>
  );
}

const styles = StyleSheet.create({
  gradient: { flex: 1 },
  patternOne: {
    position: 'absolute',
    top: -100,
    right: -130,
    width: 310,
    height: 310,
    borderRadius: 999,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.08)',
  },
  patternTwo: {
    position: 'absolute',
    bottom: -80,
    left: -120,
    width: 260,
    height: 260,
    borderRadius: 999,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.08)',
  },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  mark: {
    width: 68,
    height: 68,
    borderRadius: 21,
    backgroundColor: '#ffffff',
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: '#000000',
    shadowOpacity: 0.15,
    shadowRadius: 30,
    shadowOffset: { width: 0, height: 15 },
    elevation: 8,
  },
  markImage: { width: 52, height: 52, borderRadius: 26 },
  logo: { width: 215, height: 78, marginTop: 15 },
  spinner: { marginTop: 18 },
});
