import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import { SvgXml } from 'react-native-svg';
import { IdCard, TriangleAlert } from 'lucide-react-native';
import { LEVIN7_ART, LEVIN7_VIEWBOX } from '../assets/brand/levin7Art';
import { useTheme } from '../theme/ThemeProvider';
import { font } from '../theme/tokens';

export interface ProfileCardRow {
  label: string;
  value: string;
  /** Highlights the value in amber, as the web card does for allergies. */
  warning?: boolean;
}

const ART_HEIGHT_RATIO = 0.72;

/**
 * Mobile twin of the web portal's student/employee profile card: maroon ID-1 card with
 * the Levin7 art, caption, name, ID number and a label/value detail list.
 */
export function ProfileCard({
  caption,
  name,
  idNumber,
  rows,
}: {
  caption: string;
  name: string;
  idNumber: string;
  rows: ProfileCardRow[];
}) {
  const { theme } = useTheme();
  const dark = theme.mode === 'dark';
  const [height, setHeight] = React.useState(0);
  const artHeight = height * ART_HEIGHT_RATIO;
  const artWidth = artHeight * (LEVIN7_VIEWBOX.width / LEVIN7_VIEWBOX.height);

  return (
    <LinearGradient
      colors={dark ? ['#242424', '#161616'] : ['#800000', '#5a0000']}
      start={{ x: 0, y: 0 }}
      end={{ x: 1, y: 1 }}
      onLayout={(event) => setHeight(event.nativeEvent.layout.height)}
      accessible
      accessibilityLabel={`${caption}. ${name}. ID ${idNumber}. ${rows.map(row => `${row.label}: ${row.value}`).join('. ')}.`}
      style={[styles.card, dark ? styles.cardDark : styles.cardLight]}>
      {height > 0 ? (
        <View
          pointerEvents="none"
          style={[styles.art, dark ? styles.artDark : styles.artLight, { top: (height - artHeight) / 2 }]}>
          <SvgXml xml={LEVIN7_ART} width={artWidth} height={artHeight} />
        </View>
      ) : null}

      <View style={styles.content}>
        <View style={styles.captionRow}>
          <IdCard size={13} color={dark ? '#a3a3a3' : 'rgba(255,255,255,0.72)'} />
          <Text style={[styles.caption, dark && styles.captionDark]}>{caption}</Text>
        </View>
        <Text style={styles.name} numberOfLines={1}>
          {name}
        </Text>
        <Text style={[styles.id, dark && styles.idDark]}>ID: {idNumber}</Text>

        <View style={styles.details}>
          {rows.map((row) => (
            <View key={row.label} style={styles.detailRow}>
              <Text style={[styles.term, dark && styles.termDark]}>{row.label}</Text>
              <View style={styles.definitionWrap}>
                {row.warning ? <TriangleAlert size={11} color="#f2c14e" /> : null}
                <Text
                  style={[styles.definition, dark && styles.definitionDark, row.warning && styles.warning]}
                  numberOfLines={1}>
                  {row.value}
                </Text>
              </View>
            </View>
          ))}
        </View>
      </View>
    </LinearGradient>
  );
}

const styles = StyleSheet.create({
  card: {
    width: '100%',
    minHeight: 228,
    overflow: 'hidden',
    borderRadius: 14,
    borderWidth: 1,
  },
  cardLight: {
    borderColor: 'rgba(255,255,255,0.18)',
    elevation: 8,
    shadowColor: '#800000',
    shadowOpacity: 0.35,
    shadowRadius: 18,
    shadowOffset: { width: 0, height: 10 },
  },
  cardDark: {
    borderColor: 'rgba(255,255,255,0.12)',
    elevation: 4,
    shadowColor: '#000000',
    shadowOpacity: 0.5,
    shadowRadius: 18,
    shadowOffset: { width: 0, height: 10 },
  },
  art: { position: 'absolute', right: 0 },
  artLight: { opacity: 0.85 },
  artDark: { opacity: 0.15 },
  content: { flexGrow: 1, paddingVertical: 18, paddingHorizontal: 20 },
  captionRow: { flexDirection: 'row', alignItems: 'center', gap: 6, marginBottom: 8 },
  caption: {
    color: 'rgba(255,255,255,0.72)',
    fontSize: 10.4,
    fontWeight: '700',
    letterSpacing: 0.8,
    textTransform: 'uppercase',
    fontFamily: font.body,
  },
  captionDark: { color: '#a3a3a3' },
  name: { color: '#ffffff', fontSize: 16.8, fontWeight: '700', lineHeight: 20, fontFamily: font.body },
  id: {
    marginTop: 2,
    marginBottom: 14,
    color: 'rgba(255,255,255,0.75)',
    fontSize: 11.5,
    fontFamily: 'monospace',
  },
  idDark: { color: '#888888' },
  details: { marginTop: 'auto', gap: 4 },
  detailRow: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  term: { width: 78, color: 'rgba(255,255,255,0.65)', fontSize: 11.5, fontWeight: '500', fontFamily: font.body },
  termDark: { color: '#888888' },
  definitionWrap: { flex: 1, minWidth: 0, flexDirection: 'row', alignItems: 'center', gap: 4 },
  definition: { flexShrink: 1, color: '#ffffff', fontSize: 11.5, fontWeight: '600', fontFamily: font.body },
  definitionDark: { color: '#f0f0f0' },
  warning: { color: '#f2c14e', fontWeight: '800' },
});
