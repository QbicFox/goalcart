import { apiFetch } from './client';
import type { CampaignInput, GoalInput, PreviewPayload, PreviewSimulated } from '../types';

export interface PreviewRequest {
  goalId?: number;
  campaignId?: number;
  /**
   * Unsaved goal form state (GoalInput). When present the backend previews
   * the goal as configured in the form — merging over the stored row when
   * a goalId is also given — so the builder's live preview reflects the
   * current form values before they are persisted.
   */
  goal?: GoalInput;
  /**
   * Unsaved campaign form state (CampaignInput). When present the backend
   * previews the campaign's milestone goals with the form's name, order
   * and display_rules.
   */
  campaign?: CampaignInput;
  simulated: PreviewSimulated;
}

/**
 * Evaluate a goal or campaign against a SIMULATED cart (Phase 15).
 *
 * `POST /faracart/v1/preview` — admin-only. The backend builds a synthetic
 * cart context from the simulated amount/quantity and returns the same
 * per-goal payload shape as the public `/progress` endpoint, so the admin
 * preview renders the real storefront widget (templates, messages,
 * rewards, suggestions). The endpoint never touches the live WooCommerce
 * cart and ignores publish gating, so drafts, scheduled campaigns and
 * unsaved builder forms can be seen before they go live.
 */
export async function fetchPreview({
  goalId,
  campaignId,
  goal,
  campaign,
  simulated,
}: PreviewRequest): Promise<PreviewPayload> {
  const body: Record<string, unknown> = { simulated };

  if (goalId) {
    body.goal_id = goalId;
  }
  if (campaignId) {
    body.campaign_id = campaignId;
  }
  if (goal) {
    body.goal = goal;
  }
  if (campaign) {
    body.campaign = campaign;
  }

  return apiFetch<PreviewPayload>('/preview', {
    method: 'POST',
    body: JSON.stringify(body),
  });
}
