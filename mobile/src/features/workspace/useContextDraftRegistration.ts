import { useCallback, useEffect, useId, useRef } from 'react';
import { useOptionalWorkspace } from './WorkspaceContext';

/** Personal forms participate in context guards without changing their own step navigation. */
export function useContextDraftRegistration({ dirty, submitting, onDiscard }: {
  dirty: boolean;
  submitting: boolean;
  onDiscard: () => void;
}) {
  const workspace = useOptionalWorkspace();
  const key = `personal:${useId()}`;
  const latest = useRef(onDiscard);
  latest.current = onDiscard;
  const discard = useCallback(() => latest.current(), []);
  const registerDirtyDraft = workspace?.registerDirtyDraft;
  const registerSubmission = workspace?.registerSubmission;
  useEffect(() => dirty ? registerDirtyDraft?.(key, { onDiscard: discard, scope: { context: 'personal' } }) : undefined, [dirty, discard, key, registerDirtyDraft]);
  useEffect(() => submitting ? registerSubmission?.(key, { scope: { context: 'personal' } }) : undefined, [key, registerSubmission, submitting]);
}
