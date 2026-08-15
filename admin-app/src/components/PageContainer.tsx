import type { ReactNode } from 'react';
import Box from '@mui/material/Box';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';

interface PageContainerProps {
  title: string;
  description?: string;
  /** Optional header actions (e.g. filters or a primary button). */
  actions?: ReactNode;
  children: ReactNode;
}

/**
 * Consistent page header + content wrapper used by every admin page.
 *
 * A clean, SaaS-style header (title + muted description + optional
 * actions) separated from the content by a hairline divider rather than
 * a boxed WP-admin title block (UICHANGES.md §5), so all routed pages
 * share the same hierarchy without the WordPress table look.
 */
export default function PageContainer({
  title,
  description,
  actions,
  children,
}: PageContainerProps) {
  return (
    <Stack spacing={3}>
      <Box
        sx={{
          display: 'flex',
          flexWrap: 'wrap',
          gap: 2,
          alignItems: { md: 'center' },
          justifyContent: 'space-between',
          pb: 2,
          borderBottom: 1,
          borderColor: 'divider',
        }}
      >
        <Box>
          <Typography variant="h5" component="h1" sx={{ fontSize: '1.5rem' }} gutterBottom>
            {title}
          </Typography>
          {description && (
            <Typography variant="body2" color="text.secondary" sx={{ maxWidth: 720 }}>
              {description}
            </Typography>
          )}
        </Box>
        {actions && (
          <Stack direction="row" spacing={1} useFlexGap sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
            {actions}
          </Stack>
        )}
      </Box>
      {children}
    </Stack>
  );
}
