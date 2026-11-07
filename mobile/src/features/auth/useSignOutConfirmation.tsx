import React, { useRef, useState } from 'react';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { useAuth } from '../../store/auth';

/** Shared account confirmation; signing out never queues a later server mutation. */
export function useSignOutConfirmation() {
  const { signOut } = useAuth();
  const [visible, setVisible] = useState(false);
  const accepted = useRef(false);
  const working = useRef(false);
  return {
    requestSignOut: () => { if (!working.current) { setVisible(true); } },
    signOutDialog: (
      <ConfirmDialog
        visible={visible}
        title="Sign out of this device?"
        message="Unsaved changes will be cleared from memory. You will need to sign in again to continue."
        confirmLabel="Sign out"
        cancelLabel="Stay signed in"
        destructive
        onConfirm={() => { accepted.current = true; setVisible(false); }}
        onCancel={() => { accepted.current = false; setVisible(false); }}
        onDismiss={() => {
          if (!accepted.current || working.current) { return; }
          accepted.current = false;
          working.current = true;
          signOut().catch(() => undefined).finally(() => { working.current = false; });
        }}
      />
    ),
  };
}
