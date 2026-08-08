import { apiFetch } from './client';
import type { Campaign, CampaignInput } from '../types';

export interface CampaignsResult {
  items: Campaign[];
}

/** Fetch all campaigns via `GET /goalcart/v1/campaigns`. */
export async function fetchCampaigns(): Promise<CampaignsResult> {
  return apiFetch<CampaignsResult>('/campaigns');
}

/** Fetch a single campaign via `GET /goalcart/v1/campaigns/{id}`. */
export async function fetchCampaign(id: number): Promise<Campaign> {
  return apiFetch<Campaign>(`/campaigns/${id}`);
}

/** Create a campaign via `POST /goalcart/v1/campaigns`. */
export async function createCampaign(input: CampaignInput): Promise<Campaign> {
  return apiFetch<Campaign>('/campaigns', {
    method: 'POST',
    body: JSON.stringify(input),
  });
}

/** Update a campaign (partial) via `PUT /goalcart/v1/campaigns/{id}`. */
export async function updateCampaign(id: number, input: Partial<CampaignInput>): Promise<Campaign> {
  return apiFetch<Campaign>(`/campaigns/${id}`, {
    method: 'PUT',
    body: JSON.stringify(input),
  });
}

/** Delete a campaign via `DELETE /goalcart/v1/campaigns/{id}`. */
export async function deleteCampaign(id: number): Promise<void> {
  await apiFetch<{ deleted: boolean }>(`/campaigns/${id}`, { method: 'DELETE' });
}

/** Duplicate a campaign via `POST /goalcart/v1/campaigns/{id}/duplicate`. */
export async function duplicateCampaign(id: number): Promise<Campaign> {
  return apiFetch<Campaign>(`/campaigns/${id}/duplicate`, { method: 'POST' });
}
