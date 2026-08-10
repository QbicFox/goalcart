import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';

import { formatCurrency, formatNumber } from '../../lib/format';
import TemplateBar from '../TemplateBar';
import { rewardLabel } from '../rewardLabel';
import { bool, str } from '../utils';
import type { CampaignTemplateProps } from '../registry';

/**
 * Milestone chain campaign template body — the campaign's milestones as
 * one connected ladder (dots + names + targets + rewards) with an overall
 * progress bar driven by the top milestone. Mirrors campaignChain() in
 * assets/js/frontend.js so the admin preview equals the storefront.
 */
export default function MilestoneChainTemplateRenderer({
  campaign,
  goals,
  currency,
  settings,
  animation,
}: CampaignTemplateProps) {
  const showLabels = bool(settings, 'showLabels', true);
  const showTargets = bool(settings, 'showTargets', true);
  const showRewards = bool(settings, 'showRewards', true);
  const dotColor = str(settings, 'dotColor', '#dcdcde');
  const doneColor = str(settings, 'doneColor', '#00a32a');
  const connectorColor = str(settings, 'connectorColor', '#dcdcde');

  let top: (typeof goals)[number] | null = null;
  for (const goal of goals) {
    if (!top || goal.target > top.target) {
      top = goal;
    }
  }

  const overallPercent = top ? Math.max(0, Math.min(100, top.percentage)) : 0;
  const allDone = goals.length > 0 && goals.every((goal) => goal.completed);

  return (
    <Box>
      {campaign.name && (
        <Typography sx={{ fontWeight: 700, mb: 0.75 }}>{campaign.name}</Typography>
      )}
      <Box
        component="ol"
        sx={{
          display: 'flex',
          alignItems: 'flex-start',
          margin: '0 0 0.875rem',
          padding: 0,
          listStyle: 'none',
        }}
      >
        {goals.map((goal, index) => {
          const done = goal.completed;
          return (
            <Box
              component="li"
              key={goal.goal_id || index}
              sx={{
                position: 'relative',
                flex: '1 1 0',
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                gap: 0.25,
                minWidth: 0,
                textAlign: 'center',
                // The connector line runs behind the dots (logical
                // inline-start → end, mirroring automatically in RTL).
                '&:not(:first-of-type)::before': {
                  content: '""',
                  position: 'absolute',
                  insetInlineStart: '-50%',
                  insetInlineEnd: '50%',
                  insetBlockStart: 5,
                  height: 2,
                  background: connectorColor,
                  zIndex: 0,
                },
              }}
            >
              <Box
                sx={{
                  position: 'relative',
                  zIndex: 1,
                  width: 12,
                  height: 12,
                  borderRadius: '50%',
                  background: done ? doneColor : dotColor,
                  transition: 'background 0.3s ease',
                }}
              />
              {showLabels && (
                <Typography sx={{ fontSize: 12, fontWeight: 600, lineHeight: 1.3 }} noWrap>
                  {goal.goal_name}
                </Typography>
              )}
              {showTargets && (
                <Typography sx={{ fontSize: 12, color: '#646970', lineHeight: 1.3 }}>
                  {goal.is_money
                    ? formatCurrency(goal.target, currency)
                    : formatNumber(goal.target)}
                </Typography>
              )}
              {showRewards && goal.reward?.type && (
                <Box
                  component="span"
                  sx={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    marginBlockStart: 0.125,
                    padding: '0.125rem 0.5rem',
                    borderRadius: 999,
                    fontSize: 11,
                    fontWeight: 600,
                    background: done ? 'rgba(0,163,42,0.12)' : '#f0f0f1',
                    color: done ? '#007017' : '#646970',
                  }}
                >
                  {rewardLabel(goal.reward)}
                </Box>
              )}
            </Box>
          );
        })}
      </Box>
      <TemplateBar settings={settings} percent={overallPercent} completed={allDone} animation={animation} />
    </Box>
  );
}
