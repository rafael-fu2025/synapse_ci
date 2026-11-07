import React from 'react';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { useWorkspace } from './WorkspaceContext';

export function WorkspaceDiscardDialog() {
  const workspace = useWorkspace();
  return (
    <ConfirmDialog
      visible={workspace.pendingDiscard}
      title="Discard unsaved changes?"
      message={workspace.pendingDiscardWarning ?? 'Leaving will clear the unsaved changes held in memory. Choose Keep editing to return to your draft.'}
      confirmLabel="Discard and continue"
      cancelLabel="Keep editing"
      destructive
      loading={workspace.hasPendingSubmission}
      onConfirm={workspace.confirmDiscard}
      onCancel={workspace.cancelDiscard}
      onDismiss={workspace.completeDiscard}
    />
  );
}
