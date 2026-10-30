import React, { useEffect, useState } from 'react';
import { AccessibilityInfo, Animated, AppState, Easing, Image, Pressable, StyleSheet, Text, View } from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import { useQuery } from '@tanstack/react-query';
import { loginCarousel } from '../../../api/endpoints/auth';
import { Theme, font, memoizeStyles } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { FALLBACK_LOGIN_SLIDES, loginSlides } from '../loginContent';
import { useCarouselRealtime } from '../realtime/useCarouselRealtime';

const INTERVAL_MS = 6_000;

export function LoginCarousel({ active }: { active: boolean }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const [selection, setSelection] = useState<{ id: number | null; index: number }>({ id: null, index: 0 });
  const [foreground, setForeground] = useState(AppState.currentState === 'active');
  const [reduceMotion, setReduceMotion] = useState(true);
  const [screenReader, setScreenReader] = useState(true);
  const [failedImage, setFailedImage] = useState<string | null>(null);
  const query = useQuery({
    queryKey: ['auth', 'carousel'],
    queryFn: () => loginCarousel().then(loginSlides),
    staleTime: 5 * 60_000,
    retry: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  });
  useCarouselRealtime(active, foreground);
  const slides = query.data ?? FALLBACK_LOGIN_SLIDES;
  const retainedIndex = slides.findIndex(item => item.id === selection.id);
  const currentIndex = retainedIndex >= 0 ? retainedIndex : selection.index % slides.length;
  const slide = slides[currentIndex];
  const source = theme.mode === 'dark' ? slide.darkImage : slide.lightImage;
  const imageKey = JSON.stringify(source);
  const fallback = FALLBACK_LOGIN_SLIDES[currentIndex % FALLBACK_LOGIN_SLIDES.length];
  const displayedImage = failedImage === imageKey
    ? theme.mode === 'dark' ? fallback.darkImage : fallback.lightImage
    : source;

  useEffect(() => {
    setSelection(value => value.id === slide.id && value.index === currentIndex
      ? value : { id: slide.id, index: currentIndex });
  }, [slide.id, currentIndex]);

  useEffect(() => {
    let mounted = true;
    AccessibilityInfo.isReduceMotionEnabled().then(value => { if (mounted) { setReduceMotion(value); } }).catch(() => undefined);
    AccessibilityInfo.isScreenReaderEnabled().then(value => { if (mounted) { setScreenReader(value); } }).catch(() => undefined);
    const motion = AccessibilityInfo.addEventListener('reduceMotionChanged', setReduceMotion);
    const reader = AccessibilityInfo.addEventListener('screenReaderChanged', setScreenReader);
    const app = AppState.addEventListener('change', state => setForeground(state === 'active'));
    return () => {
      mounted = false;
      motion.remove();
      reader.remove();
      app.remove();
    };
  }, []);

  useEffect(() => {
    if (!active || !foreground || reduceMotion || screenReader || slides.length < 2) {
      return;
    }
    const timer = setInterval(() => setSelection(value => {
      const retained = slides.findIndex(item => item.id === value.id);
      const next = ((retained >= 0 ? retained : value.index) + 1) % slides.length;
      return { id: slides[next].id, index: next };
    }), INTERVAL_MS);
    return () => clearInterval(timer);
  }, [active, foreground, reduceMotion, screenReader, slides]);

  return (
    <View style={styles.carousel} testID="login-carousel">
      {slide.fitMode === 'contain_blur' ? (
        <Image source={displayedImage} resizeMode="cover" blurRadius={14} style={styles.image} accessible={false} />
      ) : null}
      <Image
        key={imageKey}
        source={displayedImage}
        resizeMode={slide.fitMode === 'cover' ? 'cover' : 'contain'}
        style={styles.image}
        accessible={false}
        onError={() => setFailedImage(imageKey)}
      />
      <LinearGradient colors={['transparent', 'rgba(0,0,0,0.78)']} style={styles.shade} pointerEvents="none" />
      <View style={styles.caption} pointerEvents="none">
        {slide.title ? <Text style={styles.title} numberOfLines={1}>{slide.title}</Text> : null}
        {slide.subtitle ? <Text style={styles.subtitle} numberOfLines={2}>{slide.subtitle}</Text> : null}
      </View>
      {slides.length > 1 ? (
        <View style={styles.indicators}>
          {slides.map((item, position) => (
            <Pressable
              key={item.id}
              accessibilityRole="button"
              accessibilityLabel={`Show slide ${position + 1}: ${item.title || 'University services'}`}
              accessibilityState={{ selected: currentIndex === position }}
              onPress={() => setSelection({ id: item.id, index: position })}
              style={styles.indicatorTarget}>
              <CarouselPill selected={position === currentIndex} animate={active && foreground && !reduceMotion && !screenReader} />
            </Pressable>
          ))}
        </View>
      ) : null}
    </View>
  );
}

function CarouselPill({ selected, animate }: { selected: boolean; animate: boolean }) {
  const [progress] = useState(() => new Animated.Value(selected ? 1 : 0));

  useEffect(() => {
    const toValue = selected ? 1 : 0;
    if (!animate) {
      progress.setValue(toValue);
      return;
    }
    const transition = Animated.timing(progress, {
      toValue,
      duration: 280,
      easing: Easing.out(Easing.cubic),
      useNativeDriver: true,
      isInteraction: false,
    });
    transition.start();
    return () => transition.stop();
  }, [animate, progress, selected]);

  return (
    <Animated.View
      accessible={false}
      style={[
        pillStyle,
        {
          opacity: progress.interpolate({ inputRange: [0, 1], outputRange: [0.45, 1] }),
          // Scale the fixed-width pill so its touch target and neighboring pills never shift.
          transform: [{ scaleX: progress.interpolate({ inputRange: [0, 1], outputRange: [0.3, 1] }) }],
        },
      ]}
    />
  );
}

const pillStyle = StyleSheet.create({
  pill: { width: 20, height: 4, borderRadius: 2, backgroundColor: '#ffffff' },
}).pill;

function createStyles(theme: Theme) {
  return StyleSheet.create({
    carousel: { flex: 1, overflow: 'hidden', backgroundColor: theme.surfaceWarm },
    image: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, width: '100%', height: '100%' },
    shade: { position: 'absolute', bottom: 0, left: 0, right: 0, height: '65%' },
    caption: { position: 'absolute', bottom: 34, left: 24, right: 24 },
    title: { fontFamily: font.display, fontWeight: '700', fontSize: 15, color: '#ffffff' },
    subtitle: { marginTop: 3, fontFamily: font.body, fontSize: 10.5, lineHeight: 15, color: '#f1f1f1' },
    indicators: { position: 'absolute', bottom: 0, left: 0, right: 0, flexDirection: 'row', justifyContent: 'center' },
    indicatorTarget: { minWidth: 32, minHeight: 32, alignItems: 'center', justifyContent: 'center' },

  });
}

const makeStyles = memoizeStyles(createStyles);
