import Box from '@mui/material/Box';

import type { TemplateSettingsValue } from '../types';
import { num, str } from './utils';

interface TemplateBarProps {
  /** Resolved template settings (drives accent + bar height). */
  settings: TemplateSettingsValue | undefined;
  percent: number;
  completed: boolean;
  animation: boolean;
}

/**
 * The percentage fill bar (mirrors .faracart-progress markup). Reads the
 * resolved template settings: `barHeight` controls the thickness and
 * `accent` the fill color — the same CSS custom properties the storefront
 * sets per card.
 */
export default function TemplateBar({ settings, percent, completed, animation }: TemplateBarProps) {
  const clamped = Math.max(0, Math.min(100, percent));
  const height = num(settings, 'barHeight', 10);
  const accent = str(settings, 'accent', '#2271b1');

  return (
    <Box
      sx={{
        position: 'relative',
        height,
        background: '#f0f0f1',
        borderRadius: 999,
        overflow: 'hidden',
        flex: '1 1 auto',
      }}
    >
      <Box
        sx={{
          position: 'absolute',
          insetInlineStart: 0,
          insetBlockStart: 0,
          height: '100%',
          width: `${clamped}%`,
          background: completed ? '#00a32a' : accent,
          borderRadius: 'inherit',
          transition: animation ? 'width 0.45s ease' : 'none',
        }}
      />
    </Box>
  );
}
