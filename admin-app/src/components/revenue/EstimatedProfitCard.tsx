import Accordion from '@mui/material/Accordion';
import AccordionDetails from '@mui/material/AccordionDetails';
import AccordionSummary from '@mui/material/AccordionSummary';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Divider from '@mui/material/Divider';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import HelpOutlineIcon from '@mui/icons-material/HelpOutlineOutlined';
import SavingsIcon from '@mui/icons-material/Savings';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { __, sprintf } from '@wordpress/i18n';
import { useState } from 'react';

import { getBootData } from '../../boot';
import { formatCurrency, formatPercentValue } from '../../lib/format';
import type { CostCoverage, CostSource, ProfitDetails, ProfitReasonCode } from '../../types';

/** User-facing labels for the cost sources the estimator consults (§10). */
const SOURCE_LABELS: Record<CostSource, string> = {
  _goalcart_product_cost: __("Goal Cart's product cost field", 'goalcart'),
  _cost: __('WooCommerce product cost field (_cost)', 'goalcart'),
  _wc_cog_cost: __('Cost of goods field (_wc_cog_cost)', 'goalcart'),
  goalcart_product_cost: __('The goalcart_product_cost filter', 'goalcart'),
  variation_fallback: __('Variation falls back to its parent product', 'goalcart'),
};

interface EstimatedProfitCardProps {
  profitImpact: number | null;
  profitAvailable: boolean;
  profitReason: string | null;
  profitReasonCode: ProfitReasonCode;
  profitDetails: ProfitDetails | null;
  costCoverage: CostCoverage;
  costSources: CostSource[];
  storeHasCostData: boolean | null;
}

/**
 * The Estimated Profit KPI card (Improvement.md §8/§10–§13, Phase 4).
 *
 * Renders every profit data state without ever inventing a number:
 *
 *  - available     → the value (zero stays 0, negative stays negative with
 *                    a short explanation), an "Estimated, not guaranteed"
 *                    label and an expandable profit-model panel (§12)
 *  - no cost data  → "Not available" + the §10 guidance and a "Set up
 *                    product costs" CTA that opens the in-plugin help
 *                    panel (cost sources, how the model works)
 *  - limited data  → "Limited data" + cost coverage (only orders with
 *                    complete cost data contribute, §11)
 *  - no data yet   → "—" with a plain explanation
 */
