import React, { useState } from 'react';
import { ActivityIndicator, Image, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Camera, ImagePlus, Trash2 } from 'lucide-react-native';
import { launchImageLibrary } from 'react-native-image-picker';
import { useQueryClient } from '@tanstack/react-query';
import { Action } from '../../../components/Action';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { ApiError, getAccessToken } from '../../../api/client';
import { config } from '../../../lib/config';
import { useAuth } from '../../../store/auth';
import { removePhoto, uploadPhoto } from '../../../api/endpoints/health';
import { Theme, font } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { ProfileStackParamList } from '../../../navigation/types';

type Props = NativeStackScreenProps<ProfileStackParamList, 'ProfilePhoto'>;

export function ProfilePhotoScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Props['navigation']>();
  const { user } = useAuth();
  const client = useQueryClient();

  const [busy, setBusy] = useState<'upload' | 'remove' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [version, setVersion] = useState(0);
  const [imageFailed, setImageFailed] = useState(false);

  const token = getAccessToken();
  const photoUri = `${config.apiBaseUrl}/api/v1/me/photo?v=${version}`;

  async function pickAndUpload() {
    setError(null);
    const result = await launchImageLibrary({ mediaType: 'photo', quality: 0.9, selectionLimit: 1 });
    const asset = result.assets?.[0];
    if (!asset?.uri) {
      return;
    }
    setBusy('upload');
    try {
      await uploadPhoto(asset.uri);
      setImageFailed(false);
      setVersion((current) => current + 1);
      client.invalidateQueries({ queryKey: ['me'] }).catch(() => undefined);
    } catch (uploadError) {
      setError(uploadError instanceof ApiError ? uploadError.message : 'The photo could not be uploaded. Try again.');
    } finally {
      setBusy(null);
    }
  }

  async function onRemove() {
    setError(null);
    setBusy('remove');
    try {
      await removePhoto();
      setImageFailed(true);
      setVersion((current) => current + 1);
    } catch {
      setError('The photo could not be removed. Try again.');
    } finally {
      setBusy(null);
    }
  }

  return (
    <Screen>
      <PageHeader title="Profile photo" onBack={navigation.goBack} />
      <View style={styles.previewRing}>
        {imageFailed ? (
          <Text style={styles.initials}>{user?.avatar_initials ?? '··'}</Text>
        ) : (
          <Image
            source={{ uri: photoUri, headers: token !== null ? { Authorization: `Bearer ${token}` } : {} }}
            style={styles.previewImage}
            resizeMode="cover"
            onError={() => setImageFailed(true)}
          />
        )}
      </View>
      <Text style={styles.title}>Choose your photo</Text>
      <Text style={styles.copy}>
        A clear photo helps staff recognize you at the clinic or guidance center. It is compressed before storage and
        only visible to university staff.
      </Text>

      {error ? <Text style={styles.error}>{error}</Text> : null}

      <View style={styles.actionsCard}>
        <ActionRow
          icon={<ImagePlus size={17} color={theme.brand} />}
          title="Choose from library"
          caption="JPEG, PNG, or WebP up to 10 MB"
          busy={busy === 'upload'}
          onPress={pickAndUpload}
        />
        <ActionRow
          icon={<Trash2 size={17} color="#a33b3b" />}
          title="Remove photo"
          caption="Return to your initials avatar"
          danger
          busy={busy === 'remove'}
          onPress={onRemove}
        />
      </View>

      <View style={styles.cameraNote}>
        <Camera size={13} color={theme.ink400} />
        <Text style={styles.cameraNoteText}>Camera capture arrives with the next release.</Text>
      </View>
    </Screen>
  );
}

function ActionRow({
  icon,
  title,
  caption,
  onPress,
  busy,
  danger,
}: {
  icon: React.ReactNode;
  title: string;
  caption: string;
  onPress: () => void;
  busy?: boolean;
  danger?: boolean;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <Action variant="ghost" full onPress={onPress} style={styles.actionRow}>
      <View style={[styles.actionIcon, danger && styles.actionIconDanger]}>{icon}</View>
      <View style={styles.actionCopy}>
        <Text style={[styles.actionTitle, danger && styles.actionTitleDanger]}>{title}</Text>
        <Text style={styles.actionCaption}>{caption}</Text>
      </View>
      {busy ? <ActivityIndicator size="small" color={theme.brand} /> : null}
    </Action>
  );
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    previewRing: {
      width: 156,
      height: 156,
      alignSelf: 'center',
      marginTop: 20,
      marginBottom: 17,
      borderRadius: 999,
      borderWidth: 7,
      borderColor: theme.surface,
      backgroundColor: '#e3b654',
      overflow: 'hidden',
      alignItems: 'center',
      justifyContent: 'center',
      elevation: 6,
      shadowColor: '#1f3e3a',
      shadowOpacity: 0.15,
      shadowRadius: 30,
      shadowOffset: { width: 0, height: 10 },
    },
    previewImage: { width: '100%', height: '100%' },
    initials: { fontSize: 38, fontWeight: '800', color: '#ffffff', fontFamily: font.display },
    title: { fontSize: 16, fontWeight: '800', textAlign: 'center', color: theme.ink900, fontFamily: font.display },
    copy: { marginTop: 7, maxWidth: 300, alignSelf: 'center', color: theme.ink500, fontSize: 10.5, lineHeight: 16, textAlign: 'center', fontFamily: font.body },
    error: { marginTop: 10, color: '#a33b3b', fontSize: 10, textAlign: 'center', fontFamily: font.body },
    actionsCard: {
      marginTop: 17,
      borderRadius: 14,
      borderWidth: 1,
      borderColor: theme.line,
      backgroundColor: theme.surface,
      overflow: 'hidden',
    },
    actionRow: { justifyContent: 'flex-start', borderBottomWidth: 1, borderBottomColor: theme.line, borderRadius: 0 },
    actionIcon: { width: 37, height: 37, alignItems: 'center', justifyContent: 'center', borderRadius: 10, backgroundColor: theme.brand50 },
    actionIconDanger: { backgroundColor: 'rgba(163,59,59,0.12)' },
    actionCopy: { flex: 1, alignItems: 'flex-start' },
    actionTitle: { fontSize: 10.5, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    actionTitleDanger: { color: '#a33b3b' },
    actionCaption: { marginTop: 2, color: theme.ink400, fontSize: 8.5, fontFamily: font.body },
    cameraNote: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, marginTop: 16, marginBottom: 20 },
    cameraNoteText: { color: theme.ink400, fontSize: 9, fontFamily: font.body },
  });
}
