/* eslint-disable react-refresh/only-export-components -- a context module
   conventionally exports both the provider component and the hook that
   consumes it. */

import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react';
import Alert from '@mui/material/Alert';
import Snackbar from '@mui/material/Snackbar';
import type { AlertColor } from '@mui/material/Alert';

interface SnackbarState {
  message: string;
  severity: AlertColor;
}

interface SnackbarContextValue {
  notify: (message: string, severity?: AlertColor) => void;
}

const SnackbarContext = createContext<SnackbarContextValue | null>(null);

/**
 * Shared notification provider.
 *
 * Pages and mutations call `useSnackbar().notify(message, severity)` and
 * a single MUI Snackbar (bottom of the viewport, above the WP admin
 * chrome) shows the latest message with an auto-hide timer.
 *
 * Note: the reference plugin renders a local Snackbar per page; this
 * shared provider is the FaraCart foundation variant so any page or
 * mutation can raise notifications without wiring its own Snackbar.
 */
export function SnackbarProvider({ children }: { children: ReactNode }) {
  const [snackbar, setSnackbar] = useState<SnackbarState | null>(null);

  const notify = useCallback((message: string, severity: AlertColor = 'success') => {
    setSnackbar({ message, severity });
  }, []);

  const value = useMemo(() => ({ notify }), [notify]);

  return (
    <SnackbarContext.Provider value={value}>
      {children}
      <Snackbar
        open={snackbar !== null}
        autoHideDuration={4000}
        onClose={() => setSnackbar(null)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert
          severity={snackbar?.severity ?? 'success'}
          variant="filled"
          onClose={() => setSnackbar(null)}
        >
          {snackbar?.message}
        </Alert>
      </Snackbar>
    </SnackbarContext.Provider>
  );
}

/** Raise a notification from anywhere inside the provider. */
export function useSnackbar(): SnackbarContextValue {
  const context = useContext(SnackbarContext);

  if (!context) {
    throw new Error('useSnackbar must be used within a SnackbarProvider.');
  }

  return context;
}
