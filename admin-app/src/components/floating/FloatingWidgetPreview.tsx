import Box from '@mui/material/Box';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import Typography from '@mui/material/Typography';
import { __ } from '@wordpress/i18n';
import { useState } from 'react';
import type { CSSProperties } from 'react';

import { DEFAULT_PREVIEW_TOKENS } from '../preview/types';
import type { PreviewTokens } from '../preview/types';
import {
  FLOATING_DEFAULT_ICON,
  resolveDrawerDirection,
  resolveFloatingDisplay,
  resolveFloatingPosition,
} from './floating';
import type { FloatingDevice, FloatingDraft, FloatingPosition } from './floating';
import type { FloatingHorizontal, FloatingVertical } from '../../types';

/**
 * Live preview of the floating goals/campaigns button.
 *
 * Renders a miniature storefront viewport (desktop or mobile) and places
 * the floating button exactly the way the storefront JS does — physical
 * left/right/top/bottom anchors + pixel offsets, center handled with the
 * same translateY(-50% + fy) trick, and the drawer opening in the
 * configured (or auto-resolved, screen-safe) direction. Every value comes
 * from the current form draft, so the preview updates immediately as the
 * admin edits the settings — no save required.
 *
 * The axes stay physical (never logical start/end), mirroring the
 * storefront, so the preview's visual result is identical in RTL.
 */

interface FloatingWidgetPreviewProps {
  /** The live form values (watched) — partial so the preview never crashes. */
  draft: FloatingDraft;
  /** Resolved storefront appearance tokens (colors/radius/bar height). */
  tokens?: PreviewTokens;
}

/** The preview viewport frame sizes (px) per device. */
const FRAME: Record<FloatingDevice, { width: number; height: number }> = {
  desktop: { width: 620, height: 360 },
  mobile: { width: 300, height: 500 },
};

/** A sample in-progress goal for the drawer card (preview only). */
const SAMPLE_GOAL = {
  name: __('Free shipping', 'faracart'),
  message: __('Only %s left to reach your goal', 'faracart').replace('%s', '350,000'),
  percent: 62,
};

