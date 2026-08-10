import { useQuery } from '@tanstack/react-query';

import { fetchTemplates } from '../api/templates';
import type { TemplateDefinition, TemplatesPayload, TemplateScope } from '../types';

/**
 * The shared `['templates']` query — one fetch per admin session, cached
 * under the same key by the Appearance page, the builders and the preview
 * dialogs.
 */
export function useTemplates() {
  return useQuery({ queryKey: ['templates'], queryFn: fetchTemplates });
}

/** Look up a registered template definition by scope + id. */
export function templateById(
  data: TemplatesPayload | undefined,
  scope: TemplateScope,
  id: string
): TemplateDefinition | undefined {
  return data?.[scope].find((template) => template.id === id);
}
