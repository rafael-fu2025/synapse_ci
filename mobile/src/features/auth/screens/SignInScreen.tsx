import React, { useEffect, useRef, useState } from 'react';
import { Image, Keyboard, KeyboardAvoidingView, Linking, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Eye, EyeOff, ShieldCheck, TriangleAlert } from 'lucide-react-native';
import { Action } from '../../../components/Action';
import { TextField } from '../../../components/TextField';
import { ApiError, NetworkError } from '../../../api/client';
import { useAuth } from '../../../store/auth';
import { Theme, font, type, memoizeStyles } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { LoginCarousel } from '../components/LoginCarousel';
import { LOGIN_SOCIAL_LINKS } from '../loginContent';
import { useSignInCooldown } from '../useSignInCooldown';

export function SignInScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const { signIn } = useAuth();

  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [passwordVisible, setPasswordVisible] = useState(false);
  const [keyboardVisible, setKeyboardVisible] = useState(() => Keyboard.isVisible());
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [notice, setNotice] = useState<string | null>(null);
  const [linkError, setLinkError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const passwordInput = useRef<React.ComponentRef<typeof TextInput>>(null);
  const submittingRef = useRef(false);
  const mounted = useRef(true);
  const { remainingSeconds, startCooldown, isCoolingDown } = useSignInCooldown();
  const displayedNotice = remainingSeconds > 0
    ? `Too many sign-in attempts. Please try again in ${Math.floor(remainingSeconds / 60)}:${String(remainingSeconds % 60).padStart(2, '0')}.`
    : notice;

  useEffect(() => {
    mounted.current = true;
    const show = Keyboard.addListener(Platform.OS === 'ios' ? 'keyboardWillShow' : 'keyboardDidShow', () => setKeyboardVisible(true));
    const hide = Keyboard.addListener('keyboardDidHide', () => setKeyboardVisible(false));
    return () => {
      mounted.current = false;
      show.remove();
      hide.remove();
    };
  }, []);

  async function onSubmit() {
    if (submittingRef.current || isCoolingDown()) {
      return;
    }
    const nextErrors: Record<string, string[]> = {};
    if (identifier.trim() === '') {
      nextErrors.identifier = ['Enter your ID number or university email.'];
    }
    if (password === '') {
      nextErrors.password = ['Enter your password.'];
    }
    setFieldErrors(nextErrors);
    setNotice(null);
    if (Object.keys(nextErrors).length > 0) {
      return;
    }
    submittingRef.current = true;
    setSubmitting(true);
    try {
      await signIn(identifier.trim(), password);
    } catch (error) {
      if (!mounted.current) {
        return;
      }
      if (error instanceof ApiError) {
        if (error.status === 429) {
          setFieldErrors({});
          setNotice('Too many sign-in attempts. Please try again.');
          startCooldown(error.retryAfterSeconds);
        } else {
          setFieldErrors(error.fields);
          setNotice(error.fields.identifier || error.fields.password ? null : error.message);
        }
      } else if (error instanceof NetworkError) {
        setNotice(error.message);
      } else {
        setNotice('Sign-in is unavailable right now. Please try again.');
      }
    } finally {
      submittingRef.current = false;
      if (mounted.current) {
        setSubmitting(false);
      }
    }
  }

  async function openSocial(url: (typeof LOGIN_SOCIAL_LINKS)[number]['url']) {
    setLinkError(null);
    try {
      await Linking.openURL(url);
    } catch {
      if (mounted.current) {
        setLinkError('Facebook could not be opened. Please try again.');
      }
    }
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
        <View
          testID="login-carousel-row"
          style={[styles.carouselRow, keyboardVisible && styles.hiddenCarousel]}
          accessibilityElementsHidden={keyboardVisible}
          importantForAccessibility={keyboardVisible ? 'no-hide-descendants' : 'auto'}>
          <LoginCarousel active={!keyboardVisible} />
        </View>

        <ScrollView
          style={styles.formRow}
          contentContainerStyle={[styles.formContent, keyboardVisible && styles.keyboardContent]}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="on-drag">
          <View style={styles.form} testID="login-form">
            <View style={styles.heading}>
              <Image
                source={theme.mode === 'dark'
                  ? require('../../../assets/brand/synapse-white.png')
                  : require('../../../assets/brand/synapse-maroon.png')}
                style={styles.logo}
                resizeMode="contain"
                accessible={false}
              />
              <View style={styles.headingCopy}>
                <Text style={styles.title}>Welcome back</Text>
                <Text style={styles.subtitle}>Sign in with your university ID and MIS password.</Text>
              </View>
            </View>

            {displayedNotice !== null ? (
              <View style={styles.errorBox}>
                <TriangleAlert size={15} color="#a33b3b" />
                <Text style={styles.errorText} accessibilityRole="alert">{displayedNotice}</Text>
              </View>
            ) : null}

            <TextField
              label="ID number"
              placeholder="Enter your ID number"
              autoCapitalize="none"
              autoCorrect={false}
              autoComplete="username"
              textContentType="username"
              keyboardType="email-address"
              returnKeyType="next"
              submitBehavior="submit"
              onSubmitEditing={() => passwordInput.current?.focus()}
              value={identifier}
              onChangeText={setIdentifier}
              editable={!submitting}
              error={fieldErrors.identifier?.[0]}
            />
            <TextField
              inputRef={passwordInput}
              label="Password"
              placeholder="Enter your password"
              secureTextEntry={!passwordVisible}
              autoCapitalize="none"
              autoCorrect={false}
              autoComplete="current-password"
              textContentType="password"
              returnKeyType="go"
              onSubmitEditing={onSubmit}
              value={password}
              onChangeText={setPassword}
              editable={!submitting}
              error={fieldErrors.password?.[0]}
              trailing={
                <Pressable
                  onPress={() => {
                    setPasswordVisible(value => !value);
                    passwordInput.current?.focus();
                  }}
                  disabled={submitting}
                  accessibilityRole="button"
                  accessibilityLabel={passwordVisible ? 'Hide password' : 'Show password'}
                  accessibilityState={{ disabled: submitting }}
                  style={styles.eyeButton}>
                  {passwordVisible ? <EyeOff size={19} color={theme.ink500} /> : <Eye size={19} color={theme.ink500} />}
                </Pressable>
              }
            />

            <View style={styles.actionWrap}>
              <Action full onPress={onSubmit} loading={submitting} disabled={remainingSeconds > 0}>
                Sign in
              </Action>
            </View>

            <View style={styles.socialHeading}>
              <View style={styles.divider} />
              <Text style={styles.socialTitle}>CONNECT WITH US</Text>
              <View style={styles.divider} />
            </View>
            <View style={styles.socials}>
              {LOGIN_SOCIAL_LINKS.map(link => (
                <Pressable
                  key={link.url}
                  accessibilityRole="link"
                  accessibilityLabel={link.accessibilityLabel}
                  accessibilityHint="Opens Facebook outside SYNAPSE"
                  onPress={() => { openSocial(link.url).catch(() => undefined); }}
                  style={({ pressed }) => [styles.socialLink, pressed && styles.pressed]}>
                  <Image source={link.image} style={styles.socialLogo} resizeMode="contain" accessible={false} />
                  <Text style={styles.socialLabel}>{link.label}</Text>
                </Pressable>
              ))}
            </View>
            {linkError ? <Text style={styles.linkError} accessibilityRole="alert">{linkError}</Text> : null}

            <View style={styles.secureNote}>
              <ShieldCheck size={13} color={theme.brandIcon} />
              <Text style={styles.secureText}>SYNAPSE does not save your MIS password.</Text>
            </View>
            <Text style={styles.help}>Trouble signing in? Contact your system administrator.</Text>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    safe: { flex: 1, backgroundColor: theme.surface },
    flex: { flex: 1 },
    // ScrollView supplies flexGrow:1, so shorthand flex weights do not enforce a 30/70 split.
    // Reserve 30% explicitly; the scrollable form takes the remainder (all space when hidden).
    carouselRow: { height: '30%', flexGrow: 0, flexShrink: 0 },
    hiddenCarousel: { display: 'none' },
    formRow: { flexBasis: 0, flexGrow: 1, flexShrink: 1, minHeight: 0 },
    formContent: { flexGrow: 1, justifyContent: 'center', paddingHorizontal: 24, paddingVertical: 18 },
    keyboardContent: { justifyContent: 'flex-start', paddingVertical: 12 },
    form: { width: '100%', maxWidth: 420, alignSelf: 'center' },
    heading: { flexDirection: 'row', alignItems: 'center', gap: 12, marginBottom: 2 },
    headingCopy: { flex: 1 },
    logo: { width: 44, height: 44 },
    title: { ...type.hero, color: theme.ink900 },
    subtitle: { marginTop: 3, color: theme.ink500, fontSize: 11.5, lineHeight: 16, fontFamily: font.body },
    eyeButton: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
    actionWrap: { marginTop: 20 },
    socialHeading: { flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 22, marginBottom: 12 },
    divider: { flex: 1, height: 1, backgroundColor: theme.line },
    socialTitle: { color: theme.ink500, fontSize: 9, fontWeight: '700', letterSpacing: 1.1, fontFamily: font.body },
    socials: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-evenly', gap: 10 },
    socialLink: { minWidth: 76, minHeight: 64, alignItems: 'center', gap: 5, paddingVertical: 2 },
    socialLogo: { width: 42, height: 42 },
    socialLabel: { color: theme.ink700, fontSize: 10, fontWeight: '600', fontFamily: font.body },
    pressed: { opacity: 0.7 },
    secureNote: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, marginTop: 17 },
    secureText: { color: theme.ink500, fontSize: 9, fontFamily: font.body },
    help: { marginTop: 7, textAlign: 'center', color: theme.ink500, fontSize: 9, lineHeight: 14, fontFamily: font.body },
    linkError: { marginTop: 10, textAlign: 'center', color: '#a33b3b', fontSize: 10, fontFamily: font.body },
    errorBox: { flexDirection: 'row', alignItems: 'flex-start', gap: 8, marginTop: 14, padding: 10, borderRadius: 10, backgroundColor: theme.mode === 'dark' ? theme.brand50 : '#fae8e6' },
    errorText: { flex: 1, color: theme.mode === 'dark' ? '#f4a7a7' : '#a33b3b', fontSize: 10.5, lineHeight: 15, fontFamily: font.body },
  });
}

const makeStyles = memoizeStyles(createStyles);
