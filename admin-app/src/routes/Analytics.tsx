import InsightsIcon from '@mui/icons-material/Insights';
import { __ } from '@wordpress/i18n';

import EmptyState from '../components/EmptyState';
import PageContainer from '../components/PageContainer';

/**
 * Analytics (P08-T03): page container.
 *
 * Goal impressions, completions, completion rates and revenue influence
 * are collected from Phase 16 (Analytics Foundation) and visualized in
 * Phase 17 (Analytics Dashboard). The Phase 7 REST layer intentionally
 * defers analytics endpoints until that data exists.
 */
export default function Analytics() {
  return (
    <PageContainer
      title={__('Analytics', 'goalcart')}
      description={__(
        'Measure whether Goal Cart actually increases your average order value.',
        'goalcart'
      )}
    >
      <EmptyState
        icon={<InsightsIcon fontSize="large" />}
        title={__('No analytics yet', 'goalcart')}
        description={__(
          'Goal impressions, completions and revenue influence will appear here once event tracking ships in a later phase.',
          'goalcart'
        )}
      />
    </PageContainer>
  );
}
