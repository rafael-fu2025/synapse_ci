import { useEffect, useState } from 'react';
import { Keyboard, Platform } from 'react-native';

/** Keep the safe-bottom inset suppressed until the keyboard has actually hidden. */
export function useKeyboardVisible(): boolean {
  const [visible, setVisible] = useState(() => Keyboard.isVisible());

  useEffect(() => {
    let mounted = true;
    let changed = false;
    const update = (next: boolean) => {
      changed = true;
      if (mounted) { setVisible(next); }
    };
    const show = Keyboard.addListener(Platform.OS === 'ios' ? 'keyboardWillShow' : 'keyboardDidShow', () => update(true));
    const hide = Keyboard.addListener('keyboardDidHide', () => update(false));
    // Cover a keyboard change between render and subscribing without overriding an event.
    if (!changed) { setVisible(Keyboard.isVisible()); }
    return () => {
      mounted = false;
      show.remove();
      hide.remove();
    };
  }, []);

  return visible;
}
