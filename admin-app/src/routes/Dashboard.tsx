import { useQuery } from '@tanstack/react-query';
import FlagIcon from '@mui/icons-material/Flag';
import InsightsIcon from '@mui/icons-material/Insights';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';
import ToggleOffIcon from '@mui/icons-material/ToggleOff';
import ToggleOnIcon from '@mui/icons-material/ToggleOn';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Paper from '@mui/material/Paper';
import Skeleton from '@mui/material/Skeleton';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { fetchGoals } from '../api/goals';
import { getBootData } from '../boot';
import EmptyState from '../components/EmptyState';
import PageContainer from '../components/PageContainer';

interface StatCardProps {
  label: string;
  value: string;
  icon: ReactNode;
  loading: boolean;
}

/** Small KPI card with a skeleton while loading. */
function StatCard({ label, value, icon, loading }: StatCardProps) {
  return (
    <Card variant="outlined">
      <CardContent>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 1, color: 'text.secondary' }}>
          {icon}
          <Typography variant="body2" color="text.secondary">
            {label}
          </Typography>
        </Box>
        {loading ? (
          <Skeleton variant="text" width={96} height={40} />
        ) : (
          <Typography variant="h4" component="p" sx={{ m: 0 }}>
            {value}
          </Typography>
        )}
      </CardContent>
    </Card>
  );
}

/**
 * Dashboard (P08-T03): the admin shell's landing page.
 *
 * Shows a live summary of the goals the plugin is running (read from the
 * Phase 7 REST API) plus the current system state — the full analytics
 * dashboard is built by Phases 16–17, so this page stays a summary until
 * then. Loading skeletons, an error alert and an empty state cover every
 * query state.
 */
export default function Dashboard() {
  const boot = getBootData();

  const goalsQuery = useQuery({
    queryKey: ['goals', 'summary'],
    queryFn: () => fetchGoals({ per_page: 100 }),
  });

  const goals = goalsQuery.data?.items ?? [];
  const active = goals.filter((goal) => goal.status === 'active').length;
  const inactive = goals.length - active;

  return (
    <PageContainer
      title={__('Dashboard', 'goalcart')}
      description={__(
        'An overview of your cart goals and the plugin status. Full analytics arrive in a later phase.',
        'goalcart'
      )}
    >
      {goalsQuery.isError && (
        <Alert severity="error" variant="outlined">
          {goalsQuery.error instanceof Error
            ? goalsQuery.error.message
            : __('Could not load the goal summary.', 'goalcart')}
        </Alert>
      )}

      {!goalsQuery.isLoading && !goalsQuery.isError && goals.length === 0 ? (
        <EmptyState
          icon={<FlagIcon fontSize="large" />}
          title={__('No goals yet', 'goalcart')}
          description={__(
            'Create your first goal to start increasing the average order value — progress bars and rewards appear on the storefront once a goal is active.',
            'goalcart'
          )}
        />
      ) : (
        <Box
          sx={{
            display: 'grid',
            gridTemplateColumns: {
              xs: 'repeat(2, 1fr)',
              sm: 'repeat(3, 1fr)',
              xl: 'repeat(5, 1fr)',
            },
            gap: 2,
          }}
        >
          <StatCard
            label={__('Total goals', 'goalcart')}
            value={String(goalsQuery.data?.total ?? '')}
            icon={<FlagIcon fontSize="small" />}
            loading={goalsQuery.isLoading}
          />
          <StatCard
            label={__('Active', 'goalcart')}
            value={String(active)}
            icon={<ToggleOnIcon fontSize="small" />}
            loading={goalsQuery.isLoading}
          />
          <StatCard
            label={__('Inactive', 'goalcart')}
            value={String(inactive)}
            icon={<ToggleOffIcon fontSize="small" />}
            loading={goalsQuery.isLoading}
          />
          <StatCard
            label={__('Currency', 'goalcart')}
            value={boot.currency || '—'}
            icon={<InsightsIcon fontSize="small" />}
            loading={goalsQuery.isLoading}
          />
          <StatCard
            label={__('Plugin version', 'goalcart')}
            value={`v${boot.version}`}
            icon={<InfoOutlinedIcon fontSize="small" />}
            loading={false}
          />
        </Box>
      )}

      <Paper variant="outlined" sx={{ p: { xs: 2.5, md: 3 } }}>
        <Typography variant="h6" component="h3" gutterBottom>
          {__('What is Goal Cart?', 'goalcart')}
        </Typography>
        <Typography variant="body2" color="text.secondary">
          {sprintf(
            /* translators: %s: site name. */
            __(
              '%s uses cart goals — like “spend %s more for free shipping” — to encourage bigger carts. Goals, rewards and progress bars are configured from the Goals page; the frontend widgets are added in a later phase.',
              'goalcart'
            ),
            boot.siteName,
            boot.currency
          )}
        </Typography>
      </Paper>
    </PageContainer>
  );
}
