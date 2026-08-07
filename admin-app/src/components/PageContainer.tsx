import type { ReactNode } from 'react';
import Box from '@mui/material/Box';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';

interface PageContainerProps {
  title: string;
  description?: string;
  /** Optional header actions (e.g. a primary button). */
  actions?: ReactNode;
  children: ReactNode;
}

/**
 * Consistent page header + content wrapper used by every admin page.
 *
 * Provides the WP-admin-style title block (heading + muted description +
 * optional actions) so all routed pages share the same hierarchy, then
 * renders the page content below.
 */
export default function PageContainer({
  title,
  description,
  actions,
  children,
}: PageContainerProps) {
  return (
    <Stack spacing={3}>
      <Paper variant="outlined" sx={{ p: { xs: 2.5, md: 3 } }}>
        <Stack
          direction={{ xs: 'column', md: 'row' }}
          spacing={2}
          alignItems={{ md: 'center' }}
          justifyContent="space-between"
        >
          <Box>
            <Typography variant="h5" component="h2" gutterBottom>
              {title}
            </Typography>
            {description && (
              <Typography variant="body2" color="text.secondary">
                {description}
              </Typography>
            )}
          </Box>
          {actions && (
            <Stack direction="row" spacing={1} alignItems="center">
              {actions}
            </Stack>
          )}
        </Stack>
      </Paper>
      {children}
    </Stack>
  );
}
