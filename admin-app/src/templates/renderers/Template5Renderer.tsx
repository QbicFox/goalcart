import LocalShippingIcon from '@mui/icons-material/LocalShipping';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';

import type { MissionTemplateProps } from '../registry';
import { bool, num, str } from '../utils';
import { MissionBar, missionPercent, remainingLabel } from './missionShared';

/**
 * Template 5 — Compact Floating / Sticky Mission (Concept 08).
 *
 * A compact dark sticky-style bar: icon, slim progress, remaining amount
 * and a small CTA. Deliberately compact — it must not behave like a
 * normal large card. The dark surface and green accent follow the
 * reference design.
 */
export default function Template5Renderer({ mission, currency, settings, animation }: MissionTemplateProps) {
  const percent = missionPercent(mission);
  const accent = str(settings, 'accent', '#4ade80');
  const bg = str(settings, 'bg', '#1e293b');
  const border = str(settings, 'border', '#334155');
  const text = str(settings, 'text', '#f1f5f9');
  const muted = str(settings, 'secondaryText', '#cbd5e1');
  const shadow = num(settings, 'shadow', 16);

  return (
    <Box
      sx={{
        display: 'flex',
        alignItems: 'center',
        gap: 1.25,
        px: 1.5,
        py: 1.25,
        background: `linear-gradient(135deg, ${bg}, ${bg}cc)`,
        border: `1px solid ${border}`,
        borderRadius: num(settings, 'radius', 2),
        boxShadow: shadow > 0 ? `0 ${Math.max(2, shadow)}px ${shadow * 2}px rgba(0,0,0,0.35)` : 'none',
      }}
    >
      {bool(settings, 'showIcon', true) && (
        <Box
          sx={{
            width: 28,
            height: 28,
            borderRadius: '50%',
            background: `${accent}33`,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            flexShrink: 0,
          }}
        >
          {String(mission.icon || '').trim() ? (
            <Box component="span" sx={{ fontSize: 13, lineHeight: 1 }} aria-hidden>
              {mission.icon}
            </Box>
          ) : (
            <LocalShippingIcon sx={{ fontSize: 13, color: accent }} />
          )}
        </Box>
      )}

      <Box sx={{ flex: '1 1 auto', minWidth: 0 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 1, mb: 0.375 }}>
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
          {bool(settings, 'showRemaining', true) && !mission.completed && (
            <Typography sx={{ fontSize: 12, color: muted, whiteSpace: 'nowrap' }}>
              {remainingLabel(mission, currency)}
            </Typography>
          )}
          {mission.completed && (
            <Typography sx={{ fontSize: 12, fontWeight: 700, color: accent, whiteSpace: 'nowrap' }}>
              {__('Completed', 'faracart')} ✓
            </Typography>
          )}
        </Box>
        <MissionBar
          percent={percent}
          completed={mission.completed}
          animation={animation}
          track={str(settings, 'trackColor', '#475569')}
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
            px: 1.5,
            py: 0.625,
            minWidth: 0,
            fontSize: 11,
            fontWeight: 800,
            textTransform: 'none',
            borderRadius: num(settings, 'buttonRadius', 8),
            color: str(settings, 'buttonTextColor', '#0f172a'),
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
