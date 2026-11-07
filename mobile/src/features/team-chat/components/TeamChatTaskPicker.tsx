import React, { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { Check, Search, X } from 'lucide-react-native';
import { useIsFocused } from '@react-navigation/native';
import type { TeamChatTaskReference } from '../../../api/endpoints/teamChat';
import type { TeamUnit } from '../../../api/types';
import { BottomSheet } from '../../../components/BottomSheet';
import { useForegroundOnline } from '../../../lib/useActiveScreen';
import { useTheme } from '../../../theme/ThemeProvider';
import { Theme, font, memoizeStyles, type } from '../../../theme/tokens';
import { useTeamChatTaskSearch } from '../hooks';
import { CHAT_TASK_LIMIT, CHAT_TASK_SEARCH_LIMIT } from '../model';

export function TeamChatTaskPicker({ unit, visible, selected, disabled = false, onChange, onClose }: {
  unit: TeamUnit | null;
  visible: boolean;
  selected: readonly TeamChatTaskReference[];
  disabled?: boolean;
  onChange: (tasks: TeamChatTaskReference[]) => void;
  onClose: () => void;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const foregroundOnline = useForegroundOnline();
  const focused = useIsFocused();
  const active = visible && focused && foregroundOnline && unit !== null && !disabled;
  const [input, setInput] = useState('');
  const [search, setSearch] = useState('');
  const query = useTeamChatTaskSearch(unit, search, visible && !disabled);
  const changing = input.trim() !== search;
  const selectedIds = new Set(selected.map(task => task.id));
  const selectionRef = useRef(selected);
  selectionRef.current = selected;
  const canSelect = useRef(active);
  canSelect.current = active;
  const retrying = useRef(false);
  const results = (query.data?.data ?? []).slice(0, CHAT_TASK_SEARCH_LIMIT);
  const resultTotal = Math.max(query.data?.meta.total ?? 0, query.data?.data.length ?? 0);

  useEffect(() => {
    if (!visible) { setInput(''); setSearch(''); return; }
    const timer = setTimeout(() => setSearch(input.trim()), 250);
    return () => clearTimeout(timer);
  }, [input, visible]);
  useEffect(() => { setInput(''); setSearch(''); }, [unit]);

  function toggle(task: TeamChatTaskReference) {
    if (!canSelect.current || changing || query.isError) { return; }
    const current = selectionRef.current;
    const next = current.some(item => item.id === task.id)
      ? current.filter(item => item.id !== task.id)
      : current.length < CHAT_TASK_LIMIT ? [...current, task] : current;
    if (next === current) { return; }
    selectionRef.current = next;
    onChange([...next]);
  }

  return (
    <BottomSheet
      visible={visible}
      title="Task References"
      description={`${selected.length} of ${CHAT_TASK_LIMIT} selected · active authorized tasks only`}
      closeLabel="Close task references"
      testID="team-chat-task-picker"
      onClose={onClose}
      footer={(
        <Pressable accessibilityRole="button" accessibilityLabel="Done choosing task references" onPress={onClose} style={styles.done}>
          <Text style={styles.doneText}>Done</Text>
        </Pressable>
      )}>
      <View style={styles.searchRow}>
        <Search size={18} color={theme.ink500} />
        <TextInput
          accessibilityLabel="Search active tasks"
          value={input}
          onChangeText={setInput}
          placeholder="Search active tasks"
          placeholderTextColor={theme.ink400}
          editable={!disabled}
          autoCorrect={false}
          maxLength={200}
          returnKeyType="search"
          style={styles.search}
        />
        {input !== '' ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Clear task search"
            accessibilityState={{ disabled }}
            disabled={disabled}
            onPress={() => { if (!disabled) { setInput(''); setSearch(''); } }}
            style={styles.clear}>
            <X size={18} color={theme.ink500} />
          </Pressable>
        ) : null}
      </View>
      {!foregroundOnline ? <Text accessibilityRole="alert" style={styles.muted}>Reconnect to search or change task references.</Text> : null}
      {changing || query.isLoading ? (
        <View style={styles.feedback}><ActivityIndicator color={theme.brandIcon} /><Text style={styles.muted}>Searching tasks…</Text></View>
      ) : query.isError ? (
        <View style={styles.feedback}>
          <Text accessibilityRole="alert" style={styles.error}>Could not search task references. Your selections are unchanged.</Text>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Retry task search"
            accessibilityState={{ disabled: !active || query.isFetching, busy: query.isFetching }}
            disabled={!active || query.isFetching}
            onPress={() => {
              if (!canSelect.current || retrying.current || query.isFetching) { return; }
              retrying.current = true;
              query.refetch().catch(() => undefined).finally(() => { retrying.current = false; });
            }}
            style={styles.retry}>
            <Text style={styles.retryText}>Try Again</Text>
          </Pressable>
        </View>
      ) : (
        <View style={styles.list}>
          {query.isFetching ? <Text accessibilityLiveRegion="polite" style={styles.muted}>Updating active task results…</Text> : null}
          {resultTotal > results.length ? (
            <Text accessibilityLiveRegion="polite" style={styles.muted}>
              Showing the first {results.length} of {resultTotal} matches. Refine your search to find another task.
            </Text>
          ) : null}
          {selected.length >= CHAT_TASK_LIMIT ? <Text accessibilityLiveRegion="polite" style={styles.muted}>20 selected. Remove a reference before adding another.</Text> : null}
          {results.length === 0 && query.data ? <Text style={styles.empty}>No active tasks match this search.</Text> : null}
          {results.map(item => {
            const checked = selectedIds.has(item.id);
            const optionDisabled = !active || (!checked && selected.length >= CHAT_TASK_LIMIT);
            return (
              <Pressable
                key={item.id}
                accessibilityRole="checkbox"
                accessibilityLabel={`Task ${item.id}: ${item.title}`}
                accessibilityState={{ checked, disabled: optionDisabled }}
                disabled={optionDisabled}
                onPress={() => toggle(item)}
                style={({ pressed }) => [styles.option, optionDisabled && styles.disabled, pressed && styles.pressed]}>
                <View style={[styles.checkbox, checked && styles.checkboxChecked]}>{checked ? <Check size={14} color="#ffffff" /> : null}</View>
                <Text style={styles.optionText}>#{item.id}: {item.title}</Text>
              </Pressable>
            );
          })}
        </View>
      )}
    </BottomSheet>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    searchRow: { minHeight: 46, flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 8, paddingHorizontal: 12, borderWidth: 1, borderColor: theme.line, borderRadius: 12, backgroundColor: theme.surfaceWarm },
    search: { flex: 1, minHeight: 44, color: theme.ink900, fontFamily: font.body, fontSize: 12 },
    clear: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
    list: { paddingVertical: 10 },
    option: { minHeight: 52, flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 7 },
    checkbox: { width: 24, height: 24, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: theme.ink400, borderRadius: 7 },
    checkboxChecked: { borderColor: theme.brand, backgroundColor: theme.brand },
    optionText: { ...type.bodyStrong, flex: 1, color: theme.ink900 },
    feedback: { alignItems: 'center', justifyContent: 'center', gap: 10, padding: 24 },
    muted: { ...type.body, color: theme.ink500, paddingVertical: 6 },
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
