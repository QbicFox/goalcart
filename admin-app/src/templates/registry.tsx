import type { ComponentType } from 'react';

import type { ProgressCampaign, ProgressGoal, TemplateSettingsValue } from '../types';
import BasicTemplateRenderer from './renderers/BasicTemplateRenderer';
import CampaignProgressTemplateRenderer from './renderers/CampaignProgressTemplateRenderer';
import CardTemplateRenderer from './renderers/CardTemplateRenderer';
import MilestoneChainTemplateRenderer from './renderers/MilestoneChainTemplateRenderer';
import MilestoneTemplateRenderer from './renderers/MilestoneTemplateRenderer';
import PercentageTemplateRenderer from './renderers/PercentageTemplateRenderer';
import RingTemplateRenderer from './renderers/RingTemplateRenderer';

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
 * The backend (GET /goalcart/v1/templates) is the source of truth for
 * which templates exist and their settings schemas; this registry only
 * supplies the rendering components, keyed by the same stable ids. A
 * template that is no longer registered falls back to the basic renderer
 * rather than failing.
 */
const GOAL_RENDERERS: Record<string, GoalTemplateRenderer> = {
  basic: BasicTemplateRenderer,
  percentage: PercentageTemplateRenderer,
  milestone: MilestoneTemplateRenderer,
  card: CardTemplateRenderer,
  ring: RingTemplateRenderer,
};

const CAMPAIGN_RENDERERS: Record<string, CampaignTemplateRenderer> = {
  milestone_chain: MilestoneChainTemplateRenderer,
  campaign_progress: CampaignProgressTemplateRenderer,
};

/** Resolve a Goal template renderer (falls back to Basic). */
export function goalRenderer(id: string): GoalTemplateRenderer {
  return GOAL_RENDERERS[id] ?? BasicTemplateRenderer;
}

/** Resolve a Campaign template renderer (null = not registered → per-goal cards). */
export function campaignRenderer(id: string): CampaignTemplateRenderer | null {
  return CAMPAIGN_RENDERERS[id] ?? null;
}
