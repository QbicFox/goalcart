# FaraCart — Campaigns

## Overview

A **campaign** is a collection of missions active under specific conditions and schedules. Campaigns group missions around events or seasons (e.g., "Summer Sale" with free shipping at 500K, a gift at 1M, a discount at 1.5M).

## Campaign Model

| Field | Type | Notes |
|---|---|---|
| `id` | bigint | Auto-increment PK |
| `name` | varchar(191) | Display name |
| `description` | text | Internal description |
| `status` | varchar(20) | `active` / `inactive` |
| `starts_at` / `ends_at` | datetime | Schedule window (nullable) |
| `priority` | int | Campaign priority (lower wins conflicts) |
| `display_rules` | JSON | Template, schedule, display settings |

## Milestone Ordering

Missions belong to a campaign via `missions.campaign_id` + `missions.menu_order`. The campaign's milestones are ordered by `menu_order` and evaluated in sequence.

Example:
```
Campaign: Summer Sale
  1. 500,000 → Free Shipping
  2. 1,000,000 → Free Gift
  3. 1,500,000 → 10% Discount
  4. 2,000,000 → Premium Gift
```

## Campaign Templates

Campaigns can render as a unit using campaign-scoped templates:

| Template | Scope | Layout |
|---|---|---|
| `milestone_chain` | campaign | Connected milestone ladder with dots, names, targets, rewards and overall progress bar |
| `campaign_progress` | campaign | One overall progress bar with milestone counter |

Without a campaign template, each milestone renders as its own mission card.

## Conflict Resolution

Campaign priority is the **primary sort key** for conflict resolution:

```sql
ORDER BY COALESCE(campaigns.priority, 10) ASC, missions.priority ASC, missions.id ASC
```

- Campaign priority outranks any mission inside it
- Standalone missions compete at campaign priority 10

## Scheduled Campaigns

Campaigns can have recurring day/time rules in `display_rules.schedule_days`, `schedule_start_time`, `schedule_end_time`. These rules fold onto milestones that lack their own schedule.

## REST API

| Endpoint | Method | Description |
|---|---|---|
| `GET /campaigns` | GET | All campaigns with mission_count |
| `GET /campaigns/{id}` | GET | Single campaign with ordered missions |
| `POST /campaigns` | POST | Create campaign |
| `PUT /campaigns/{id}` | PUT | Update campaign |
| `DELETE /campaigns/{id}` | DELETE | Delete campaign (missions detached) |
| `POST /campaigns/{id}/duplicate` | POST | Duplicate campaign (inactive copy) |

## Admin UI

The Campaign Builder (`admin-app/src/routes/CampaignBuilder.tsx`) provides:

- **Basic Information** — name, description, status
- **Schedule & Priority** — start/end dates, priority, recurring schedule
- **Milestones** — ordered mission list with drag-and-drop reordering
- **Display** — campaign template, template settings
