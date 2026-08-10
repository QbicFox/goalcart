import { __, sprintf } from '@wordpress/i18n';

import { formatCurrency } from '../lib/format';
import type { ProgressReward } from '../types';

/** Reward-type base labels (mirrors ProgressUI::reward_labels()). */
export const REWARD_LABELS: Record<string, string> = {
  free_shipping: __('Free shipping', 'goalcart'),
  percent_discount: __('% discount', 'goalcart'),
  fixed_discount: __('Fixed discount', 'goalcart'),
  free_gift: __('Free gift', 'goalcart'),
  coupon: __('Coupon', 'goalcart'),
};

/** Value-aware reward label (mirrors MessageEngine::reward_label). */
export function rewardLabel(reward: ProgressReward): string {
  const base = REWARD_LABELS[reward.type ?? ''] ?? reward.type ?? '';

  if (reward.type === 'percent_discount' && reward.value !== null) {
    return sprintf(
      /* translators: %d: discount percentage. */
      __('%d%% discount', 'goalcart'),
      Math.round(reward.value)
    );
  }

  if (reward.type === 'fixed_discount' && reward.value !== null) {
    return sprintf(
      /* translators: %s: formatted discount amount. */
      __('Fixed %s off', 'goalcart'),
      formatCurrency(reward.value)
    );
  }

  return base;
}
