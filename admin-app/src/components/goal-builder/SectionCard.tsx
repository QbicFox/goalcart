import type { ReactNode } from 'react';
import Box from '@mui/material/Box';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';

interface SectionCardProps {
  title: string;
  description?: string;
  children: ReactNode;
  /** Optional trailing header action (e.g. a "clear" button). */
  action?: ReactNode;
}

/**
 * Goal builder section wrapper: a titled Paper card that groups one
 * builder step (Basic Information, Goal Type, Target, Reward, Conditions,
 * Display, Priority) so the form reads as a series of focused steps.
 */
export default function SectionCard({ title, description, children, action }: SectionCardProps) {
  return (
    <Paper variant="outlined" sx={{ p: { xs: 2.5, md: 3 } }}>
      <Box
        sx={{
          display: 'flex',
          alignItems: 'flex-start',
          justifyContent: 'space-between',
          gap: 2,
          mb: description ? 0.5 : 2,
        }}
      >
        <Box>
          <Typography variant="h6" component="h3" gutterBottom={!description}>
            {title}
          </Typography>
          {description && (
            <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
              {description}
            </Typography>
          )}
        </Box>
        {action}
      </Box>
      {children}
    </Paper>
  );
}
