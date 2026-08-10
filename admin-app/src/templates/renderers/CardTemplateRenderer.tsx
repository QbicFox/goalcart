import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';

import TemplateBar from '../TemplateBar';
import { str } from '../utils';
import type { GoalTemplateProps } from '../registry';

/**
 * Card template body — icon + goal title header above the bar. The icon
 * falls back to the template's `icon` setting when the goal has none;
 * the reward chip + message toggles live in the shared PreviewWidget
 * chrome.
 */
export default function CardTemplateRenderer({
  goal,
  settings,
  animation,
}: GoalTemplateProps) {
  const fallbackIcon = str(settings, 'icon', '🎯');

  return (
    <Box sx={{ display: 'flex', alignItems: 'center', gap: '0.625rem', flexWrap: 'wrap' }}>
      <Typography sx={{ fontSize: 24, lineHeight: 1 }} aria-hidden>
        {goal.icon || fallbackIcon}
      </Typography>
      <Typography sx={{ fontWeight: 700, flex: '1 1 auto', minWidth: 0 }}>
        {goal.goal_name}
      </Typography>
      <Box sx={{ flex: '0 0 100%' }}>
        <TemplateBar
          settings={settings}
          percent={goal.percentage}
          completed={goal.completed}
          animation={animation}
        />
      </Box>
    </Box>
  );
}
