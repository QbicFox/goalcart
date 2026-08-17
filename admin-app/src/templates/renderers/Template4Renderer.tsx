import LightbulbIcon from '@mui/icons-material/Lightbulb';
import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';

import type { MissionTemplateProps } from '../registry';
import { bool, num, str } from '../utils';
import { MissionBar, RecommendedProductItem, missionPercent, remainingLabel } from './missionShared';

/**
 * Template 4 — Product Recommendation + Mission (Concept 07).
 *
 * A gradient progress header (mission title + remaining chip + bar) followed
 * by the mission's own recommended products with add-to-cart buttons. The
 * products come from the existing FaraCart / WooCommerce recommendation
 * data (mission.suggestions) — nothing is hard-coded and no second
 * recommendation engine is introduced.
 */
export default function Template4Renderer({ mission, currency, settings, animation }: MissionTemplateProps) {
  const percent = missionPercent(mission);
  const headerBg = str(settings, 'headerBg', '#2563eb');
  const accent = str(settings, 'accent', '#2563eb');
  const text = str(settings, 'text', '#1f2937');
  const muted = str(settings, 'secondaryText', '#6b7280');
  const products = mission.suggestions ?? [];

  return (
    <Box sx={{ overflow: 'hidden', borderRadius: 2 }}>
      {/* Progress header (gradient) */}
      <Box
        sx={{
          px: 2,
          py: 1.5,
          background: `linear-gradient(135deg, ${headerBg}, ${headerBg}cc)`,
          color: '#ffffff',
        }}
      >
        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 1, mb: 1 }}>
          <Typography
            sx={{
              fontSize: 12,
              fontWeight: 700,
              opacity: 0.9,
              overflow: 'hidden',
              textOverflow: 'ellipsis',
              whiteSpace: 'nowrap',
            }}
          >
            {mission.mission_name}
          </Typography>
          {bool(settings, 'showRemaining', true) && !mission.completed && (
            <Box
              component="span"
              sx={{
                flexShrink: 0,
                fontSize: 12,
                fontWeight: 700,
                background: 'rgba(255,255,255,0.2)',
                px: 1,
                py: 0.25,
                borderRadius: 999,
                whiteSpace: 'nowrap',
              }}
            >
              {remainingLabel(mission, currency)}
            </Box>
          )}
          {mission.completed && (
            <Box
              component="span"
              sx={{
                flexShrink: 0,
                fontSize: 12,
                fontWeight: 700,
                background: 'rgba(255,255,255,0.2)',
                px: 1,
                py: 0.25,
                borderRadius: 999,
                whiteSpace: 'nowrap',
              }}
            >
              {__('Completed', 'faracart')} ✓
            </Box>
          )}
        </Box>
        <MissionBar
          percent={mission.completed ? 100 : percent}
          completed={mission.completed}
          animation={animation}
          track="rgba(255,255,255,0.25)"
          height={num(settings, 'barHeight', 8)}
          color="#ffffff"
        />
      </Box>

      {/* Recommended products */}
      <Box sx={{ p: 1.75 }}>
        {bool(settings, 'showHeading', true) && (
          <Typography sx={{ fontSize: 12, fontWeight: 700, color: text, mb: 1.25 }}>
            <LightbulbIcon
              sx={{ fontSize: 14, color: '#eab308', verticalAlign: 'middle', ml: 0.25 }}
            />
            {__('Add these products to reach your mission faster:', 'faracart')}
          </Typography>
        )}

        {products.length === 0 ? (
          <Typography sx={{ fontSize: 12, color: muted }}>
            {__('No recommendations available right now.', 'faracart')}
          </Typography>
        ) : (
          <Box>
            {products.map((item) => (
              <RecommendedProductItem
                key={item.id}
                item={item}
                settings={settings}
                currency={currency}
                accent={accent}
              />
            ))}
          </Box>
        )}
      </Box>
    </Box>
  );
}
