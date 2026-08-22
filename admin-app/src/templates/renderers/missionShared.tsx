import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';
import type { ComponentType, ReactNode } from 'react';

import { decodeHtmlEntities, formatCurrency, formatNumber } from '../../lib/format';
import type { ProgressMission, SuggestionProduct, TemplateSettingsValue } from '../../types';
import { num, str } from '../utils';

/** Format a mission value: currency for money missions, plain number otherwise. */
export function formatMissionAmount(mission: ProgressMission, value: number, _currency: string): string {
  return mission.is_money ? formatCurrency(value) : formatNumber(value);
}

/** The clamped 0–100 progress percentage. */
export function missionPercent(mission: ProgressMission): number {
  return Math.max(0, Math.min(100, Number(mission.percentage) || 0));
}

/** Whether a mission is in an expired/ended presentation state. */
export function isExpiredMission(mission: ProgressMission): boolean {
  return mission.eligible === false || mission.state === 'inactive' || mission.state === 'unavailable';
}

/** The remaining-amount label ("%s left"), localized. */
export function remainingLabel(mission: ProgressMission, currency: string): string {
  return sprintf(
    /* translators: %s: formatted remaining amount. */
    __('%s left', 'faracart'),
    formatMissionAmount(mission, mission.remaining, currency)
  );
}

/** The CTA label ("Add %s more"), localized. */
export function addMoreLabel(mission: ProgressMission, currency: string): string {
  return sprintf(
    /* translators: %s: formatted remaining amount. */
    __('Add %s more', 'faracart'),
    formatMissionAmount(mission, mission.remaining, currency)
  );
}

interface MissionIconProps {
  mission: ProgressMission;
  /** Fallback MUI icon rendered when the mission carries no icon string. */
  FallbackIcon: ComponentType<{ sx?: object }>;
  /** Icon badge background ('' = none). */
  bg?: string;
  color: string;
  size: number;
  radius?: number | string;
}

/**
 * The mission's icon badge: the configured mission icon (emoji / dashicon name)
 * rendered as text, or the template's fallback MUI icon when none is set.
 */
export function MissionIcon({ mission, FallbackIcon, bg, color, size, radius = '50%' }: MissionIconProps) {
  const icon = String(mission.icon || '').trim();

  return (
    <Box
      sx={{
        width: size,
        height: size,
        borderRadius: radius,
        background: bg || 'transparent',
        color,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        flexShrink: 0,
      }}
      aria-hidden
    >
      {icon ? (
        <Box component="span" sx={{ fontSize: size * 0.5, lineHeight: 1 }}>
          {icon}
        </Box>
      ) : (
        <FallbackIcon sx={{ fontSize: size * 0.5 }} />
      )}
    </Box>
  );
}

/** The percentage chip (e.g. "82%"). */
export function PercentChip({ percent, color, bg, border }: { percent: number; color: string; bg: string; border: string }) {
  return (
    <Box
      component="span"
      sx={{
        display: 'inline-flex',
        alignItems: 'center',
        padding: '0.125rem 0.5rem',
        borderRadius: 999,
        fontSize: 12,
        fontWeight: 700,
        color,
        background: bg,
        border: `1px solid ${border}`,
        whiteSpace: 'nowrap',
        fontVariantNumeric: 'tabular-nums',
      }}
    >
      {Math.round(percent)}%
    </Box>
  );
}

/**
 * A styled progress bar reading the template's settings (height, accent,
 * track, completed color).
 */
export function MissionBar({
  percent,
  completed,
  animation,
  track,
  height,
  color,
  radius = 999,
}: {
  percent: number;
  completed: boolean;
  animation: boolean;
  track: string;
  height: number;
  color: string;
  radius?: number | string;
}) {
  const clamped = Math.max(0, Math.min(100, percent));

  return (
    <Box
      sx={{
        position: 'relative',
        height,
        background: track,
        borderRadius: radius,
        overflow: 'hidden',
        width: '100%',
        flex: '1 1 auto',
        minWidth: 0,
      }}
    >
      <Box
        sx={{
          position: 'absolute',
          insetInlineStart: 0,
          insetBlockStart: 0,
          height: '100%',
          width: `${clamped}%`,
          background: completed ? '#00a32a' : color,
          borderRadius: 'inherit',
          transition: animation ? 'width 0.45s ease' : 'none',
        }}
      />
    </Box>
  );
}

