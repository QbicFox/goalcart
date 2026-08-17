import LocalShippingIcon from '@mui/icons-material/LocalShipping';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';

import type { MissionTemplateProps } from '../registry';
import { bool, num, str } from '../utils';
import { MissionBar, MissionIcon, missionPercent, remainingLabel } from './missionShared';

/**
 * Template 2 — Minimal Inline Cart Mission (Concept 02).
 *
 * A very compact inline strip: small icon, mission title, remaining amount,
 * a slim progress bar and a compact CTA. Designed to sit between the cart
 * content and the totals, so its vertical height stays small — it must
 * never become a normal large card.
 */
export default function Template2Renderer({ mission, currency, settings, animation }: MissionTemplateProps) {
  const percent = missionPercent(mission);
  const accent = str(settings, 'accent', '#6366f1');
  const text = str(settings, 'text', '#312e81');
  const muted = str(settings, 'secondaryText', '#6366f1');

  return (
    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.25 }}>
      {bool(settings, 'showIcon', true) && (
        <MissionIcon
          mission={mission}
          FallbackIcon={LocalShippingIcon}
          color={accent}
          size={20}
        />
      )}

      <Box sx={{ flex: '1 1 auto', minWidth: 0 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 1, mb: 0.375 }}>
          {bool(settings, 'showTitle', true) && (
            <Typography
              sx={{
                fontSize: 12,
                fontWeight: 600,
                color: text,
                overflow: 'hidden',
                textOverflow: 'ellipsis',
                whiteSpace: 'nowrap',
              }}
            >
              {mission.mission_name}
            </Typography>
          )}
          {bool(settings, 'showRemaining', true) && !mission.completed && (
            <Typography sx={{ fontSize: 12, fontWeight: 700, color: muted, whiteSpace: 'nowrap' }}>
              {remainingLabel(mission, currency)}
            </Typography>
          )}
          {mission.completed && (
            <Typography sx={{ fontSize: 12, fontWeight: 700, color: '#16a34a', whiteSpace: 'nowrap' }}>
              {__('Completed', 'faracart')} ✓
            </Typography>
          )}
        </Box>
        <MissionBar
          percent={percent}
          completed={mission.completed}
          animation={animation}
          track={mission.completed ? '#bbf7d0' : '#c7d2fe'}
          height={num(settings, 'barHeight', 6)}
          color={mission.completed ? '#16a34a' : accent}
        />
      </Box>

      {bool(settings, 'showCta', true) && !mission.completed && (
        <Button
          size="small"
          variant="contained"
          disableElevation
          sx={{
            flexShrink: 0,
            px: 1.25,
            py: 0.5,
            minWidth: 0,
            fontSize: 12,
            fontWeight: 600,
            textTransform: 'none',
            borderRadius: num(settings, 'buttonRadius', 8),
            background: accent,
            '&:hover': { background: accent, filter: 'brightness(0.95)' },
          }}
        >
          {__('Add', 'faracart')}
        </Button>
      )}
    </Box>
  );
}
