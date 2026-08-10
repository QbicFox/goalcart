import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';

import { formatNumber } from '../../lib/format';
import TemplateBar from '../TemplateBar';
import { rewardLabel } from '../rewardLabel';
import { bool, str } from '../utils';
import type { CampaignTemplateProps } from '../registry';

/**
 * Campaign progress campaign template body — the whole campaign as one
 * readout: the campaign name, a "n / m" milestone counter and a single
 * progress bar driven by the top milestone, with the milestone rewards
 * listed as chips. Mirrors campaignProgress() in assets/js/frontend.js so
 * the admin preview equals the storefront.
 */
export default function CampaignProgressTemplateRenderer({
  campaign,
  goals,
  settings,
  animation,
}: CampaignTemplateProps) {
  const showTitle = bool(settings, 'showTitle', true);
  const showCounter = bool(settings, 'showCounter', true);
  const showRewards = bool(settings, 'showRewards', true);
  const textColor = str(settings, 'text', '#1d2327');
  const accent = str(settings, 'accent', '#2271b1');
  const bg = str(settings, 'bg', '#ffffff');
  const border = str(settings, 'border', '#dcdcde');
  const radius = settings.radius === undefined ? 10 : Number(settings.radius) || 0;

  let top: (typeof goals)[number] | null = null;
  for (const goal of goals) {
    if (!top || goal.target > top.target) {
      top = goal;
    }
  }

  const done = goals.filter((goal) => goal.completed).length;

  return (
    <Box
      sx={{
        padding: 1.25,
        border: `1px solid ${border}`,
        borderRadius: radius,
        background: bg,
        color: textColor,
      }}
    >
      {showTitle && campaign.name && (
        <Typography sx={{ fontWeight: 700, mb: 0.25 }}>{campaign.name}</Typography>
      )}
      {showCounter && (
        <Typography sx={{ fontSize: 13, color: '#646970', mb: 0.75 }}>
          {formatNumber(done)} / {formatNumber(goals.length)}
        </Typography>
      )}
      {top && (
        <TemplateBar settings={settings} percent={top.percentage} completed={top.completed} animation={animation} />
      )}
      {showRewards && goals.some((goal) => goal.reward?.type) && (
        <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.5, mt: 0.75 }}>
          {goals.map((goal, index) =>
            goal.reward?.type ? (
              <Box
                key={goal.goal_id || index}
                component="span"
                sx={{
                  display: 'inline-flex',
                  alignItems: 'center',
                  padding: '0.125rem 0.5rem',
                  borderRadius: 999,
                  fontSize: 11,
                  fontWeight: 600,
                  background: goal.completed ? 'rgba(0,163,42,0.12)' : 'rgba(34,113,177,0.10)',
                  color: goal.completed ? '#007017' : accent,
                }}
              >
                {rewardLabel(goal.reward)}
              </Box>
            ) : null
          )}
        </Box>
      )}
    </Box>
  );
}