export default function FloatingWidgetPreview({ draft, tokens }: FloatingWidgetPreviewProps) {
  const [device, setDevice] = useState<FloatingDevice>('desktop');
  const [open, setOpen] = useState(false);

  const display = resolveFloatingDisplay(draft);
  const { desktop, mobile } = resolveFloatingPosition(draft);
  const position = device === 'mobile' ? mobile : desktop;
  const frame = FRAME[device];

  // The button is only visible when the widget is enabled AND the current
  // device's visibility flag is on (the storefront applies the same gate).
  const visibleOnDevice = device === 'mobile' ? display.showMobile : display.showDesktop;
  const showButton = display.enabled && visibleOnDevice;

  // The button's physical rect within the frame — the same math the
  // storefront's fixed positioning produces, so the auto drawer direction
  // resolves identically (never pointing off-screen).
  const size = display.buttonSize;
  const rect = buttonRect(position, size, frame);
  const drawerWidth = Math.min(240, frame.width - 32);
  const drawerHeight = Math.min(210, frame.height - 24);

  const direction = showButton
    ? resolveDrawerDirection(rect, display.drawerDirection, frame, {
        minWidth: drawerWidth + 12,
        minHeight: drawerHeight,
      })
    : 'left';

  const accent = tokens?.accent ?? DEFAULT_PREVIEW_TOKENS.accent;
  const bg = tokens?.bg ?? DEFAULT_PREVIEW_TOKENS.bg;
  const border = tokens?.border ?? DEFAULT_PREVIEW_TOKENS.border;
  const text = tokens?.text ?? DEFAULT_PREVIEW_TOKENS.text;
  const radius = tokens?.radius ?? DEFAULT_PREVIEW_TOKENS.radius;

  return (
    <Paper variant="outlined" sx={{ p: { xs: 2, md: 2.5 } }}>
      <Stack spacing={1.5}>
        <Box
          sx={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: 1,
            flexWrap: 'wrap',
          }}
        >
          <Typography variant="overline" color="text.secondary" sx={{ m: 0 }}>
            {__('Live preview', 'faracart')}
          </Typography>
          <ToggleButtonGroup
            size="small"
            exclusive
            value={device}
            onChange={(_event, next: FloatingDevice | null) => {
              if (next) {
                setDevice(next);
                setOpen(false);
              }
            }}
            aria-label={__('Preview device', 'faracart')}
          >
            <ToggleButton value="desktop">{__('Desktop', 'faracart')}</ToggleButton>
            <ToggleButton value="mobile">{__('Mobile', 'faracart')}</ToggleButton>
          </ToggleButtonGroup>
        </Box>

        <Box
          sx={{
            position: 'relative',
            width: '100%',
            maxWidth: frame.width,
            height: frame.height,
            mx: 'auto',
            overflow: 'hidden',
            border: 1,
            borderColor: 'divider',
            borderRadius: 1.5,
            bgcolor: '#eef0f1',
          }}
        >
          {/* Fake storefront page behind the button. */}
          <FakePage mobile={device === 'mobile'} />

          {showButton && (
            <Box
              sx={anchorStyles(position, size, display.animation, frame)}
              onClick={() => setOpen((value) => !value)}
              aria-label={display.label || __('View your cart goals', 'faracart')}
              role="button"
            >
              <Box
                sx={{
                  width: size,
                  height: size,
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  borderRadius: '50%',
                  bgcolor: accent,
                  color: '#ffffff',
                  fontSize: Math.round(size * 0.44),
                  lineHeight: 1,
                  cursor: 'pointer',
                  boxShadow: '0 4px 14px rgba(0, 0, 0, 0.18)',
                  transition: display.animation
                    ? 'transform 0.2s ease, box-shadow 0.2s ease'
                    : 'none',
                  '&:hover': {
                    transform: display.animation ? 'translateY(-1px)' : 'none',
                    boxShadow: '0 10px 28px rgba(0, 0, 0, 0.22)',
                  },
                }}
                title={display.label || __('View your cart goals', 'faracart')}
              >
                {display.icon || FLOATING_DEFAULT_ICON}
              </Box>

              {open && (
                <Box
                  sx={{
                    position: 'absolute',
                    zIndex: 1,
                    minWidth: drawerWidth,
                    width: drawerWidth,
                    height: drawerHeight,
                    overflow: 'auto',
                    p: 1.5,
                    bgcolor: bg,
                    border: `1px solid ${border}`,
                    borderRadius: 2,
                    boxShadow: '0 8px 30px rgba(0, 0, 0, 0.18)',
                    color: text,
                    ...drawerAnchorStyles(direction, rect, size, frame, drawerWidth, drawerHeight),
                  }}
                  onClick={(event) => event.stopPropagation()}
                >
                  <DrawerCard
                    goal={SAMPLE_GOAL}
                    tokens={{ accent, bg, border, text, radius, barHeight: 8 }}
                  />
                </Box>
              )}
            </Box>
          )}

          {!display.enabled && (
            <Box
              sx={{
                position: 'absolute',
                inset: 0,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <Typography variant="body2" color="text.secondary">
                {__('Enable the floating widget to preview it.', 'faracart')}
              </Typography>
            </Box>
          )}
        </Box>

        <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
          {showButton
            ? __(
                'Left/right and top/bottom are physical sides — the button stays exactly here in RTL stores.',
                'faracart'
              )
            : __(
                'The button is hidden on this device (visibility disabled or the widget is off).',
                'faracart'
              )}
        </Typography>
      </Stack>
    </Paper>
  );
}

/** The button's physical rect within the preview frame. */
function buttonRect(
  position: FloatingPosition,
  size: number,
  frame: { width: number; height: number }
): { left: number; top: number; right: number; bottom: number } {
  const horizontal = position.horizontal as FloatingHorizontal;
  const vertical = position.vertical as FloatingVertical;
  const offsetX = Math.min(position.offset_x, Math.max(0, frame.width - size - 8));
  const offsetY = Math.min(position.offset_y, Math.max(0, frame.height - size - 8));

  const left = horizontal === 'left' ? offsetX : frame.width - offsetX - size;
  let top: number;

  if (vertical === 'top') {
    top = offsetY;
  } else if (vertical === 'bottom') {
    top = frame.height - offsetY - size;
  } else {
    top = Math.round(frame.height / 2 - size / 2 + offsetY);
  }

  return { left, top, right: left + size, bottom: top + size };
}

/** Physical anchor styles for the button wrapper (mirrors the storefront). */
function anchorStyles(
  position: FloatingPosition,
  size: number,
  animation: boolean,
  frame: { width: number; height: number }
): CSSProperties {
  const style: CSSProperties = {
    position: 'absolute',
    width: size,
    height: size,
    cursor: 'pointer',
    transition: animation ? 'transform 0.2s ease' : 'none',
  };

  // Clamp the offsets so the button always stays fully inside the frame
  // (the storefront applies the same safe-positioning guard).
  const maxX = Math.max(0, frame.width - size - 8);
  const maxY = Math.max(0, frame.height - size - 8);
  const offsetX = Math.min(position.offset_x, maxX);
  const offsetY = Math.min(position.offset_y, maxY);

  if (position.horizontal === 'left') {
    style.left = offsetX;
  } else {
    style.right = offsetX;
  }

  if (position.vertical === 'top') {
    style.top = offsetY;
  } else if (position.vertical === 'bottom') {
    style.bottom = offsetY;
  } else {
    // Center: the same translateY(-50% + fy) composition the storefront
    // CSS uses, so the configured offset shifts the whole block off-center.
    style.top = '50%';
    style.transform = `translateY(calc(-50% + ${offsetY}px))`;
  }

  return style;
}

/**
 * Physical drawer anchoring — mirrors the storefront: the panel opens
 * from the button in the configured direction, then gets clamped so it
 * stays fully inside the viewport frame (the same safe-positioning guard
 * the storefront JS applies), keeping its internal scroll area reachable.
 */
function drawerAnchorStyles(
  direction: 'left' | 'right' | 'up' | 'down',
  rect: { left: number; top: number },
  size: number,
  frame: { width: number; height: number },
  width: number,
  height: number
): CSSProperties {
  const gap = 12;
  const margin = 8;
  let top = rect.top;
  let left = rect.left;

  if (direction === 'left') {
    left = rect.left - gap - width;
    top = rect.top + size / 2 - height / 2;
  } else if (direction === 'right') {
    left = rect.left + size + gap;
    top = rect.top + size / 2 - height / 2;
  } else if (direction === 'up') {
    left = rect.left + size / 2 - width / 2;
    top = rect.top - gap - height;
  } else {
    left = rect.left + size / 2 - width / 2;
    top = rect.top + size + gap;
  }

  return {
    top: Math.min(Math.max(margin, top), frame.height - margin - height),
    left: Math.min(Math.max(margin, left), frame.width - margin - width),
  };
}

/** A compact goal card, styled like the storefront drawer content. */
function DrawerCard({ goal, tokens }: { goal: typeof SAMPLE_GOAL; tokens: PreviewTokens }) {
  return (
    <Box>
      <Typography variant="body2" sx={{ fontWeight: 600, mb: 0.5 }}>
        {goal.name}
      </Typography>
      <Box
        sx={{
          height: tokens.barHeight,
          borderRadius: tokens.radius,
          bgcolor: tokens.border,
          overflow: 'hidden',
          mb: 1,
        }}
      >
        <Box
          sx={{
            width: `${goal.percent}%`,
            height: '100%',
            bgcolor: tokens.accent,
            borderRadius: tokens.radius,
            transition: 'width 0.3s ease',
          }}
        />
      </Box>
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
        {goal.message}
      </Typography>
      <Box
        sx={{
          display: 'inline-flex',
          alignItems: 'center',
          gap: 0.5,
          px: 1,
          py: 0.25,
          borderRadius: 999,
          bgcolor: 'rgba(0, 0, 0, 0.06)',
          color: 'text.secondary',
        }}
      >
        <span aria-hidden>🔒</span>
        <Typography variant="caption" sx={{ fontWeight: 600 }}>
          {__('Free shipping', 'faracart')}
        </Typography>
      </Box>
    </Box>
  );
}

/** A simplified storefront page behind the button. */
function FakePage({ mobile }: { mobile: boolean }) {
  return (
    <Box sx={{ height: '100%', display: 'flex', flexDirection: 'column' }}>
      <Box
        sx={{
          height: 40,
          bgcolor: mobile ? '#cfd4d8' : '#c4cad0',
          display: 'flex',
          alignItems: 'center',
          px: 2,
          gap: 1,
        }}
      >
        <Box sx={{ width: 70, height: 8, borderRadius: 4, bgcolor: '#9aa3ab' }} />
        <Box sx={{ width: 44, height: 8, borderRadius: 4, bgcolor: '#9aa3ab', opacity: 0.7 }} />
        <Box sx={{ width: 44, height: 8, borderRadius: 4, bgcolor: '#9aa3ab', opacity: 0.7 }} />
      </Box>
      <Box
        sx={{
          flex: 1,
          p: 2,
          display: 'flex',
          flexWrap: 'wrap',
          gap: 1.5,
          alignContent: 'flex-start',
        }}
      >
        {[0, 1, 2].map((index) => (
          <Box
            key={index}
            sx={{
              width: mobile ? '100%' : 96,
              height: mobile ? 46 : 78,
              borderRadius: 1.5,
              bgcolor: '#dde1e4',
              border: '1px solid #d3d8dc',
            }}
          />
        ))}
      </Box>
      <Box sx={{ height: 34, bgcolor: '#c4cad0' }} />
    </Box>
  );
}
