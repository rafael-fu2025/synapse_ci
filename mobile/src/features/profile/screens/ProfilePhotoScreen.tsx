import React, { useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Image, Pressable, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect, useNavigation } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { ImagePlus, ShieldCheck, Trash2 } from 'lucide-react-native';
import { launchImageLibrary } from 'react-native-image-picker';
import { useQueryClient } from '@tanstack/react-query';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { ApiError, getAccessToken, request } from '../../../api/client';
import { config } from '../../../lib/config';
import { useAuth } from '../../../store/auth';
import { removePhoto, uploadPhoto } from '../../../api/endpoints/health';
import type { PhotoUpload } from '../../../api/endpoints/health';
import { Theme, font, memoizeStyles } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { ProfileStackParamList } from '../../../navigation/types';

type Props = NativeStackScreenProps<ProfileStackParamList, 'ProfilePhoto'>;

const MAX_PHOTO_BYTES = 10 * 1024 * 1024;
const PHOTO_EXTENSIONS: Record<PhotoUpload['type'], string> = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp' };
let previewSequence = 0;

export function ProfilePhotoScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Props['navigation']>();
  const { user } = useAuth();
  const client = useQueryClient();

  const [busy, setBusy] = useState<'upload' | 'remove' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [version, setVersion] = useState(0);
  const [failedUri, setFailedUri] = useState<string | null>(null);
  const [removedSession, setRemovedSession] = useState<string | null>(null);
  const mutationInFlight = useRef(false);
  const previewActive = useRef(false);
  // Native image caches may ignore Authorization. Never reuse a URL across owners or screen sessions.
  const previewSession = useMemo(() => ({
    owner: user,
    nonce: `${Date.now().toString(36)}-${++previewSequence}-${Math.random().toString(36).slice(2)}`,
  }), [user]);
  const token = getAccessToken();
  const photoUri = `${config.apiBaseUrl}/api/v1/me/photo?owner=${user?.tenant_id}-${user?.id}&session=${previewSession.nonce}&v=${version}`;
  const currentPreview = useRef({ session: previewSession, uri: photoUri });
  currentPreview.current = { session: previewSession, uri: photoUri };

  useFocusEffect(React.useCallback(() => {
    previewActive.current = previewSession.owner !== null;
    setFailedUri(null);
    // Refresh native image headers when returning after another request rotated the token.
    setVersion(current => current + 1);
    return () => { previewActive.current = false; };
  }, [previewSession]));

  async function onImageError() {
    if (!previewActive.current || currentPreview.current.uri !== photoUri) {
      return;
    }
    try {
      // Native Image does not use the API client's 401 refresh/retry path.
      await request('api/v1/me');
    } catch {
      // Fall back to initials without exposing request details or credentials.
    }
    if (!previewActive.current || currentPreview.current.uri !== photoUri) {
      return;
    }
    if (getAccessToken() !== null && getAccessToken() !== token) {
      setVersion(current => current + 1);
    } else {
      setFailedUri(photoUri);
    }
  }

  async function pickAndUpload() {
    if (!previewActive.current || mutationInFlight.current || currentPreview.current.session !== previewSession) {
      return;
    }
    mutationInFlight.current = true;
    setError(null);
    setBusy('upload');
    try {
      const result = await launchImageLibrary({ mediaType: 'photo', quality: 1, selectionLimit: 1, assetRepresentationMode: 'current' });
      if (result.didCancel || !previewActive.current || currentPreview.current.session !== previewSession) {
        return;
      }
      if (result.errorCode) {
        setError(result.errorCode === 'permission'
          ? 'Allow photo library access in your device settings, then try again.'
          : 'The photo library could not be opened. Try again.');
        return;
      }
      const asset = result.assets?.[0];
      if (!asset?.uri) {
        setError('No photo was selected. Please choose a photo again.');
        return;
      }
      const mime = asset.type?.toLowerCase() ?? '';
      if (mime !== 'image/jpeg' && mime !== 'image/png' && mime !== 'image/webp') {
        setError('Choose a JPEG, PNG, or WebP image.');
        return;
      }
      if (asset.fileSize === undefined || !Number.isFinite(asset.fileSize) || asset.fileSize <= 0) {
        setError('The photo size could not be verified. Please choose another photo.');
        return;
      }
      if (asset.fileSize > MAX_PHOTO_BYTES) {
        setError('Choose a photo no larger than 10 MB.');
        return;
      }
      await uploadPhoto({ uri: asset.uri, name: `photo.${PHOTO_EXTENSIONS[mime]}`, type: mime });
      if (currentPreview.current.session !== previewSession) {
        return;
      }
      setRemovedSession(null);
      setFailedUri(null);
      setVersion(current => current + 1);
      client.invalidateQueries({ queryKey: ['me'] }).catch(() => undefined);
    } catch (uploadError) {
      setError(uploadError instanceof ApiError ? uploadError.message : 'The photo could not be selected or uploaded. Try again.');
    } finally {
      mutationInFlight.current = false;
      setBusy(null);
    }
  }

  async function onRemove() {
    if (!previewActive.current || mutationInFlight.current || currentPreview.current.session !== previewSession) {
      return;
    }
    mutationInFlight.current = true;
    setError(null);
    setBusy('remove');
    try {
      await removePhoto();
      if (currentPreview.current.session !== previewSession) {
        return;
      }
      setRemovedSession(previewSession.nonce);
      setVersion(current => current + 1);
      client.invalidateQueries({ queryKey: ['me'] }).catch(() => undefined);
    } catch (removeError) {
      setError(removeError instanceof ApiError ? removeError.message : 'The photo could not be removed. Try again.');
    } finally {
      mutationInFlight.current = false;
      setBusy(null);
    }
  }

  return (
    <Screen>
      <PageHeader title="Profile photo" onBack={navigation.goBack} />
      <View style={styles.previewRing}>
        {!user || !token || failedUri === photoUri || removedSession === previewSession.nonce ? (
          <Text style={styles.initials}>{user?.avatar_initials ?? '··'}</Text>
        ) : (
          <Image
            key={photoUri}
            source={{ uri: photoUri, headers: { Authorization: `Bearer ${token}` }, cache: 'reload' }}
            accessibilityLabel="Your profile photo"
            style={styles.previewImage}
            resizeMode="cover"
            onError={onImageError}
          />
        )}
      </View>
      <Text style={styles.title}>Choose how you appear in SYNAPSE</Text>
      <Text style={styles.copy}>
        JPEG, PNG, or WebP up to 10 MB. This changes your personal SYNAPSE picture, not your official university ID photo.
      </Text>

      {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}

      <View style={styles.actionsCard}>
        <ActionRow
          icon={<ImagePlus size={17} color={theme.brandIcon} />}
          title="Choose from library"
          caption="JPEG, PNG, or WebP up to 10 MB"
          busy={busy === 'upload'}
          disabled={busy !== null}
          onPress={pickAndUpload}
        />
        <ActionRow
          icon={<Trash2 size={17} color="#a33b3b" />}
          title="Remove photo"
          caption="Return to your initials avatar"
          danger
          busy={busy === 'remove'}
          disabled={busy !== null}
          onPress={onRemove}
        />
      </View>

      <View style={styles.cameraNote}>
        <ShieldCheck size={16} color={theme.brandIcon} />
        <Text style={styles.cameraNoteText}>Photos are resized to fit within 1024 × 1024, compressed to WebP, and stripped of location metadata.</Text>
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
  disabled,
  danger,
}: {
  icon: React.ReactNode;
  title: string;
  caption: string;
  onPress: () => void;
  busy?: boolean;
  disabled?: boolean;
  danger?: boolean;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <Pressable accessibilityRole="button" accessibilityLabel={title} accessibilityState={{ disabled, busy }} disabled={disabled} onPress={onPress} style={[styles.actionRow, disabled && styles.actionDisabled]}>
      <View style={styles.actionIcon}>{icon}</View>
      <View style={styles.actionCopy}>
        <Text style={[styles.actionTitle, danger && styles.actionTitleDanger]}>{title}</Text>
        <Text style={styles.actionCaption}>{caption}</Text>
      </View>
      {busy ? <ActivityIndicator size="small" color={theme.brandIcon} /> : null}
    </Pressable>
  );
}

function createStyles(theme: Theme) {
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
      backgroundColor: theme.brand,
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
    actionRow: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 12, minHeight: 60, borderBottomWidth: 1, borderBottomColor: theme.line },
    actionDisabled: { opacity: 0.5 },
    actionIcon: { width: 26, height: 26, alignItems: 'center', justifyContent: 'center' },
    actionCopy: { flex: 1, alignItems: 'flex-start' },
    actionTitle: { fontSize: 10.5, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    actionTitleDanger: { color: '#a33b3b' },
    actionCaption: { marginTop: 2, color: theme.ink400, fontSize: 8.5, fontFamily: font.body },
    cameraNote: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, marginTop: 16, marginBottom: 20 },
    cameraNoteText: { flex: 1, color: theme.ink500, fontSize: 9, lineHeight: 14, fontFamily: font.body },
  });
}

const makeStyles = memoizeStyles(createStyles);
