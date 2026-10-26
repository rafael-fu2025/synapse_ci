import React, { useState } from 'react';
import { Image, KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { ShieldCheck, TriangleAlert } from 'lucide-react-native';
import { Action } from '../../../components/Action';
import { TextField } from '../../../components/TextField';
import { ApiError, NetworkError } from '../../../api/client';
import { useAuth } from '../../../store/auth';
import { Theme, font, type } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';

export function SignInScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const { signIn } = useAuth();

  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [notice, setNotice] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function onSubmit() {
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
    setSubmitting(true);
    try {
      await signIn(identifier.trim(), password);
    } catch (error) {
      if (error instanceof ApiError) {
        setFieldErrors(error.fields);
        setNotice(error.fields.identifier || error.fields.password ? null : error.message);
      } else if (error instanceof NetworkError) {
        setNotice(error.message);
      } else {
        setNotice('Sign-in is unavailable right now. Please try again.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <KeyboardAvoidingView behavior={Platform.OS === 'android' ? undefined : 'padding'} style={styles.flex}>
        <ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
          <View style={styles.hero}>
            <Image source={require('../../../assets/brand/synapse-maroon.png')} style={styles.logo} resizeMode="contain" />
            <Text style={styles.brandName}>SYNAPSE</Text>
            <Text style={styles.tagline}>University Health &amp; Guidance Portal</Text>
          </View>

          <View style={styles.card}>
            <Text style={styles.cardTitle}>Welcome back</Text>
            <Text style={styles.cardSubtitle}>Sign in with your university ID and MIS password.</Text>

            {notice !== null ? (
              <View style={styles.errorBox}>
                <TriangleAlert size={15} color="#a33b3b" />
                <Text style={styles.errorText}>{notice}</Text>
              </View>
            ) : null}

            <TextField
              label="ID number or email"
              autoCapitalize="none"
              autoCorrect={false}
              keyboardType="email-address"
              value={identifier}
              onChangeText={setIdentifier}
              editable={!submitting}
              error={fieldErrors.identifier?.[0]}
            />
            <TextField
              label="Password"
              secureTextEntry
              value={password}
              onChangeText={setPassword}
              editable={!submitting}
              error={fieldErrors.password?.[0]}
            />

            <View style={styles.actionWrap}>
              <Action full onPress={onSubmit} loading={submitting}>
                Sign in
              </Action>
            </View>

            <View style={styles.secureNote}>
              <ShieldCheck size={13} color={theme.brand} />
              <Text style={styles.secureText}>Secured by university single sign-in</Text>
            </View>
          </View>

          <View style={styles.footer}>
            <Image source={require('../../../assets/brand/FoundationU.png')} style={styles.footerMark} />
            <Text style={styles.footerText}>Foundation University · Dumaguete City</Text>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    safe: { flex: 1, backgroundColor: theme.brand50 },
    flex: { flex: 1 },
    scroll: { flexGrow: 1, justifyContent: 'center', paddingHorizontal: 22, paddingVertical: 24 },
    hero: { alignItems: 'center', paddingBottom: 28, paddingTop: 14 },
    logo: { width: 180, height: 64 },
    brandName: { marginTop: 15, ...type.hero, fontSize: 23, letterSpacing: 3, color: theme.brand, fontFamily: font.display },
    tagline: { marginTop: 4, color: theme.ink500, fontSize: 11, fontFamily: font.body },
    card: {
      padding: 22,
      borderRadius: 21,
      backgroundColor: theme.surface,
      borderColor: theme.line,
      borderWidth: 1,
      shadowColor: theme.shadowColor,
      shadowOpacity: 0.11,
      shadowRadius: 30,
      shadowOffset: { width: 0, height: 12 },
      elevation: 6,
    },
    cardTitle: { ...type.hero, color: theme.ink900 },
    cardSubtitle: { marginTop: 5, marginBottom: 18, color: theme.ink500, fontSize: 11.5, fontFamily: font.body },
    errorBox: {
      flexDirection: 'row',
      alignItems: 'flex-start',
      gap: 8,
      marginBottom: 12,
      padding: 10,
      borderRadius: 10,
      backgroundColor: '#fae8e6',
    },
    errorText: { flex: 1, color: '#a33b3b', fontSize: 9.5, lineHeight: 13, fontFamily: font.body },
    actionWrap: { marginTop: 18 },
    secureNote: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 7, marginTop: 13 },
    secureText: { color: theme.ink500, fontSize: 9, fontFamily: font.body },
    footer: { alignItems: 'center', gap: 8, paddingTop: 22 },
    footerMark: { width: 38, height: 38, borderRadius: 19 },
    footerText: { color: theme.ink500, fontSize: 9, fontFamily: font.body },
  });
}
