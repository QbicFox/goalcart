import { apiFetch } from './client';
import type { TemplatesPayload } from '../types';

/**
 * Fetch every registered progress template grouped by scope with its
 * settings schema and effective defaults via `GET /goalcart/v1/templates`
 * (pluggable template engine). Admin-only, like every Goal Cart admin
 * endpoint. The backend is the source of truth for which templates exist
 * and what settings they accept; the React registry only supplies the
 * rendering components.
 */
export async function fetchTemplates(): Promise<TemplatesPayload> {
  return apiFetch<TemplatesPayload>('/templates');
}
