import CampaignIcon from '@mui/icons-material/Campaign';
import { __ } from '@wordpress/i18n';

import EmptyState from '../components/EmptyState';
import PageContainer from '../components/PageContainer';

/**
 * Campaigns (P08-T03): page container.
 *
 * Campaigns bundle multiple goals into scheduled, prioritized
 * milestones. The full Campaign Builder (CRUD, ordering, scheduling) is
 * implemented by Phase 10; the Phase 7 REST layer already exposes a
 * read-only campaign list this page will consume.
 */
export default function Campaigns() {
  return (
    <PageContainer
      title={__('Campaigns', 'goalcart')}
      description={__(
        'Group goals into scheduled campaigns — e.g. a summer sale with free shipping, a gift and a discount at different thresholds.',
        'goalcart'
      )}
    >
      <EmptyState
        icon={<CampaignIcon fontSize="large" />}
        title={__('Campaign builder coming soon', 'goalcart')}
        description={__(
          'The Campaign Builder is implemented in a later phase. Until then you can already assign goals to campaigns through the REST API.',
          'goalcart'
        )}
      />
    </PageContainer>
  );
}
