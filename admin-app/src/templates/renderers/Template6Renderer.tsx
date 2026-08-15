import LocalFireDepartmentIcon from '@mui/icons-material/LocalFireDepartment';
import LocalShippingIcon from '@mui/icons-material/LocalShipping';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';

import type { GoalTemplateProps } from '../registry';
import { bool, num, str } from '../utils';
import { GoalBar, formatGoalAmount, goalPercent } from './goalShared';

/**
 * Template 6 — Premium / Elegant E-commerce Style (Concept 09).
 *
 * Gold-accented elegant card: a slim header with a gold rail, a large
 * goal title + description, a thin gold progress bar with a marker dot,
 * the current/remaining amounts and a refined outline CTA, plus a
 * highlighted "almost completed" callout. Visually distinct — never a
 * generic MUI Card.
 */
export default function Template6Renderer({ goal, currency, settings, animation }: GoalTemplateProps) {
  const percent = goalPercent(goal);
  const gold = str(settings, 'accent', '#d4af37');
  const progressColor = str(settings, 'progressColor', gold);
  const bg = str(settings, 'bg', '#fafafa');
  const border = str(settings, 'border', '#ece5d0');
  const text = str(settings, 'text', '#111827');
  const muted = str(settings, 'secondaryText', '#9ca3af');
  const outlineColor = str(settings, 'buttonTextColor', '#b8922a');
  const buttonColor = str(settings, 'buttonColor', '#b8922a');
  const barHeight = num(settings, 'barHeight', 6);

  return (
    <Box>
      {/* Header */}
      <Box
        sx={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: 1,
          pb: 1.5,
          mb: 1.5,
          borderBottom: `1px solid ${border}`,
        }}
      >
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.25 }}>
          <Box
            sx={{
              width: 4,
              height: 24,
              borderRadius: 999,
              background: `linear-gradient(to bottom, ${gold}, ${outlineColor})`,
            }}
          />
          <Typography
            sx={{
              fontSize: 11,
              fontWeight: 700,
              color: '#9ca3af',
              letterSpacing: '0.2em',
              textTransform: 'uppercase',
            }}
          >
            {__('Shopping goal', 'goalcart')}
          </Typography>
        </Box>
        <LocalShippingIcon sx={{ fontSize: 16, color: border }} aria-hidden />
      </Box>

      {/* Title + description */}
      <Typography sx={{ fontSize: 20, fontWeight: 900, color: text, mb: 0.25 }}>
        {goal.goal_name}
      </Typography>
      <Typography sx={{ fontSize: 12, color: muted, mb: 1.5 }}>
        {sprintf(
          /* translators: %s: formatted target amount. */
          __('With a purchase of %s', 'goalcart'),
          formatGoalAmount(goal, goal.target, currency)
        )}
      </Typography>

      {/* Elegant progress with a marker dot at the end */}
      <Box sx={{ position: 'relative', mb: 1.5 }}>
        <GoalBar
          percent={goal.completed ? 100 : percent}
          completed={goal.completed}
          animation={animation}
          track="#f3f4f6"
          height={barHeight}
          color={goal.completed ? '#00a32a' : progressColor}
        />
        {!goal.completed && percent > 0 && percent < 100 && (
          <Box
            sx={{
              position: 'absolute',
              insetBlockStart: '50%',
              insetInlineStart: `${percent}%`,
              transform: 'translate(-50%, -50%)',
              width: 12,
              height: 12,
              borderRadius: '50%',
              background: bg,
              border: `2px solid ${progressColor}`,
              boxShadow: '0 1px 3px rgba(0,0,0,0.12)',
            }}
          />
        )}
      </Box>

      {/* Amounts */}
      {bool(settings, 'showAmounts', true) && (
        <Box sx={{ display: 'flex', justifyContent: 'space-between', gap: 1, mb: 1.5 }}>
          <Box>
            <Typography sx={{ fontSize: 11, color: muted, mb: 0.25 }}>
              {__('Paid', 'goalcart')}
            </Typography>
            <Typography sx={{ fontSize: 13, fontWeight: 700, color: text }}>
              {formatGoalAmount(goal, goal.current, currency)}
            </Typography>
          </Box>
          <Box sx={{ textAlign: 'end' }}>
            <Typography sx={{ fontSize: 11, color: muted, mb: 0.25 }}>
              {__('Remaining', 'goalcart')}
            </Typography>
            <Typography sx={{ fontSize: 13, fontWeight: 700, color: outlineColor }}>
              {formatGoalAmount(goal, goal.remaining, currency)}
            </Typography>
          </Box>
        </Box>
      )}

      {/* CTA */}
      {bool(settings, 'showCta', true) && (
        <Button
          component="a"
          href={(goal.suggestions ?? [])[0]?.permalink}
          target="_blank"
          rel="noreferrer"
          fullWidth
          disableElevation
          sx={{
            py: 1.25,
            fontSize: 12,
            fontWeight: 700,
            letterSpacing: '0.06em',
            textTransform: 'none',
            borderRadius: 8,
            color: outlineColor,
            background: 'transparent',
            border: `1px solid ${buttonColor}`,
            '&:hover': { background: 'transparent', opacity: 0.85 },
          }}
        >
          {__('View products', 'goalcart')}
        </Button>
      )}

      {/* Almost-completed callout */}
      {goal.state === 'nearly_complete' && !goal.completed && (
        <Box
          sx={{
            mt: 1.5,
            display: 'flex',
            alignItems: 'center',
            gap: 1.25,
            px: 1.5,
            py: 1,
            borderRadius: 2,
            background: '#fffbeb',
            border: '1px solid #fde68a',
          }}
        >
          <LocalFireDepartmentIcon sx={{ fontSize: 18, color: '#f59e0b', flexShrink: 0 }} aria-hidden />
          <Box>
            <Typography sx={{ fontSize: 12, fontWeight: 800, color: '#78350f' }}>
              {sprintf(
                /* translators: %s: formatted remaining amount. */
                __('Almost there! Only %s left', 'goalcart'),
                formatGoalAmount(goal, goal.remaining, currency)
              )}
            </Typography>
            <Typography sx={{ fontSize: 10, color: '#92400e' }}>
              {__('Finish today — your reward is waiting', 'goalcart')}
            </Typography>
          </Box>
        </Box>
      )}
    </Box>
  );
}
