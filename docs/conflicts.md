# FaraCart — Conflict & Priority Engine

> **Phase 26 / Tasks P26-T01–T04.** The single deterministic rule for what happens when
> multiple missions/campaigns are active at the same time. The engine is authoritative in
> `includes/Missions/ConflictResolver.php`; this document explains the ordering, the resolution
> modes, mutually exclusive missions, and how the behavior is communicated to admins and shoppers.

---

## 1. Objective (P26-T01)

When several missions are active — standalone missions, campaign milestones, or both — the plugin
must behave **deterministically**. Two consumers share the exact same resolution:

1. **`RewardEngine::sync_cart()`** — decides which completed missions *grant* their rewards on
   the live WooCommerce cart.
2. **`FrontendController` / `PreviewController`** — decide which completed missions the
   storefront payload and the admin preview report as *won* (reward active) vs *suppressed*
   (reward blocked), so what a shopper or an admin sees is always what the cart grants.

The resolution is driven by three inputs:

| Input | Where it lives | Role |
|---|---|---|
| Mission priority | `missions.priority` (lower wins) | Primary mission-level tie-breaker |
| Campaign priority | `campaigns.priority` (lower wins) | Primary campaign-level sort key |
| Exclusive flag | `missions.exclusive` | Mutually exclusive missions |
| Resolution mode | `conflict_resolution` setting | cumulative · best · first |

## 2. Deterministic order (P26-T01)

`MissionRepository::active_missions()` returns the active missions (with campaign status/schedule
gating folded in) in a single deterministic order:

```sql
ORDER BY COALESCE(campaigns.priority, 10) ASC, missions.priority ASC, missions.id ASC
```

- **Campaign priority first** — a mission inside a higher-priority campaign (lower number)
  wins conflicts over missions in a lower-priority campaign, regardless of the individual
  missions' priorities.
- **Standalone missions compete at campaign priority 10** (`MissionRepository::DEFAULT_CAMPAIGN_PRIORITY`,
  the schema default), so they interleave with campaigns deterministically.
- **Then mission priority, then id** for a stable tie-break.

## 3. Resolution modes (P26-T02 / P26-T03)

The store-wide `conflict_resolution` setting (Settings → General → Conflict resolution)
selects how *completed* missions with rewards compete:

| Mode | Behavior |
|---|---|
| `cumulative` (default) | Every completed mission grants, subject to the per-reward stacking rules (`RewardSafety::stacking_allows()`). Preserves the pre-Phase-26 behavior exactly. |
| `first` | Only the **first matching mission** in priority order grants; every later completed mission is suppressed (`not_first`). |
| `best` | Only the completed mission with the **best reward** grants (`not_best` for the rest). "Best" compares the reward's computed discount amount on the current cart when available (the `RewardEngine` pass — percentage discounts resolved to their real value); otherwise a deterministic static score (fixed/percentage value; free shipping, gifts and coupons count as equal-value offers). Ties break by priority order, then id. |

**Display/grant parity:** the storefront payload (`FrontendController`) and the admin
preview (`PreviewController`) resolve with the *same* inputs as the live cart — the
reward engine is injected into both, so `best` compares the same computed amounts and
`apply_stacking()` mirrors the engine's pass-2 stacking suppression. The reasons a
shopper or an admin sees are exactly what the cart grants.

Suppressed missions still show their *progress* on the storefront (a shopper may be working
toward the next milestone) but their reward never renders as unlocked and never grants.

## 4. Mutually exclusive missions (P26-T03)

A mission marked **Exclusive** (`missions.exclusive = 1`) in the mission builder is mutually
exclusive: when it is **completed**, every *lower-priority* completed mission is suppressed
(`exclusive` reason) in every mode. Priority above the exclusive mission is still respected —
an exclusive mission means "I win over everything below me", never "I silence everything".

Exclusivity is resolved **before** mode selection, so mode rules can never undo an
exclusive mission's win (e.g. in `best` mode an exclusive mission beats a higher-value
lower-priority reward).

## 5. Reasons & payload contract

The resolver returns `mission_id => reason`; an empty reason means the mission wins. The reasons
flow into the `RewardResult` (`blocked` state) and the progress/preview payload's
`conflict` fragment:

| Reason | Meaning |
|---|---|
| `''` (empty) | Wins — the reward may grant |
| `lower_priority` | Reserved for priority-based suppression |
| `not_first` | `first` mode: a higher-priority mission won |
| `not_best` | `best` mode: another reward was more valuable |
| `exclusive` | A completed exclusive mission suppressed this mission |
| `stacking` | A same-type non-stacking reward lost to stacking safety (display mirrors the cart grant exactly) |

The public `GET /faracart/v1/progress` payload and the admin `POST /faracart/v1/preview`
payload carry per mission:

```json
"conflict": { "resolved": true, "reason": "" }
```

`resolved: false` means the mission's reward is blocked by a conflict, with the machine-readable
`reason`. The storefront widget (`assets/js/frontend.js`) renders a suppressed reward as
**locked** (never unlocked) and reports `goal_completed` instead of `reward_activated` for
it; the admin preview shows a "Blocked — …" chip explaining why.

## 6. Admin UI communication

- **Settings → General → Conflict resolution** — pick the store-wide mode with a plain
  explanation of each option.
- **Mission builder → Priority & conflicts** — priority field (lower wins) plus the
  **Exclusive (mutually exclusive)** toggle with its behavior explained.
- **Missions list** — exclusive missions carry an "Exclusive" chip.
- **Campaign builder → Schedule & priority** — campaign priority participates in conflict
  resolution (campaigns compete before missions).
- **Mission/campaign preview** — suppressed milestones show a conflict chip with the reason.

## 7. Edge cases

| Case | Behavior |
|---|---|
| Reward-less completed missions | Never compete; they grant nothing in every mode |
| Incomplete missions | Never participate in resolution |
| Exclusive mission not completed | Suppresses nothing |
| Exclusive + `first` | The exclusive mission wins if it is (or is above) the first winner; otherwise the first mission wins and the exclusive mission is suppressed by priority |
| Exclusive + `best` | The exclusive mission suppresses everything below it, then the best among the remaining wins |
| Ties in `best` | Priority order breaks the tie |
| Same-type non-stacking rewards | Stacking safety still applies *within* the winning set (cumulative mode) — and the payload mirrors it: the later same-type non-stacking winner is reported `stacking`, never as unlocked |
| Campaign gating | Missions in inactive/out-of-schedule campaigns never reach resolution |

## 8. Design decisions

| Decision | Rationale |
|---|---|
| Campaign priority as the primary sort key | A campaign is a deliberate merchandising unit; its priority should outrank any mission inside it |
| Exclusive resolved before modes | Guarantees an exclusive mission can never be undone by `best` — the rule stays simple to explain |
| Resolution shared by engine + payload | The reward granted and the reward displayed can never drift apart |
| Static fallback for `best` | Without a cart (display-only paths), fixed/percentage values give a deterministic comparison; with a cart, real computed amounts win |
| Cumulative as the default | Zero behavior change for existing installs until an admin opts in |
