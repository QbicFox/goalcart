import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';

import TemplateBar from '../TemplateBar';
import { bool, num, str } from '../utils';
import type { GoalTemplateProps } from '../registry';

/**
 * Percentage template body — a large percent readout above the bar. Its
 * percent color/size come from the template settings (fields that Basic /
 * Milestone / Card do not share).
 */
export default function PercentageTemplateRenderer({
  goal,
  settings,
  animation,
}: GoalTemplateProps) {
  const percent = Math.max(0, Math.min(100, goal.percentage));
  const percentColor = str(settings, 'percentColor', '#2271b1');
  const percentSize = num(settings, 'percentSize', 28);
  const showBar = bool(settings, 'showBar', true);

  return (
    <Stack direction="row" spacing={1.5} sx={{ alignItems: 'center' }}>
      <Typography
        sx={{
          fontSize: percentSize,
          fontWeight: 800,
          lineHeight: 1,
          letterSpacing: '-0.02em',
          fontVariantNumeric: 'tabular-nums',
          color: percentColor,
          minWidth: '3.2em',
        }}
      >
        {Math.round(percent)}%
      </Typography>
      {showBar && (
        <TemplateBar
          settings={settings}
          percent={percent}
          completed={goal.completed}
          animation={animation}
        />
      )}
    </Stack>
  );
}
