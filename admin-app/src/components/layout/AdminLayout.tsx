import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import MenuIcon from '@mui/icons-material/Menu';
import StorefrontIcon from '@mui/icons-material/Storefront';
import UnfoldLessIcon from '@mui/icons-material/UnfoldLess';
import UnfoldMoreIcon from '@mui/icons-material/UnfoldMore';
import Accordion from '@mui/material/Accordion';
import AccordionDetails from '@mui/material/AccordionDetails';
import AccordionSummary from '@mui/material/AccordionSummary';
import AppBar from '@mui/material/AppBar';
import Avatar from '@mui/material/Avatar';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Divider from '@mui/material/Divider';
import Drawer from '@mui/material/Drawer';
import IconButton from '@mui/material/IconButton';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import ListItemIcon from '@mui/material/ListItemIcon';
import ListItemText from '@mui/material/ListItemText';
import Menu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
import Toolbar from '@mui/material/Toolbar';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import type { Theme } from '@mui/material/styles';
import { __ } from '@wordpress/i18n';
import { useState } from 'react';
import { Outlet, Link as RouterLink, useLocation } from 'react-router-dom';

import { getBootData } from '../../boot';
import { useFullscreen } from '../../providers/FullscreenProvider';
import ErrorBoundary from '../ErrorBoundary';
import { NAV_SECTIONS, type NavItem } from './navigation';

export const DRAWER_WIDTH = 260;

/** localStorage key holding which sidebar groups are expanded. */
const NAV_EXPANDED_KEY = 'goalcart:navExpanded';

/**
 * Thin, theme-aware scrollbar shared by the sidebar nav area and the
 * full-screen content area so both scroll regions look identical
 * (WP-admin palette: a muted divider-colored thumb on a transparent
 * track, darkening on hover).
 */
function thinScrollbar(theme: Theme) {
  return {
    scrollbarWidth: 'thin',
    scrollbarColor: `${theme.palette.divider} transparent`,
    '&::-webkit-scrollbar': { width: 6 },
    '&::-webkit-scrollbar-track': { bgcolor: 'transparent' },
    '&::-webkit-scrollbar-thumb': {
      bgcolor: theme.palette.divider,
      borderRadius: 3,
      '&:hover': { bgcolor: theme.palette.text.disabled },
    },
  };
}

/**
 * Admin app shell: top bar + responsive sidebar + routed content.
 *
 * The app runs inside WordPress's own admin chrome (fixed admin bar on
 * top, fixed admin sidebar on the side). To avoid overlapping them, the
 * app's own header and sidebar are kept in normal document flow (not
 * `position: fixed`): the header sits below the WP admin bar and the
 * sidebar flows beside the content, both scrolling with the page like a
 * regular WP admin screen. The sidebar collapses into a temporary drawer
 * (overlay) on small screens.
 *
 * Mirrors the reference plugin (WooInsights\AdminLayout).
 */