/** The current / remaining amount summary row. */
export function AmountSummary({
  mission,
  currency,
  settings,
  highlightColor,
}: {
  mission: ProgressMission;
  currency: string;
  settings: TemplateSettingsValue;
  highlightColor: string;
}) {
  return (
    <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 1 }}>
      <Typography sx={{ fontSize: 12, color: str(settings, 'secondaryText', '#6b7280') }}>
        {formatMissionAmount(mission, mission.current, currency)}
      </Typography>
      <Typography sx={{ fontSize: 12, fontWeight: 600, color: highlightColor, whiteSpace: 'nowrap' }}>
        {remainingLabel(mission, currency)}
      </Typography>
    </Box>
  );
}

/**
 * The template CTA: links to the top recommended product when one exists
 * (the gap-closing product), rendered as a full-width button. Hidden when
 * there is no recommendation to send the shopper to.
 */
export function MissionCta({
  mission,
  currency,
  settings,
  children,
  variant = 'solid',
}: {
  mission: ProgressMission;
  currency: string;
  settings: TemplateSettingsValue;
  children?: ReactNode;
  variant?: 'solid' | 'outline';
}) {
  const suggestion = (mission.suggestions ?? [])[0];

  if (!suggestion) {
    return null;
  }

  const solid = variant !== 'outline';

  return (
    <Button
      component="a"
      href={suggestion.permalink}
      target="_blank"
      rel="noreferrer"
      fullWidth
      disableElevation
      sx={{
        mt: 1,
        py: 0.875,
        fontSize: 14,
        fontWeight: 700,
        textTransform: 'none',
        borderRadius: num(settings, 'buttonRadius', 8),
        color: solid ? str(settings, 'buttonTextColor', '#ffffff') : str(settings, 'buttonColor', '#2271b1'),
        background: solid ? str(settings, 'buttonColor', '#2271b1') : 'transparent',
        border: solid ? 'none' : `1px solid ${str(settings, 'buttonColor', '#2271b1')}`,
        '&:hover': {
          background: solid
            ? str(settings, 'buttonColor', '#2271b1')
            : 'transparent',
          filter: solid ? 'brightness(0.95)' : undefined,
          opacity: solid ? undefined : 0.85,
        },
      }}
    >
      {children ?? addMoreLabel(mission, currency)}
    </Button>
  );
}

/** One recommended product row (image, name, price, add button). */
export function RecommendedProductItem({
  item,
  settings,
  currency: _currency,
  accent,
}: {
  item: SuggestionProduct;
  settings: TemplateSettingsValue;
  currency: string;
  accent: string;
}) {
  const imageSize = num(settings, 'productImageSize', 40);

  return (
    <Box
      sx={{
        display: 'flex',
        alignItems: 'center',
        gap: 1.25,
        p: 1,
        borderRadius: 1.5,
        border: '1px solid',
        borderColor: 'divider',
        '& + &': { mt: 0.5 },
      }}
    >
      <Box
        component="img"
        src={item.image}
        alt={item.name}
        loading="lazy"
        sx={{
          width: imageSize,
          height: imageSize,
          borderRadius: 1,
          objectFit: 'cover',
          background: '#f3f4f6',
          flexShrink: 0,
        }}
      />
      <Box sx={{ flex: '1 1 auto', minWidth: 0 }}>
        <Typography sx={{ fontSize: 12, fontWeight: 700, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
          {item.name}
        </Typography>
        <Typography sx={{ fontSize: 11, color: 'text.secondary' }}>
          {sprintf(
            /* translators: %s: formatted price. */
            __('Only %s', 'faracart'),
            item.price !== null && item.price !== undefined
              ? formatCurrency(item.price)
              : decodeHtmlEntities(item.price_html)
          )}
        </Typography>
      </Box>
      <Button
        size="small"
        variant="contained"
        disableElevation
        sx={{
          flexShrink: 0,
          minWidth: 0,
          px: 1,
          fontSize: 11,
          fontWeight: 700,
          textTransform: 'none',
          borderRadius: num(settings, 'buttonRadius', 8),
          background: accent,
          '&:hover': { background: accent, filter: 'brightness(0.95)' },
        }}
      >
        {__('Add', 'faracart')}
      </Button>
    </Box>
  );
}
