import type { SvgIconComponent } from '@mui/icons-material';
import BarChartIcon from '@mui/icons-material/BarChart';
import CampaignIcon from '@mui/icons-material/Campaign';
import DashboardIcon from '@mui/icons-material/Dashboard';
import FlagIcon from '@mui/icons-material/Flag';
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
 * FaraCart sidebar navigation (UICHANGES.md §4 information architecture).
 *
 * Sales Performance is the single primary analytics destination — it
 * answers "is FaraCart helping my store sell more profitably?" with
 * business outcomes. Optimization holds the two "what should I change?"
 * engines: Recommendations (choose better Goal targets) and Upsells
 * (which products to recommend).
 *
 * The legacy Analytics page (/analytics) and Attribution Dashboard
 * (/revenue/attribution) stay reachable by direct URL for backward
 * compatibility but are deliberately NOT primary navigation items
 * (UICHANGES.md §25/§26/§39).
 */
export const NAV_SECTIONS: NavSection[] = [
  {
    title: __('FaraCart', 'faracart'),
    items: [
      { path: '/dashboard', label: __('Dashboard', 'faracart'), icon: DashboardIcon },
      { path: '/goals', label: __('Goals', 'faracart'), icon: FlagIcon },
      { path: '/campaigns', label: __('Campaigns', 'faracart'), icon: CampaignIcon },
    ],
  },
  {
    title: __('Sales Performance', 'faracart'),
    items: [
      { path: '/revenue', label: __('Overview', 'faracart'), icon: BarChartIcon },
      { path: '/revenue/goals', label: __('Goal Performance', 'faracart'), icon: LeaderboardIcon },
    ],
  },
  {
    title: __('Optimization', 'faracart'),
    items: [
      { path: '/optimization/goals', label: __('Recommendations', 'faracart'), icon: TipsAndUpdatesIcon },
      { path: '/optimization/upsells', label: __('Upsells', 'faracart'), icon: TrendingUpIcon },
    ],
  },
  {
    title: __('Configuration', 'faracart'),
    items: [
      { path: '/appearance', label: __('Appearance', 'faracart'), icon: PaletteIcon },
      { path: '/settings', label: __('Settings', 'faracart'), icon: SettingsIcon },
    ],
  },
];
