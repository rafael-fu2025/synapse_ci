import React, { useState } from 'react';
import { Image, StyleSheet, Text, View } from 'react-native';
import { font, palette } from '../theme/tokens';

/** The saved profile photo, with white initials on maroon as the loading/error fallback. */
export function Avatar({
  initials,
  size = 'small',
  photoUri,
}: {
  initials: string;
  size?: 'small' | 'large';
  photoUri?: string | null;
}) {
  const [failedUri, setFailedUri] = useState<string | null>(null);
  const showPhoto = Boolean(photoUri && photoUri !== failedUri);
  return (
    <View style={[styles.base, size === 'large' ? styles.large : styles.small]}>
      {showPhoto ? (
        <Image
          key={photoUri}
          source={{ uri: photoUri! }}
          style={styles.photo}
          resizeMode="cover"
          accessibilityLabel="Your profile photo"
          onError={() => setFailedUri(photoUri ?? null)}
        />
      ) : (
        <Text style={[styles.label, size === 'large' ? styles.largeLabel : styles.smallLabel]}>{initials}</Text>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  base: {
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 999,
    borderWidth: 2,
    borderColor: palette.brand700,
    backgroundColor: palette.brand700,
    overflow: 'hidden',
  },
  small: { width: 38, height: 38 },
  large: { width: 86, height: 86, borderWidth: 3 },
  photo: { width: '100%', height: '100%' },
  label: { color: '#ffffff', fontWeight: '800', fontFamily: font.body },
  smallLabel: { fontSize: 11 },
  largeLabel: { fontSize: 22 },
});
