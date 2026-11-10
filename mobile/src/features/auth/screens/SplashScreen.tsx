import React from 'react';
import { ActivityIndicator, Image, ScrollView, StyleSheet, View } from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import { SafeAreaView } from 'react-native-safe-area-context';
import { palette } from '../../../theme/tokens';
import { useSystemNavigationBar } from '../../../components/SystemNavigationBar';
import { useOptionalConnection } from '../../connection/ConnectionContext';
import { ServerConnectionControl } from '../../connection/ServerConnectionControl';

export function SplashScreen() {
  useSystemNavigationBar('splash');
  const connection = useOptionalConnection();
  const showConnection = connection?.enabled === true && connection.profile !== null;
  const content = (
    <View style={styles.center}>
      <Image
        source={require('../../../assets/brand/synapse-white.png')}
        style={styles.logo}
        resizeMode="contain"
      />
      <ActivityIndicator color="#ffffff" style={styles.spinner} />
    </View>
  );
  return (
    <LinearGradient colors={[palette.brand900, palette.brand600]} style={styles.gradient}>
      <View style={styles.patternOne} />
      <View style={styles.patternTwo} />
      {showConnection ? (
        <SafeAreaView style={styles.gradient} edges={['top', 'bottom']}>
          <ScrollView contentContainerStyle={styles.connectionContent} keyboardShouldPersistTaps="handled" keyboardDismissMode="on-drag">
            {content}
            <ServerConnectionControl requiresSignIn />
          </ScrollView>
        </SafeAreaView>
      ) : content}
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
  connectionContent: { flexGrow: 1, paddingHorizontal: 24, paddingVertical: 12 },
  logo: { width: 215, height: 78 },
  spinner: { marginTop: 18 },
});
