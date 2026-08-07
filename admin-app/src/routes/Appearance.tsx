import PaletteIcon from '@mui/icons-material/Palette';
import { __ } from '@wordpress/i18n';

import EmptyState from '../components/EmptyState';
import PageContainer from '../components/PageContainer';

/**
 * Appearance (P08-T03): page container.
 *
 * Templates, colors, typography and progress-bar customization are
 * implemented by Phase 12 (Progress Templates); this page hosts those
 * controls.
 */
export default function Appearance() {
  return (
    <PageContainer
      title={__('Appearance', 'goalcart')}
      description={__('Customize how the cart progress UI looks on your storefront.', 'goalcart')}
    >
      <EmptyState
        icon={<PaletteIcon fontSize="large" />}
        title={__('Templates coming soon', 'goalcart')}
        description={__(
          'Progress templates, colors, typography and custom CSS arrive in a later phase.',
          'goalcart'
        )}
      />
    </PageContainer>
  );
}
