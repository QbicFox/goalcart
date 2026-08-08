import { apiFetch } from './client';
import type { PreviewPayload, PreviewSimulated } from '../types';

export interface PreviewRequest {
  goalId?: number;
  campaignId?: number;
  simulated: PreviewSimulated;
}

/**
 * Evaluate a goal or campaign against a SIMULATED cart (Phase 15).
 *
 * `POST /goalcart/v1/preview` — admin-only. The backend builds a synthetic
 * cart context from the simulated amount/quantity and returns the same
 * per-goal payload shape as the public `/progress` endpoint, so the admin
 * preview renders the real storefront widget (templates, messages,
 * rewards, suggestions). The endpoint never touches the live WooCommerce
 * cart and ignores publish gating, so drafts and scheduled campaigns can
 * be seen before they go live.
 */
export async function fetchPreview({
  goalId,
  campaignId,
  simulated,
}: PreviewRequest): Promise<PreviewPayload> {
  const body: Record<string, unknown> = { simulated };

  if (goalId) {
    body.goal_id = goalId;
  }
  if (campaignId) {
    body.campaign_id = campaignId;
  }

  return apiFetch<PreviewPayload>('/preview', {
    method: 'POST',
    body: JSON.stringify(body),
  });
}
