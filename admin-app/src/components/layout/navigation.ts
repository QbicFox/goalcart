import type { SvgIconComponent } from '@mui/icons-material';
import BarChartIcon from '@mui/icons-material/BarChart';
import CampaignIcon from '@mui/icons-material/Campaign';
import DashboardIcon from '@mui/icons-material/Dashboard';
import FlagIcon from '@mui/icons-material/Flag';
import InsightsIcon from '@mui/icons-material/Insights';
import LeaderboardIcon from '@mui/icons-material/Leaderboard';
import PaletteIcon from '@mui/icons-material/Palette';
import SettingsIcon from '@mui/icons-material/Settings';
import TipsAndUpdatesIcon from '@mui/icons-material/TipsAndUpdates';
import TrendingUpIcon from '@mui/icons-material/TrendingUp';
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
    title: __('Sales Performance', 'goalcart'),
    items: [
      { path: '/revenue', label: __('Overview', 'goalcart'), icon: BarChartIcon },
      { path: '/revenue/goals', label: __('Goal Performance', 'goalcart'), icon: LeaderboardIcon },
      { path: '/revenue/recommendations', label: __('Recommendations', 'goalcart'), icon: TipsAndUpdatesIcon },
      { path: '/revenue/upsells', label: __('Upsell Analytics', 'goalcart'), icon: TrendingUpIcon },
    ],
  },
  // The Attribution Dashboard stays reachable at /revenue/attribution for
  // backward compatibility but is no longer a primary navigation item
  // (Improvement.md §3 — advanced attribution lives in the page drawers).
  {
    title: __('Configuration', 'goalcart'),
    items: [
      { path: '/appearance', label: __('Appearance', 'goalcart'), icon: PaletteIcon },
      { path: '/settings', label: __('Settings', 'goalcart'), icon: SettingsIcon },
    ],
  },
];