export default function AdminLayout() {
  const boot = getBootData();
  const location = useLocation();
  const { fullscreen } = useFullscreen();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [userMenuAnchor, setUserMenuAnchor] = useState<null | HTMLElement>(null);

  // Which groups are expanded (all by default, persisted across reloads).
  const [expanded, setExpanded] = useState<Record<string, boolean>>(() => {
    if (typeof window !== 'undefined') {
      try {
        const stored = window.localStorage.getItem(NAV_EXPANDED_KEY);

        if (stored) {
          const parsed = JSON.parse(stored) as unknown;

          if (parsed && typeof parsed === 'object') {
            return parsed as Record<string, boolean>;
          }
        }
      } catch {
        // Corrupt storage — fall back to everything expanded.
      }
    }

    return Object.fromEntries(NAV_SECTIONS.map((section) => [section.title, true]));
  });

  // Keep the group holding the current page open when navigating — the
  // user can still collapse it afterwards (their choice is persisted).
  // The state is adjusted during render (guarded by the previously seen
  // path) rather than in an effect, per react-hooks/set-state-in-effect.
  const [activePath, setActivePath] = useState(location.pathname);

  if (location.pathname !== activePath) {
    setActivePath(location.pathname);

    const activeSection = NAV_SECTIONS.find((section) =>
      section.items.some((item) => item.path === location.pathname)
    );

    if (activeSection) {
      setExpanded((prev) =>
        prev[activeSection.title] ? prev : { ...prev, [activeSection.title]: true }
      );
    }
  }

  const toggleSection = (title: string) => {
    setExpanded((prev) => {
      const next = { ...prev, [title]: !prev[title] };

      try {
        window.localStorage.setItem(NAV_EXPANDED_KEY, JSON.stringify(next));
      } catch {
        // Private mode / quota — persistence is best-effort.
      }

      return next;
    });
  };

  // At least one group is open — the footer control offers to collapse
  // everything; when none are open it offers to expand everything.
  const anyExpanded = NAV_SECTIONS.some((section) => expanded[section.title] !== false);

  const setAllSections = (value: boolean) => {
    setExpanded(() => {
      const next = Object.fromEntries(NAV_SECTIONS.map((section) => [section.title, value]));

      try {
        window.localStorage.setItem(NAV_EXPANDED_KEY, JSON.stringify(next));
      } catch {
        // Private mode / quota — persistence is best-effort.
      }

      return next;
    });
  };

  const sidebar = (
    <>
      {/* Scrollable nav area — grows/shrinks to fill, but the footer
          (collapse-all + version) below stays pinned while a long group
          list scrolls. minHeight: 0 lets the flex child shrink. */}
      <Box
        sx={(theme) => ({
          flex: '1 1 auto',
          minHeight: 0,
          overflowY: 'auto',
          px: 1,
          py: 1,
          ...thinScrollbar(theme),
        })}
      >
        <List component="nav" sx={{ p: 0 }} aria-label={__('Main navigation', 'goalcart')}>
          {NAV_SECTIONS.map((section) => (
            <Accordion
              key={section.title}
              // A group missing from the persisted record (e.g. added by a
              // future version) defaults to expanded — only an explicit
              // `false` collapses it, matching the all-expanded first run.
              expanded={expanded[section.title] !== false}
              onChange={() => toggleSection(section.title)}
              disableGutters
              square
              elevation={0}
              sx={{
                bgcolor: 'transparent',
                // Remove the MUI divider line that normally caps each group.
                '&::before': { display: 'none' },
              }}
            >
              {/* Collapsible group header — WP-admin style caption + chevron. */}
              <AccordionSummary
                expandIcon={<ExpandMoreIcon fontSize="small" />}
                sx={{
                  minHeight: 0,
                  px: 1,
                  borderRadius: 1,
                  transition: 'background-color 120ms ease',
                  '&:hover': { bgcolor: 'action.hover' },
                  '& .MuiAccordionSummary-content': { m: 0, py: 0.75 },
                }}
              >
                <Typography
                  variant="overline"
                  sx={{
                    display: 'block',
                    fontSize: '0.68rem',
                    lineHeight: 1.4,
                    fontWeight: 700,
                    textTransform: 'none',
                    letterSpacing: 0.4,
                    color: 'text.secondary',
                  }}
                >
                  {section.title}
                </Typography>
              </AccordionSummary>
              <AccordionDetails sx={{ p: 0, pb: 0.5 }}>
                {section.items.map((item: NavItem) => {
                  const Icon = item.icon;
                  const active = location.pathname === item.path;

                  return (
                    <ListItemButton
                      key={item.path}
                      component={RouterLink}
                      to={item.path}
                      selected={active}
                      onClick={() => setMobileOpen(false)}
                      sx={{ borderRadius: 1, mb: 0.5 }}
                    >
                      <ListItemIcon sx={{ minWidth: 40 }}>
                        <Icon fontSize="small" />
                      </ListItemIcon>
                      <ListItemText
                        primary={item.label}
                        slotProps={{ primary: { variant: 'body2' } }}
                      />
                    </ListItemButton>
                  );
                })}
              </AccordionDetails>
            </Accordion>
          ))}
        </List>
      </Box>
      <Divider />
      {/* Pinned footer — never scrolls with the nav above. */}
      <Box
        sx={{
          flexShrink: 0,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: 1,
          px: 1.5,
          py: 1,
        }}
      >
        {/* Collapse-all / expand-all — resets every accordion group. */}
        <Button
          size="small"
          startIcon={
            anyExpanded ? <UnfoldLessIcon fontSize="small" /> : <UnfoldMoreIcon fontSize="small" />
          }
          onClick={() => setAllSections(!anyExpanded)}
          sx={{
            textTransform: 'none',
            color: 'text.secondary',
            px: 1,
            '&:hover': { bgcolor: 'action.hover' },
          }}
        >
          {anyExpanded ? __('Collapse all', 'goalcart') : __('Expand all', 'goalcart')}
        </Button>
        <Chip label={`v${boot.version}`} size="small" variant="outlined" />
      </Box>
    </>
  );

  return (
    <Box
      sx={{
        display: 'flex',
        flexDirection: 'column',
        // Full-screen mode: the page hides the WP admin chrome (see
        // assets/css/admin-fullscreen.css) so the app owns the whole
        // viewport — fixed header + sidebar, only the content area
        // scrolls. Embedded mode: normal document flow inside the WP
        // admin chrome.
        ...(fullscreen
          ? {
              height: '100vh',
              overflow: 'hidden',
              // Mobile: 100dvh tracks the visible area (URL bar included).
              '@supports (height: 100dvh)': { height: '100dvh' },
            }
          : { minHeight: '100vh' }),
      }}
    >
      {/* Header — in normal flow so it never slides under WordPress's
          own admin bar (which is fixed with a higher z-index). */}
      <AppBar
        position="static"
        color="default"
        elevation={0}
        sx={{
          bgcolor: '#ffffff',
          borderBottom: 1,
          borderColor: 'divider',
        }}
      >
        <Toolbar>
          <IconButton
            color="inherit"
            edge="start"
            onClick={() => setMobileOpen(true)}
            sx={{ mr: 2, display: { md: 'none' } }}
            aria-label={__('Open navigation', 'goalcart')}
          >
            <MenuIcon />
          </IconButton>

          <Typography
            variant="h6"
            component="h1"
            sx={{ flexGrow: 1, fontWeight: 600, fontSize: '1.05rem' }}
          >
            Goal Cart
          </Typography>

          <Tooltip title={__('View store', 'goalcart')}>
            <IconButton
              component="a"
              href={boot.homeUrl}
              target="_blank"
              rel="noreferrer"
              aria-label={__('View store', 'goalcart')}
            >
              <StorefrontIcon fontSize="small" />
            </IconButton>
          </Tooltip>

          <IconButton
            onClick={(event) => setUserMenuAnchor(event.currentTarget)}
            aria-label={__('User menu', 'goalcart')}
            sx={{ ml: 1 }}
          >
            <Avatar
              src={boot.user.avatarUrl || undefined}
              sx={{
                width: 32,
                height: 32,
                bgcolor: 'primary.main',
                fontSize: 14,
              }}
            >
              {boot.user.displayName.charAt(0).toUpperCase()}
            </Avatar>
          </IconButton>

          <Menu
            anchorEl={userMenuAnchor}
            open={Boolean(userMenuAnchor)}
            onClose={() => setUserMenuAnchor(null)}
          >
            <MenuItem disabled sx={{ opacity: 1 }}>
              <Box>
                <Typography variant="body2" sx={{ fontWeight: 600 }}>
                  {boot.user.displayName}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {boot.siteName}
                </Typography>
              </Box>
            </MenuItem>
            <Divider />
            <MenuItem component="a" href={boot.adminUrl} onClick={() => setUserMenuAnchor(null)}>
              {__('Back to WordPress Admin', 'goalcart')}
            </MenuItem>
            <MenuItem
              component="a"
              href={`${boot.adminUrl}profile.php`}
              onClick={() => setUserMenuAnchor(null)}
            >
              {__('Edit profile', 'goalcart')}
            </MenuItem>
          </Menu>
        </Toolbar>
      </AppBar>

      <Box
        sx={{
          display: 'flex',
          alignItems: 'stretch',
          flexGrow: 1,
          // Full-screen mode bounds the row height so the content area
          // scrolls internally; embedded mode lets the page grow.
          ...(fullscreen ? { minHeight: 0, overflow: 'hidden' } : {}),
        }}
      >
        {/* Desktop sidebar — rendered in flow (paper is relative, not
            fixed) so it sits beside WordPress's own admin sidebar
            instead of overlapping it. */}
        <Box
          component="nav"
          sx={{
            width: { md: DRAWER_WIDTH },
            flexShrink: { md: 0 },
            display: { xs: 'none', md: 'block' },
          }}
          aria-label={__('Sidebar', 'goalcart')}
        >
          <Drawer
            variant="permanent"
            open
            sx={{
              height: '100%',
              '& .MuiDrawer-paper': {
                position: 'relative',
                width: DRAWER_WIDTH,
                height: '100%',
                boxSizing: 'border-box',
                borderRight: 1,
                borderColor: 'divider',
              },
            }}
          >
            {sidebar}
          </Drawer>
        </Box>

        {/* Mobile sidebar (temporary overlay) */}
        <Drawer
          variant="temporary"
          open={mobileOpen}
          onClose={() => setMobileOpen(false)}
          ModalProps={{ keepMounted: true }}
          sx={{
            display: { xs: 'block', md: 'none' },
            '& .MuiDrawer-paper': {
              width: DRAWER_WIDTH,
              boxSizing: 'border-box',
            },
          }}
        >
          {sidebar}
        </Drawer>

        <Box
          component="main"
          sx={(theme) => ({
            flexGrow: 1,
            minWidth: 0,
            ...(fullscreen ? { height: '100%', overflowY: 'auto', ...thinScrollbar(theme) } : {}),
            p: { xs: 2, md: 3 },
          })}
        >
          {/* Catch render errors in any route instead of blanking the app. */}
          <ErrorBoundary>
            <Outlet />
          </ErrorBoundary>
        </Box>
      </Box>
    </Box>
  );
}