export default function EstimatedProfitCard({
  profitImpact,
  profitAvailable,
  profitReason,
  profitReasonCode,
  profitDetails,
  costCoverage,
  costSources,
  storeHasCostData,
}: EstimatedProfitCardProps) {
  const [helpOpen, setHelpOpen] = useState(false);
  const [detailsOpen, setDetailsOpen] = useState(false);

  const isAvailable = profitAvailable && profitImpact !== null;
  const isNegative = isAvailable && profitImpact < 0;
  const noCostData = profitReasonCode === 'missing_product_cost' || storeHasCostData === false;
  const limitedData = profitReasonCode === 'incomplete_product_cost';
  const noDataYet = profitReasonCode === 'insufficient_data';

  const coveragePct = costCoverage.coverage_pct;
  const marginAmount =
    isAvailable && profitDetails && profitDetails.margin_pct !== null
      ? profitDetails.incremental_revenue * profitDetails.margin_pct
      : null;

  const productsUrl = `${getBootData().adminUrl}edit.php?post_type=product`;

  return (
    <Card variant="outlined" sx={{ height: '100%' }}>
      <CardContent sx={{ p: 2, '&:last-child': { pb: 2 }, display: 'flex', flexDirection: 'column', gap: 1 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, color: 'text.secondary' }}>
          <SavingsIcon fontSize="small" />
          <Typography variant="body2" color="text.secondary" noWrap>
            {__('Estimated Profit', 'goalcart')}
          </Typography>
        </Box>

        {isAvailable ? (
          <Typography variant="h5" component="p" sx={{ m: 0, fontWeight: 600 }}>
            {formatCurrency(profitImpact)}
          </Typography>
        ) : (
          <Typography variant="h6" component="p" sx={{ m: 0, fontWeight: 600 }}>
            {noDataYet ? '—' : __('Not available', 'goalcart')}
          </Typography>
        )}

        <Typography variant="caption" color="text.secondary">
          {isAvailable
            ? limitedData
              ? __('Limited data — based on orders with complete cost information', 'goalcart')
              : __('Estimated, not guaranteed', 'goalcart')
            : noDataYet
              ? __('Not enough attributed order data yet.', 'goalcart')
              : __('Goal Cart needs product cost data to estimate profit.', 'goalcart')}
        </Typography>

        {isNegative && (
          <Typography variant="caption" color="text.secondary">
            {__('Rewards and shipping costs were higher than the estimated incremental margin.', 'goalcart')}
          </Typography>
        )}

        {noCostData && (
          <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1, alignItems: 'center' }}>
            <Button
              size="small"
              variant="contained"
              color="primary"
              onClick={() => setHelpOpen((open) => !open)}
              aria-expanded={helpOpen}
            >
              {__('Set up product costs', 'goalcart')}
            </Button>
            <Button
              size="small"
              variant="text"
              startIcon={<HelpOutlineIcon fontSize="small" />}
              onClick={() => setHelpOpen((open) => !open)}
              aria-expanded={helpOpen}
            >
              {__('Learn how', 'goalcart')}
            </Button>
          </Box>
        )}

        {limitedData && coveragePct !== null && (
          <Typography variant="caption" color="text.secondary">
            {sprintf(
              /* translators: 1: coverage percentage. */
              __('Cost data coverage: %1$s — profit is calculated only for orders with complete cost data.', 'goalcart'),
              formatPercentValue(coveragePct)
            )}
          </Typography>
        )}

        {isAvailable && profitDetails && (
          <Accordion
            disableGutters
            square
            expanded={detailsOpen}
            onChange={(_, expanded) => setDetailsOpen(expanded)}
            sx={{ boxShadow: 'none', border: '1px solid', borderColor: 'divider', borderRadius: 1 }}
          >
            <AccordionSummary
              expandIcon={<ExpandMoreIcon />}
              sx={{ '& .MuiAccordionSummary-content': { m: 0, py: 0.5 } }}
            >
              <Typography variant="body2">{__('How is this calculated?', 'goalcart')}</Typography>
            </AccordionSummary>
            <AccordionDetails sx={{ pt: 0 }}>
              <Stack spacing={0.75}>
                <DetailRow
                  label={__('Sales attributed', 'goalcart')}
                  value={formatCurrency(profitDetails.incremental_revenue)}
                />
                <DetailRow
                  label={__('Estimated product margin', 'goalcart')}
                  value={marginAmount !== null ? formatCurrency(marginAmount) : '—'}
                />
                <DetailRow label={__('Reward cost', 'goalcart')} value={formatCurrency(profitDetails.reward_cost)} />
                <DetailRow
                  label={__('Shipping cost', 'goalcart')}
                  value={profitDetails.shipping_cost !== null ? formatCurrency(profitDetails.shipping_cost) : '—'}
                />
                <Divider />
                <DetailRow label={__('Estimated profit', 'goalcart')} value={formatCurrency(profitImpact ?? 0)} strong />
              </Stack>
              <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1 }}>
                {__(
                  'This is an analytical estimate based on available WooCommerce cost and order data. It is not accounting profit.',
                  'goalcart'
                )}
              </Typography>
            </AccordionDetails>
          </Accordion>
        )}

        {noCostData && (
          <Accordion
            disableGutters
            square
            expanded={helpOpen}
            onChange={(_, expanded) => setHelpOpen(expanded)}
            sx={{ boxShadow: 'none', border: '1px solid', borderColor: 'divider', borderRadius: 1 }}
          >
            <AccordionSummary
              expandIcon={<ExpandMoreIcon />}
              sx={{ '& .MuiAccordionSummary-content': { m: 0, py: 0.5 } }}
            >
              <Typography variant="body2">{__('How Estimated Profit becomes available', 'goalcart')}</Typography>
            </AccordionSummary>
            <AccordionDetails sx={{ pt: 0 }}>
              <Stack spacing={0.75}>
                <Typography variant="body2">{__('Goal Cart does not invent product costs.', 'goalcart')}</Typography>
                <Typography variant="body2">
                  {__(
                    'Product cost must come from WooCommerce or your product-cost data. Goal Cart reads, in order:',
                    'goalcart'
                  )}
                </Typography>
                <Box component="ul" sx={{ m: 0, pl: 2.5 }}>
                  {costSources.map((source) => (
                    <Box component="li" key={source}>
                      <Typography variant="body2">{SOURCE_LABELS[source] ?? source}</Typography>
                    </Box>
                  ))}
                </Box>
                <Typography variant="body2">
                  {__('Once cost data exists, Estimated Profit becomes available automatically.', 'goalcart')}
                </Typography>
                <Typography variant="body2">
                  {__('The calculation includes product cost/margin, reward cost, shipping cost and incremental revenue.', 'goalcart')}
                </Typography>
                <Button size="small" variant="outlined" href={productsUrl} target="_blank" rel="noreferrer">
                  {__('Open your products', 'goalcart')}
                </Button>
              </Stack>
            </AccordionDetails>
          </Accordion>
        )}

        {limitedData && !isAvailable && (
          <Typography variant="caption" color="text.secondary" component="p">
            {profitReason}
          </Typography>
        )}
      </CardContent>
    </Card>
  );
}

function DetailRow({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
  return (
    <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 2 }}>
      <Typography variant="body2" color="text.secondary">
        {label}
      </Typography>
      <Typography variant="body2" sx={{ fontWeight: strong ? 600 : 400 }}>
        {value}
      </Typography>
    </Box>
  );
}
