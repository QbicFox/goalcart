import { apiFetch } from './client';
import type { CampaignInput, MissionInput, PreviewPayload, PreviewSimulated } from '../types';

export interface PreviewRequest {
  missionId?: number;
  campaignId?: number;
  /**
   * Unsaved mission form state (MissionInput). When present the backend previews
   * the mission as configured in the form — merging over the stored row when
   * a missionId is also given — so the builder's live preview reflects the
   * current form values before they are persisted.
   */
  mission?: MissionInput;
  /**
   * Unsaved campaign form state (CampaignInput). When present the backend
   * previews the campaign's milestone missions with the form's name, order
   * and display_rules.
   */
  campaign?: CampaignInput;
  simulated: PreviewSimulated;
}

/**
 * Evaluate a mission or campaign against a SIMULATED cart.
 *
 * `POST /faracart/v1/preview` — admin-only. The backend builds a synthetic
 * cart context from the simulated amount/quantity and returns the same
 * per-mission payload shape as the public `/progress` endpoint, so the admin
 * preview renders the real storefront widget (templates, messages,
 * rewards, suggestions). The endpoint never touches the live WooCommerce
 * cart and ignores publish gating, so drafts, scheduled campaigns and
 * unsaved builder forms can be seen before they go live.
 */
export async function fetchPreview({
  missionId,
  campaignId,
  mission,
  campaign,
  simulated,
}: PreviewRequest): Promise<PreviewPayload> {
  const body: Record<string, unknown> = { simulated };

  if (missionId) {
    body.mission_id = missionId;
  }
  if (campaignId) {
    body.campaign_id = campaignId;
  }
  if (mission) {
    body.mission = mission;
  }
  if (campaign) {
    body.campaign = campaign;
  }

  return apiFetch<PreviewPayload>('/preview', {
    method: 'POST',
    body: JSON.stringify(body),
  });
}
