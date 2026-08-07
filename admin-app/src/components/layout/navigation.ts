import type { SvgIconComponent } from '@mui/icons-material';
import CampaignIcon from '@mui/icons-material/Campaign';
import DashboardIcon from '@mui/icons-material/Dashboard';
import FlagIcon from '@mui/icons-material/Flag';
import InsightsIcon from '@mui/icons-material/Insights';
import PaletteIcon from '@mui/icons-material/Palette';
import SettingsIcon from '@mui/icons-material/Settings';
import { __ } from '@wordpress/i18n';

export interface NavItem {
  path: string;
  label: string;
  icon: SvgIconComponent;
}

/**
 * A labelled group of sidebar links. Section labels render as overline
 * captions between the groups.
 */
export interface NavSection {
  title: string;
  items: NavItem[];
}

/**
 * Goal Cart sidebar navigation (Phase 8 admin shell).
 *
 * The full feature pages (Goals CRUD, Campaigns, Analytics, Appearance)
 * are built by their phases (9, 10, 16–17, 12); the shell already routes
 * to their page containers.
 */
export const NAV_SECTIONS: NavSection[] = [
  {
    title: __('Goal Cart', 'goalcart'),
    items: [
      { path: '/dashboard', label: __('Dashboard', 'goalcart'), icon: DashboardIcon },
      { path: '/goals', label: __('Goals', 'goalcart'), icon: FlagIcon },
      { path: '/campaigns', label: __('Campaigns', 'goalcart'), icon: CampaignIcon },
      { path: '/analytics', label: __('Analytics', 'goalcart'), icon: InsightsIcon },
    ],
  },
  {
    title: __('Configuration', 'goalcart'),
    items: [
      { path: '/appearance', label: __('Appearance', 'goalcart'), icon: PaletteIcon },
      { path: '/settings', label: __('Settings', 'goalcart'), icon: SettingsIcon },
    ],
  },
];
