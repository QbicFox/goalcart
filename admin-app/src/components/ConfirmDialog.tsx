import type { ReactNode } from 'react';
import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogContentText from '@mui/material/DialogContentText';
import DialogTitle from '@mui/material/DialogTitle';
import { __ } from '@wordpress/i18n';

interface ConfirmDialogProps {
  open: boolean;
  title: string;
  description?: ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  /** Danger-styled confirm button (deletes, destructive actions). */
  destructive?: boolean;
  /** Shows a busy state on the confirm button while an action runs. */
  busy?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}

/**
 * Reusable confirmation dialog (delete / destructive actions).
 *
 * Renders a MUI Dialog with a title, optional description and
 * confirm/cancel actions. The confirm button turns into a spinner while
 * `busy` is set, and the dialog cannot be dismissed while busy.
 */
export default function ConfirmDialog({
  open,
  title,
  description,
  confirmLabel,
  cancelLabel,
  destructive,
  busy,
  onConfirm,
  onCancel,
}: ConfirmDialogProps) {
  return (
    <Dialog
      open={open}
      onClose={busy ? undefined : onCancel}
      maxWidth="xs"
      fullWidth
      role="alertdialog"
      aria-labelledby="goalcart-confirm-title"
    >
      <DialogTitle id="goalcart-confirm-title">{title}</DialogTitle>
      {description !== undefined && (
        <DialogContent>
          <DialogContentText>{description}</DialogContentText>
        </DialogContent>
      )}
      <DialogActions>
        <Button onClick={onCancel} disabled={busy}>
          {cancelLabel ?? __('Cancel', 'goalcart')}
        </Button>
        <Button
          onClick={onConfirm}
          color={destructive ? 'error' : 'primary'}
          variant="contained"
          disabled={busy}
        >
          {confirmLabel ?? __('Confirm', 'goalcart')}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
