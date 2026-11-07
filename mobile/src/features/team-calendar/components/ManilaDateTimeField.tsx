import React, { useEffect, useState } from 'react';
import { Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import DateTimePicker, { type DateTimePickerEvent } from '@react-native-community/datetimepicker';
import { CalendarDays, Clock3 } from 'lucide-react-native';
import { Action } from '../../../components/Action';
import { useTheme } from '../../../theme/ThemeProvider';
import { Theme, font, memoizeStyles, type } from '../../../theme/tokens';
import {
  formatManilaDayLabel,
  formatManilaTimeFromInstant,
  manilaWallToPickerDate,
  mergePickerSelection,
} from '../manila';

export function ManilaDateTimeField({
  label,
  value,
  onChange,
  disabled = false,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const [mode, setMode] = useState<'date' | 'time' | null>(null);
  const pickerValue = manilaWallToPickerDate(value);
  const timeLabel = formatManilaTimeFromInstant(pickerValue.toISOString());

  useEffect(() => {
    if (disabled) { setMode(null); }
  }, [disabled]);

  const handleChange = (event: DateTimePickerEvent, selected?: Date) => {
    if (Platform.OS === 'android') { setMode(null); }
    if (disabled || event.type !== 'set' || !selected) { return; }
    onChange(mergePickerSelection(value, selected, mode ?? 'date'));
  };

  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <View style={styles.row}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={`Choose ${label.toLowerCase()} date`}
          accessibilityState={{ disabled }}
          disabled={disabled}
          onPress={() => setMode('date')}
          style={styles.control}>
          <CalendarDays size={17} color={theme.brandIcon} />
          <Text style={styles.controlText}>{formatManilaDayLabel(value.slice(0, 10))}</Text>
        </Pressable>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={`Choose ${label.toLowerCase()} time`}
          accessibilityState={{ disabled }}
          disabled={disabled}
          onPress={() => setMode('time')}
          style={[styles.control, styles.timeControl]}>
          <Clock3 size={17} color={theme.brandIcon} />
          <Text style={styles.controlText}>{timeLabel}</Text>
        </Pressable>
      </View>
      <Text style={styles.hint}>Asia/Manila time</Text>
      {mode && !disabled ? (
        <View style={styles.pickerWrap}>
          <DateTimePicker
            value={pickerValue}
            mode={mode}
            display={Platform.OS === 'ios' ? 'spinner' : 'default'}
            timeZoneName="Asia/Manila"
            is24Hour={false}
            minuteInterval={5}
            disabled={disabled}
            themeVariant={theme.mode}
            onChange={handleChange}
          />
          {Platform.OS === 'ios' ? (
            <Action variant="secondary" onPress={() => setMode(null)}>Done</Action>
          ) : null}
        </View>
      ) : null}
    </View>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    field: { marginTop: 15 },
    label: { marginBottom: 6, marginLeft: 2, fontSize: 10.5, fontWeight: '700', fontFamily: font.body, color: theme.ink700 },
    row: { flexDirection: 'row', gap: 8 },
    control: {
      minHeight: 46,
      flex: 1,
      flexDirection: 'row',
      alignItems: 'center',
      gap: 8,
      paddingHorizontal: 12,
      borderWidth: 1,
      borderColor: theme.line,
      borderRadius: 12,
      backgroundColor: theme.surfaceWarm,
    },
    timeControl: { flex: 0.62 },
    controlText: { ...type.bodyStrong, flex: 1, color: theme.ink900 },
    hint: { marginTop: 4, marginLeft: 2, color: theme.ink500, fontSize: 9.5, fontFamily: font.body },
    pickerWrap: { marginTop: 8, padding: 8, gap: 8, borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
  });
}

const makeStyles = memoizeStyles(createStyles);
