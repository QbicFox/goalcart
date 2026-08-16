import type { ComponentType } from 'react';

import type { ProgressCampaign, ProgressGoal, TemplateSettingsValue } from '../types';
import CampaignProgressTemplateRenderer from './renderers/CampaignProgressTemplateRenderer';
import MilestoneChainTemplateRenderer from './renderers/MilestoneChainTemplateRenderer';
import Template1Renderer from './renderers/Template1Renderer';
import Template2Renderer from './renderers/Template2Renderer';
import Template3Renderer from './renderers/Template3Renderer';
import Template4Renderer from './renderers/Template4Renderer';
import Template5Renderer from './renderers/Template5Renderer';
import Template6Renderer from './renderers/Template6Renderer';

/**
 * The props every Goal template renderer receives. Renderers draw only
 * the template-specific body (bar / percent / rung / card header); the
 * shared chrome (reward chip, message, suggestions, conflict note) lives
 * in PreviewWidget so all templates stay consistent.
 */
export interface GoalTemplateProps {
  goal: ProgressGoal;
  currency: string;
  /** The resolved settings for this template (schema-conformant). */
  settings: TemplateSettingsValue;
  animation: boolean;
}

/**
 * The props the Campaign template renderers receive: the whole milestone
 * group, because a campaign template renders the campaign as a unit.
 */
export interface CampaignTemplateProps {
  campaign: ProgressCampaign;
  goals: ProgressGoal[];
  currency: string;
  settings: TemplateSettingsValue;
  animation: boolean;
}

export type GoalTemplateRenderer = ComponentType<GoalTemplateProps>;
export type CampaignTemplateRenderer = ComponentType<CampaignTemplateProps>;

/**
 * The React template registry: `template id → renderer component`.
 *
 * The backend (GET /faracart/v1/templates) is the source of truth for
 * which templates exist and their settings schemas; this registry only
 * supplies the rendering components, keyed by the same stable ids.
 * Consumers look components up by property access (never by call
 * result) so the component references stay static across renders. A
 * template that is no longer registered falls back to the first design
 * template (`GOAL_RENDERERS['template-1']`) rather than failing.
 */
export const GOAL_RENDERERS: Record<string, GoalTemplateRenderer> = {
  'template-1': Template1Renderer,
  'template-2': Template2Renderer,
  'template-3': Template3Renderer,
  'template-4': Template4Renderer,
  'template-5': Template5Renderer,
  'template-6': Template6Renderer,
};

/**
 * The registered Campaign template renderers. A campaign whose template
 * id is missing here renders as per-goal cards instead (the storefront
 * grouping check looks the id up in this map).
 */
export const CAMPAIGN_RENDERERS: Record<string, CampaignTemplateRenderer> = {
  milestone_chain: MilestoneChainTemplateRenderer,
  campaign_progress: CampaignProgressTemplateRenderer,
};
