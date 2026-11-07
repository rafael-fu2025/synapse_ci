import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigation, usePreventRemove, useRoute } from '@react-navigation/native';
import { useWorkspace } from './WorkspaceContext';

type WorkspaceDraftOptions = {
  key: string;
  dirty: boolean;
  submitting?: boolean;
  onDiscard: () => void;
  warning?: string;
};

/** One guard for native back/removal, explicit leave actions, and context changes. */
export function useWorkspaceDraft({ key, dirty, submitting = false, onDiscard, warning }: WorkspaceDraftOptions) {
  const navigation = useNavigation();
  const route = useRoute();
  const { activeUnit, registerDirtyDraft, registerSubmission, requestDiscard } = useWorkspace();
  const source = `${route.key}:${key}`;
  const origin = useRef({ source, unit: activeUnit?.unit ?? null });
  if (origin.current.source !== source || (origin.current.unit === null && activeUnit !== null)) {
    origin.current = { source, unit: activeUnit?.unit ?? null };
  }
  const originatingUnit = origin.current.unit;
  const latest = useRef({ onDiscard, submitting });
  latest.current = { onDiscard, submitting };
  const releaseDraft = useRef<(() => void) | null>(null);
  const releaseSubmission = useRef<(() => void) | null>(null);
  const bypass = useRef(false);
  const [released, setReleased] = useState(false);
  const wasDirty = useRef(dirty);

  const markSaved = useCallback(() => {
    bypass.current = true;
    releaseDraft.current?.();
    releaseSubmission.current?.();
    releaseDraft.current = null;
    releaseSubmission.current = null;
    setReleased(true);
  }, []);

  const discard = useCallback(() => {
    markSaved();
    latest.current.onDiscard();
  }, [markSaved]);

  useEffect(() => {
    if ((!dirty && !submitting) || (dirty && !wasDirty.current)) {
      bypass.current = false;
      setReleased(false);
    }
    wasDirty.current = dirty;
  }, [dirty, submitting]);

  useEffect(() => {
    if (!dirty || released) { return undefined; }
    const unregister = registerDirtyDraft(source, { onDiscard: discard, warning, scope: { context: 'workspace', unit: originatingUnit } });
    releaseDraft.current = unregister;
    return () => {
      unregister();
      if (releaseDraft.current === unregister) { releaseDraft.current = null; }
    };
  }, [dirty, discard, originatingUnit, registerDirtyDraft, released, source, warning]);

  useEffect(() => {
    if (!submitting || released) { return undefined; }
    const unregister = registerSubmission(source, { scope: { context: 'workspace', unit: originatingUnit } });
    releaseSubmission.current = unregister;
    return () => {
      unregister();
      if (releaseSubmission.current === unregister) { releaseSubmission.current = null; }
    };
  }, [originatingUnit, registerSubmission, released, source, submitting]);

  const requestLeave = useCallback((action: () => void) => {
    if (latest.current.submitting && !bypass.current) { return; }
    if (bypass.current) { action(); return; }
    requestDiscard(() => {
      markSaved();
      action();
    }, source);
  }, [markSaved, requestDiscard, source]);

  usePreventRemove(!released && (dirty || submitting), ({ data }) => {
    if (bypass.current) {
      navigation.dispatch(data.action);
    } else if (!latest.current.submitting) {
      requestLeave(() => navigation.dispatch(data.action));
    }
  });

  return { requestLeave, markSaved };
}
