import React, { useEffect, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  Modal,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { Check, Search, X } from 'lucide-react-native';
import type { TeamChatTaskReference } from '../../../api/endpoints/teamChat';
import type { TeamUnit } from '../../../api/types';
import { useTheme } from '../../../theme/ThemeProvider';
import { Theme, font, memoizeStyles, type } from '../../../theme/tokens';
import { useTeamChatTaskSearch } from '../hooks';

export function TeamChatTaskPicker({
  unit,
  visible,
  selected,
  onChange,
  onClose,
}: {
  unit: TeamUnit | null;
  visible: boolean;
  selected: readonly TeamChatTaskReference[];
  onChange: (tasks: TeamChatTaskReference[]) => void;
  onClose: () => void;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const [input, setInput] = useState('');
  const [search, setSearch] = useState('');
  const query = useTeamChatTaskSearch(unit, search, visible);
  const selectedIds = new Set(selected.map(task => task.id));

  useEffect(() => {
    if (!visible) { setInput(''); setSearch(''); return; }
    const timer = setTimeout(() => setSearch(input.trim()), 250);
    return () => clearTimeout(timer);
  }, [input, visible]);

  function toggle(task: TeamChatTaskReference) {
    if (selectedIds.has(task.id)) {
      onChange(selected.filter(item => item.id !== task.id));
    } else if (selected.length < 20) {
      onChange([...selected, task]);
    }
  }

  return (
    <Modal
      visible={visible}
      transparent
      animationType="slide"
      accessibilityViewIsModal
      onRequestClose={onClose}>
      <View style={styles.backdrop}>
        <View style={styles.sheet}>
          <View style={styles.heading}>
            <View style={styles.headingCopy}>
              <Text accessibilityRole="header" style={styles.title}>Task References</Text>
              <Text style={styles.subtitle}>{selected.length} of 20 selected · active tasks only</Text>
            </View>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Close task references"
              onPress={onClose}
              style={styles.iconButton}>
              <X size={20} color={theme.ink700} />
            </Pressable>
          </View>
          <View style={styles.searchRow}>
            <Search size={18} color={theme.ink500} />
            <TextInput
              accessibilityLabel="Search active tasks"
              value={input}
              onChangeText={setInput}
              placeholder="Search active tasks"
              placeholderTextColor={theme.ink400}
              autoCorrect={false}
              maxLength={200}
              style={styles.search}
            />
          </View>
          {query.isLoading ? (
            <View style={styles.feedback}><ActivityIndicator color={theme.brandIcon} /><Text style={styles.muted}>Loading tasks…</Text></View>
          ) : query.isError ? (
            <View style={styles.feedback}>
              <Text accessibilityRole="alert" style={styles.error}>Could not search task references.</Text>
              <Pressable accessibilityRole="button" onPress={() => { query.refetch().catch(() => undefined); }} style={styles.retry}>
                <Text style={styles.retryText}>Try Again</Text>
              </Pressable>
            </View>
          ) : (
            <FlatList
              data={query.data?.data ?? []}
              keyExtractor={item => String(item.id)}
              keyboardShouldPersistTaps="handled"
              contentContainerStyle={styles.list}
              ListEmptyComponent={<Text style={styles.empty}>No active tasks match this search.</Text>}
              renderItem={({ item }) => {
                const checked = selectedIds.has(item.id);
                const disabled = !checked && selected.length >= 20;
                return (
                  <Pressable
                    accessibilityRole="checkbox"
                    accessibilityLabel={`Task ${item.id}: ${item.title}`}
                    accessibilityState={{ checked, disabled }}
                    disabled={disabled}
                    onPress={() => toggle(item)}
                    style={({ pressed }) => [styles.option, disabled && styles.disabled, pressed && styles.pressed]}>
                    <View style={[styles.checkbox, checked && styles.checkboxChecked]}>
                      {checked ? <Check size={14} color="#ffffff" /> : null}
                    </View>
                    <Text style={styles.optionText}>#{item.id}: {item.title}</Text>
                  </Pressable>
                );
              }}
            />
          )}
          <Pressable accessibilityRole="button" onPress={onClose} style={styles.done}>
            <Text style={styles.doneText}>Done</Text>
          </Pressable>
        </View>
      </View>
    </Modal>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    backdrop: { flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(0,0,0,0.45)' },
    sheet: {
      maxHeight: '78%',
      minHeight: 360,
      padding: 18,
      borderTopLeftRadius: 22,
      borderTopRightRadius: 22,
      backgroundColor: theme.surface,
    },
    heading: { flexDirection: 'row', alignItems: 'flex-start', gap: 10 },
    headingCopy: { flex: 1 },
    title: { ...type.cardTitle, color: theme.ink900 },
    subtitle: { ...type.caption, marginTop: 3, color: theme.ink500 },
    iconButton: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
    searchRow: {
      minHeight: 46,
      flexDirection: 'row',
      alignItems: 'center',
      gap: 8,
      marginTop: 12,
      paddingHorizontal: 12,
      borderWidth: 1,
      borderColor: theme.line,
      borderRadius: 12,
      backgroundColor: theme.surfaceWarm,
    },
    search: { flex: 1, minHeight: 44, color: theme.ink900, fontFamily: font.body, fontSize: 12 },
    list: { paddingVertical: 10 },
    option: { minHeight: 52, flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 7 },
    checkbox: { width: 24, height: 24, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: theme.ink400, borderRadius: 7 },
    checkboxChecked: { borderColor: theme.brand, backgroundColor: theme.brand },
    optionText: { ...type.bodyStrong, flex: 1, color: theme.ink900 },
    feedback: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 10, padding: 24 },
    muted: { ...type.body, color: theme.ink500 },
    error: { ...type.body, color: '#a33b3b', textAlign: 'center' },
    empty: { ...type.body, padding: 24, color: theme.ink500, textAlign: 'center' },
    retry: { minHeight: 44, justifyContent: 'center', paddingHorizontal: 16 },
    retryText: { ...type.action, color: theme.brandText },
    done: { minHeight: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 12, backgroundColor: theme.brand },
    doneText: { ...type.action, color: '#ffffff' },
    disabled: { opacity: 0.4 },
    pressed: { opacity: 0.68 },
  });
}

const makeStyles = memoizeStyles(createStyles);
