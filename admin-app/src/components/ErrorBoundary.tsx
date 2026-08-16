import { Component, type ErrorInfo, type ReactNode } from 'react';
import RefreshIcon from '@mui/icons-material/Refresh';
import Alert from '@mui/material/Alert';
import AlertTitle from '@mui/material/AlertTitle';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import { __ } from '@wordpress/i18n';

interface ErrorBoundaryProps {
  children: ReactNode;
}

interface ErrorBoundaryState {
  error: Error | null;
}

/**
 * Catches render errors anywhere below it and shows a friendly fallback
 * instead of unmounting the whole dashboard. A "Try again" button resets
 * the boundary so the section can re-render.
 *
 * Mirrors the reference plugin (WooInsights\ErrorBoundary).
 */
export default class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  state: ErrorBoundaryState = { error: null };

  static getDerivedStateFromError(error: Error): ErrorBoundaryState {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // Keep the console useful for debugging without breaking the UI.
    console.error('FaraCart admin section failed to render:', error, info);
  }

  private handleRetry = (): void => {
    this.setState({ error: null });
  };

  render(): ReactNode {
    if (!this.state.error) {
      return this.props.children;
    }

    return (
      <Box sx={{ py: 4 }}>
        <Alert severity="error" variant="outlined">
          <AlertTitle>{__('Something went wrong', 'faracart')}</AlertTitle>
          <Box component="pre" sx={{ m: 0, mb: 1.5, whiteSpace: 'pre-wrap', fontSize: 13 }}>
            {this.state.error.message}
          </Box>
          <Button
            variant="contained"
            size="small"
            startIcon={<RefreshIcon />}
            onClick={this.handleRetry}
          >
            {__('Try again', 'faracart')}
          </Button>
        </Alert>
      </Box>
    );
  }
}
