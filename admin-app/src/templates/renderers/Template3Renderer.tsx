import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import LocalShippingIcon from '@mui/icons-material/LocalShipping';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import CircularProgress from '@mui/material/CircularProgress';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';

import type { GoalTemplateProps } from '../registry';
import { bool, num, str } from '../utils';
import { GoalIcon, formatGoalAmount, goalPercent } from './goalShared';

/**
 * Template 3 — Circular Progress (Concept 03).
 *
 * A circular gauge with the percentage centered inside, beside the goal
 * icon, title, description and the current/remaining amounts, plus a CTA.
 * Uses MUI CircularProgress (the project's own component) rather than
 * hand-rolled SVG. The completed state draws a full green ring with a
 * check.
 */
export default function Template3Renderer({ goal, currency, settings, animation }: GoalTemplateProps) {
  const percent = goalPercent(goal);
  const accent = str(settings, 'accent', '#6366f1');
  const text = str(settings, 'text', '#1f2937');
  const muted = str(settings, 'secondaryText', '#6b7280');
  const size = num(settings, 'ringSize', 100);
  const thickness = num(settings, 'strokeWidth', 8);

  if (goal.completed) {
    return (
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
        <Box sx={{ position: 'relative', width: size * 0.8, height: size * 0.8, flexShrink: 0 }}>
          <CircularProgress
            variant="determinate"
            value={100}
            size={size * 0.8}
            thickness={thickness}
            sx={{ color: '#10b981' }}
          />
          <Box
            sx={{
              position: 'absolute',
              inset: 0,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            <CheckCircleIcon sx={{ fontSize: size * 0.28, color: '#059669' }} />
          </Box>
        </Box>
        <Box>
          <Typography sx={{ fontSize: 14, fontWeight: 800, color: '#166534' }}>
            {__('Congratulations!', 'faracart')} 🎉
          </Typography>
          <Typography sx={{ fontSize: 12, color: '#15803d' }}>{goal.goal_name}</Typography>
        </Box>
      </Box>
    );
  }

  const showDescription = bool(settings, 'showDescription', true);

  return (
    <Box>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2.5 }}>
        {/* Circular gauge with the percentage centered inside */}
        <Box sx={{ position: 'relative', width: size, height: size, flexShrink: 0 }}>
          <CircularProgress
            variant="determinate"
            value={percent}
            size={size}
            thickness={thickness}
            sx={{ color: accent, transition: animation ? 'stroke-dashoffset 0.6s ease' : 'none' }}
          />
          {bool(settings, 'showPercent', true) && (
            <Box
              sx={{
                position: 'absolute',
                inset: 0,
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <Typography
                sx={{
                  fontSize: size * 0.19,
                  fontWeight: 900,
                  lineHeight: 1,
                  color: accent,
                  fontVariantNumeric: 'tabular-nums',
                }}
              >
                {Math.round(percent)}%
              </Typography>
              <Typography sx={{ fontSize: size * 0.085, fontWeight: 500, color: muted, mt: 0.25 }}>
                {__('Progress', 'faracart')}
              </Typography>
            </Box>
          )}
        </Box>

        {/* Goal info */}
        <Box sx={{ flex: '1 1 auto', minWidth: 0 }}>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.75, mb: 0.5 }}>
            <GoalIcon goal={goal} FallbackIcon={LocalShippingIcon} color={accent} size={18} />
            <Typography
              sx={{
                fontSize: 14,
                fontWeight: 700,
                color: text,
                overflow: 'hidden',
                textOverflow: 'ellipsis',
                whiteSpace: 'nowrap',
              }}
            >
              {goal.goal_name}
            </Typography>
          </Box>
          {showDescription && (
            <Typography sx={{ fontSize: 12, color: muted, mb: 1 }}>
              {sprintfWithGoal(goal, currency)}
            </Typography>
          )}
          {bool(settings, 'showAmounts', true) && (
            <Box sx={{ '& > * + *': { mt: 0.375 } }}>
              <Box sx={{ display: 'flex', justifyContent: 'space-between', fontSize: 12 }}>
                <Typography sx={{ fontSize: 12, color: muted }}>{__('Paid', 'faracart')}</Typography>
                <Typography sx={{ fontSize: 12, fontWeight: 700, color: text }}>
                  {formatGoalAmount(goal, goal.current, currency)}
                </Typography>
              </Box>
              <Box sx={{ display: 'flex', justifyContent: 'space-between', fontSize: 12 }}>
                <Typography sx={{ fontSize: 12, color: muted }}>{__('Remaining', 'faracart')}</Typography>
                <Typography sx={{ fontSize: 12, fontWeight: 700, color: accent }}>
                  {formatGoalAmount(goal, goal.remaining, currency)}
                </Typography>
              </Box>
            </Box>
          )}
        </Box>
      </Box>

      {bool(settings, 'showCta', true) && (
        <Button
          component="a"
          href={(goal.suggestions ?? [])[0]?.permalink}
          target="_blank"
          rel="noreferrer"
          fullWidth
          disableElevation
          sx={{
            mt: 2,
            py: 1,
            fontSize: 14,
            fontWeight: 700,
            textTransform: 'none',
            borderRadius: 8,
            color: str(settings, 'buttonTextColor', '#6366f1'),
            background: 'transparent',
            border: `1px solid ${str(settings, 'buttonColor', '#6366f1')}`,
            '&:hover': { background: 'transparent', opacity: 0.85 },
          }}
        >
          {__('View products', 'faracart')}
        </Button>
      )}
    </Box>
  );
}

/** A goal description derived from the target, e.g. "With a purchase of 2,000,000". */
function sprintfWithGoal(goal: GoalTemplateProps['goal'], currency: string): string {
  return `${__('With a purchase of', 'faracart')} ${formatGoalAmount(goal, goal.target, currency)}`;
}
