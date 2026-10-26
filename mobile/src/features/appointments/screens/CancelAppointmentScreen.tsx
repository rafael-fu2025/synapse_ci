import React, { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Action } from '../../../components/Action';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { TextField } from '../../../components/TextField';
import { ApiError } from '../../../api/client';
import { Theme, font, type } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { useCancelAppointment } from '../hooks';
import { VisitsStackParamList } from '../../../navigation/types';

type Props = NativeStackScreenProps<VisitsStackParamList, 'CancelAppointment'>;

export function CancelAppointmentScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Props['navigation']>();
  const route = useRoute<Props['route']>();
  const cancel = useCancelAppointment();

  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);

  async function onSubmit() {
    const trimmed = reason.trim();
    if (trimmed === '') {
      setError('Tell the department why you are cancelling.');
      return;
    }
    setError(null);
    try {
      await cancel.mutateAsync({ id: route.params.id, reason: trimmed });
      navigation.goBack();
    } catch (mutationError) {
      setError(
        mutationError instanceof ApiError ? mutationError.message : 'The appointment could not be cancelled. Try again.',
      );
    }
  }

  return (
    <Screen scroll={false}>
      <PageHeader title="Cancel appointment" subtitle="The department is notified immediately" onBack={navigation.goBack} />
      <Text style={styles.heading}>What changed?</Text>
      <Text style={styles.caption}>
        A short reason helps the clinic or guidance staff offer the slot to someone else. Cancellations cannot be
        undone, but you can always request a new appointment.
      </Text>
      <TextField
        label="Reason for cancelling"
        multiline
        style={styles.textarea}
        value={reason}
        onChangeText={setReason}
        editable={!cancel.isPending}
        error={error ?? undefined}
      />
      <View style={styles.actions}>
        <Action variant="ghost" full onPress={navigation.goBack}>
          Keep my appointment
        </Action>
        <Action variant="danger" full onPress={onSubmit} loading={cancel.isPending}>
          Cancel appointment
        </Action>
      </View>
    </Screen>
  );
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    heading: { ...type.hero, fontSize: 19, color: theme.ink900 },
    caption: { marginTop: 5, color: theme.ink500, fontSize: 11.5, lineHeight: 17, fontFamily: font.body },
    textarea: { minHeight: 105, paddingTop: 12, textAlignVertical: 'top' },
    actions: { marginTop: 'auto', gap: 7, paddingBottom: 4 },
  });
}
