import ArrowForwardIcon from "@mui/icons-material/ArrowForward";
import CheckCircleOutlineOutlinedIcon from "@mui/icons-material/CheckCircleOutlineOutlined";
import ExpandLessIcon from "@mui/icons-material/ExpandLess";
import ExpandMoreIcon from "@mui/icons-material/ExpandMore";
import InfoOutlinedIcon from "@mui/icons-material/InfoOutlined";
import TipsAndUpdatesIcon from "@mui/icons-material/TipsAndUpdates";
import Alert from "@mui/material/Alert";
import Box from "@mui/material/Box";
import Button from "@mui/material/Button";
import Chip from "@mui/material/Chip";
import Collapse from "@mui/material/Collapse";
import Divider from "@mui/material/Divider";
import LinearProgress from "@mui/material/LinearProgress";
import Paper from "@mui/material/Paper";
import Skeleton from "@mui/material/Skeleton";
import Stack from "@mui/material/Stack";
import Typography from "@mui/material/Typography";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { __, sprintf } from "@wordpress/i18n";
import { useState, type ReactElement } from "react";

import { fetchMissions } from "../api/missions";
import {
	applyMissionRecommendation,
	fetchCostCoverage,
	fetchMissionRecommendations,
} from "../api/revenue";
import { getBootData } from "../boot";
import ConfirmDialog from "../components/ConfirmDialog";
import EmptyState from "../components/EmptyState";
import { useSnackbar } from "../components/notifications/SnackbarProvider";
import PageContainer from "../components/PageContainer";
import RevenueToolbar from "../components/revenue/RevenueToolbar";
import {
	formatCurrency,
	formatNumber,
	formatPercent,
	formatPercentValue,
} from "../lib/format";
import { REWARD_LABELS } from "../templates/rewardLabel";
import type {
	CostCoveragePayload,
	MissionRecommendationsPayload,
	RecommendationCandidate,
	RecommendationMissionHistory,
} from "../types";

/**
 * Business-friendly confidence label — the qualitative status is the primary
 * message, the raw 0–100 score stays as secondary text next to it
 * (80–100 High, 60–79 Medium, <60 Low).
 */
function confidenceTier(confidence: number): {
	label: string;
	color: "success" | "warning" | "default";
	icon: ReactElement;
} {
	if (confidence >= 80) {
		return {
			label: __("High confidence", "faracart"),
			color: "success",
			icon: <CheckCircleOutlineOutlinedIcon fontSize="small" />,
		};
	}
	if (confidence >= 60) {
		return {
			label: __("Medium confidence", "faracart"),
			color: "warning",
			icon: <InfoOutlinedIcon fontSize="small" />,
		};
	}
	return {
		label: __("Low confidence", "faracart"),
		color: "default",
		icon: <InfoOutlinedIcon fontSize="small" />,
	};
}

/** Data-sufficiency tier translated to business language (§45). */
function sufficiencyLabel(status: string): string {
	if (status === "high_confidence") {
		return __("Good data", "faracart");
	}
	if (status === "reliable") {
		return __("Moderate data", "faracart");
	}
	return __("Limited data", "faracart");
}

/** Small caption/value stat (optional progress bar) used in the cards. */
function StatBox({
	label,
	value,
	bar,
}: {
	label: string;
	value: string;
	bar?: number;
}) {
	return (
		<Box>
			<Typography variant="caption" color="text.secondary">
				{label}
			</Typography>
			<Typography variant="body2" sx={{ fontWeight: 600 }}>
				{value}
			</Typography>
			{bar !== undefined && (
				<LinearProgress
					variant="determinate"
					value={Math.min(100, Math.max(0, bar))}
					sx={{ height: 4, borderRadius: 2, mt: 0.5 }}
				/>
			)}
		</Box>
	);
}

/** Compact label/value pair for the raw scoring factors. */
function Factor({ label, value }: { label: string; value: string }) {
	return (
		<Box sx={{ display: "flex", alignItems: "baseline", gap: 1 }}>
			<Typography variant="body2" color="text.secondary">
				{label}
			</Typography>
			<Typography variant="body2" sx={{ fontWeight: 600 }}>
				{value}
			</Typography>
		</Box>
	);
}

/**
 * The raw scoring detail block (Improvement.md §33 — score, component
 * scores, ratios and availability flags). Only rendered inside the
 * "Advanced details" expander of the top recommendation card — never as
 * the primary experience.
 */
function AdvancedDetails({
	candidate,
}: {
	candidate: RecommendationCandidate;
}) {
	const factors = candidate.factors;

	return (
		<Stack spacing={1.5}>
			<Typography variant="body2" sx={{ fontWeight: 600 }}>
				{__("Advanced details", "faracart")}
			</Typography>

			<Box
				sx={{
					display: "grid",
					gridTemplateColumns: { xs: "repeat(2, 1fr)", md: "repeat(4, 1fr)" },
					gap: 1.5,
				}}
			>
				<StatBox
					label={__("Score", "faracart")}
					value={`${formatNumber(candidate.score)} / 100`}
					bar={candidate.score}
				/>
				<StatBox
					label={__("Confidence", "faracart")}
					value={formatPercentValue(candidate.confidence)}
					bar={candidate.confidence}
				/>
				<StatBox
					label={__("Expected completion", "faracart")}
					value={formatPercent(candidate.expected_completion_rate)}
				/>
				<StatBox
					label={__("Reachable orders", "faracart")}
					value={formatPercentValue(candidate.reachable_orders_pct)}
				/>
				{candidate.reward_cost !== null && (
					<StatBox
						label={__("Estimated reward cost", "faracart")}
						value={formatCurrency(candidate.reward_cost)}
					/>
				)}
			</Box>

			<Box>
				<Typography
					variant="caption"
					color="text.secondary"
					sx={{ fontWeight: 600 }}
				>
					{__("Scoring factors", "faracart")}
				</Typography>
				<Box
					sx={{
						display: "grid",
						gridTemplateColumns: { xs: "repeat(2, 1fr)", md: "repeat(4, 1fr)" },
						gap: 1.5,
						mt: 0.75,
					}}
				>
					<StatBox
						label={__("Reachability", "faracart")}
						value={formatNumber(factors.reachability_score)}
					/>
					<StatBox
						label={__("Distance", "faracart")}
						value={formatNumber(factors.distance_score)}
					/>
					<StatBox
						label={__("Economics", "faracart")}
						value={formatNumber(factors.economics_score)}
					/>
					<StatBox
						label={__("History", "faracart")}
						value={formatNumber(factors.history_score)}
					/>
				</Box>
				<Box sx={{ display: "flex", flexWrap: "wrap", gap: 1.5, mt: 1 }}>
					{factors.aov_ratio !== null && (
						<Factor
							label={__("AOV ratio", "faracart")}
							value={`${formatNumber(factors.aov_ratio)}×`}
						/>
					)}
					{factors.median_ratio !== null && (
						<Factor
							label={__("Median ratio", "faracart")}
							value={`${formatNumber(factors.median_ratio)}×`}
						/>
					)}
					<Factor
						label={__("Reach share", "faracart")}
						value={formatPercent(factors.reach_share)}
					/>
					<Factor
						label={__("Already at share", "faracart")}
						value={formatPercent(factors.already_at_share)}
					/>
					{/* margin_pct is a 0–1 rate (e.g. 0.6 = 60%) — formatPercent
              multiplies by 100; formatPercentValue would print "0.6%". */}
					{factors.margin_pct !== null && (
						<Factor
							label={__("Margin", "faracart")}
							value={formatPercent(factors.margin_pct)}
						/>
					)}
				</Box>
			</Box>
		</Stack>
	);
}

/**
 * The "Current Mission" block of the recommendation detail (UPSELL_REFACTOR
 * §9): current threshold, reward, completion + purchase rates, attributed
 * sales and estimated profit — all real analytics data, never fabricated.
 */
function CurrentMissionBlock({
	history,
}: {
	history: RecommendationMissionHistory | null;
}) {
	if (!history) {
		return null;
	}

	const profitValue =
		history.profit_available && history.estimated_profit !== null
			? formatCurrency(history.estimated_profit)
			: __("Not available", "faracart");

	return (
		<Box sx={{ mb: 2 }}>
			<Typography variant="body2" sx={{ fontWeight: 600 }}>
				{__("Current mission", "faracart")}
			</Typography>
			<Box
				sx={{
					display: "grid",
					gridTemplateColumns: { xs: "repeat(2, 1fr)", md: "repeat(4, 1fr)" },
					gap: 1.5,
					mt: 1,
				}}
			>
				<StatBox
					label={__("Current target", "faracart")}
					value={formatCurrency(history.current_target)}
				/>
				<StatBox
					label={__("Reward", "faracart")}
					value={
						history.reward_type
							? (REWARD_LABELS[history.reward_type] ?? history.reward_type)
							: __("None", "faracart")
					}
				/>
				<StatBox
					label={__("Completion rate", "faracart")}
					value={
						history.completion_rate === null
							? "—"
							: formatPercent(history.completion_rate)
					}
				/>
				<StatBox
					label={__("Purchase rate", "faracart")}
					value={
						history.purchase_rate === null
							? "—"
							: formatPercent(history.purchase_rate)
					}
				/>
				<StatBox
					label={__("Attributed sales", "faracart")}
					value={formatCurrency(history.attributed_sales)}
				/>
				<StatBox
					label={__("Estimated profit", "faracart")}
					value={profitValue}
				/>
				<StatBox
					label={__("Upsell-assisted completions", "faracart")}
					value={formatNumber(history.upsell_assisted)}
				/>
			</Box>
		</Box>
	);
}

/**
 * The primary recommendation card — one clear business action (§33).
 *
 * Manager-facing hierarchy: recommended target (dominant) → current vs
 * recommended → expected impact + profit → confidence → reach in plain
 * language → Apply. Detail lives behind two collapsed sections:
 * "Why this recommendation?" (max 4 plain reasons) and "Advanced
 * analysis" (current-mission stats + raw scoring factors).
 */
function TopRecommendationCard({
	candidate,
	missionId,
	missionName,
	missionHistory,
	reasonsOpen,
	advancedOpen,
	onToggleReasons,
	onToggleAdvanced,
	onApply,
}: {
	candidate: RecommendationCandidate;
	missionId: number;
	/** The selected mission's name — makes it unmistakable which mission the card belongs to. */
	missionName: string | null;
	missionHistory: RecommendationMissionHistory | null;
	reasonsOpen: boolean;
	advancedOpen: boolean;
	onToggleReasons: () => void;
	onToggleAdvanced: () => void;
	onApply: (candidate: RecommendationCandidate) => void;
}) {
	const tier = confidenceTier(candidate.confidence);
	const profitAvailable =
		candidate.expected_profit_available && candidate.expected_profit !== null;

	// Plain-language values: rounded to whole percents so a manager never
	// reads 23.38% or 7.02% — the exact numbers stay in the payload/advanced
	// details.
	const reachPct = Math.round(candidate.reachable_orders_pct);
	const impactLow = Math.round(candidate.expected_aov_impact.low);
	const impactHigh = Math.round(candidate.expected_aov_impact.high);
	const simpleReasons = candidate.reasons.slice(0, 4);

	// Current → recommended comparison; hidden when the mission has no
	// recorded history (nothing to compare against).
	const currentTarget = missionHistory ? missionHistory.current_target : null;
	const hasComparison =
		currentTarget !== null &&
		Math.abs(currentTarget - candidate.threshold) > 0.0001;

	return (
		<Paper
			variant="outlined"
			sx={{
				p: 2.5,
				borderColor: "primary.main",
				borderWidth: 2,
				position: "relative",
			}}
		>
			<Chip
				size="small"
				color="primary"
				icon={<TipsAndUpdatesIcon />}
				label={__("Top recommendation", "faracart")}
				sx={{ position: "absolute", top: -12, insetInlineStart: 16 }}
			/>
			{missionName && (
				<Chip
					size="small"
					variant="outlined"
					color="primary"
					label={missionName}
					sx={{ position: "absolute", top: -12, insetInlineEnd: 16 }}
				/>
			)}

			{/* Recommended target — the dominant message. */}
			<Box>
				<Typography variant="caption" color="text.secondary">
					{__("Recommended Mission Target", "faracart")}
				</Typography>
				<Box
					sx={{
						display: "flex",
						flexWrap: "wrap",
						gap: 2,
						alignItems: "center",
					}}
				>
					<Typography variant="h3" component="p" sx={{ m: 0, fontWeight: 700 }}>
						{formatCurrency(candidate.threshold)}
					</Typography>
					{/* Qualitative confidence first; the raw percent is secondary. */}
					<Chip
						size="small"
						variant="outlined"
						color={tier.color}
						icon={tier.icon}
						label={`${tier.label} · ${formatPercentValue(candidate.confidence)}`}
					/>
				</Box>
			</Box>

			{/* Current vs recommended at a glance (§10). */}
			{hasComparison && (
				<Box sx={{ mt: 2, p: 1.5, bgcolor: "action.hover", borderRadius: 1 }}>
					<Stack
						direction={{ xs: "column", sm: "row" }}
						spacing={1}
						useFlexGap
						sx={{
							alignItems: "center",
							justifyContent: "center",
							flexWrap: "wrap",
						}}
					>
						<Box sx={{ textAlign: "center" }}>
							<Typography variant="caption" color="text.secondary">
								{__("Current target", "faracart")}
							</Typography>
							<Typography variant="body1" sx={{ fontWeight: 600 }}>
								{formatCurrency(currentTarget as number)}
							</Typography>
						</Box>
						<ArrowForwardIcon fontSize="small" color="action" />
						<Box sx={{ textAlign: "center" }}>
							<Typography variant="caption" color="text.secondary">
								{__("Recommended target", "faracart")}
							</Typography>
							<Typography
								variant="h6"
								component="p"
								sx={{ m: 0, fontWeight: 700, color: "primary.main" }}
							>
								{formatCurrency(candidate.threshold)}
							</Typography>
						</Box>
					</Stack>
				</Box>
			)}

			{/* One short sentence that explains the change in business terms. */}
			<Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>
				{__(
					"Based on your recent order history, this target provides a good balance between increasing order value and keeping the mission achievable.",
					"faracart",
				)}
			</Typography>

			{/* Expected impact + profit — the outcome, not the math. */}
			<Box
				sx={{
					display: "grid",
					gridTemplateColumns: { xs: "repeat(2, 1fr)" },
					gap: 2,
					mt: 2.5,
				}}
			>
				<Box>
					<Typography variant="caption" color="text.secondary">
						{__("Expected impact", "faracart")}
					</Typography>
					<Typography variant="h6" component="p" sx={{ m: 0 }}>
						+{formatNumber(impactLow)}% – +{formatNumber(impactHigh)}%
					</Typography>
					<Typography variant="caption" color="text.secondary">
						{__("average basket value", "faracart")}
					</Typography>
				</Box>
				<Box>
					<Typography variant="caption" color="text.secondary">
						{__("Expected profit", "faracart")}
					</Typography>
					<Typography variant="h6" component="p" sx={{ m: 0 }}>
						{profitAvailable
							? formatCurrency(candidate.expected_profit as number)
							: __("Not available", "faracart")}
					</Typography>
					{!profitAvailable && (
						<Typography variant="caption" color="text.secondary">
							{__(
								"Add product cost data to estimate profitability.",
								"faracart",
							)}
						</Typography>
					)}
				</Box>
			</Box>

			{/* Reachability in plain language (§7) — never the internal definition. */}
			<Box sx={{ display: "flex", alignItems: "flex-start", gap: 1, mt: 2 }}>
				<InfoOutlinedIcon fontSize="small" color="action" sx={{ mt: 0.25 }} />
				<Typography variant="body2" color="text.secondary">
					{sprintf(
						/* translators: %d: share of recent orders close to the target (percent, rounded). */
						__(
							"About %d%% of recent orders are close to this target.",
							"faracart",
						),
						reachPct,
					)}
				</Typography>
			</Box>

			{/* Action — the manager acts here; the detail lives behind collapses. */}
			<Stack sx={{ mt: 2.5 }}>
				<Button
					variant="contained"
					size="large"
					startIcon={<CheckCircleOutlineOutlinedIcon />}
					disabled={missionId < 1}
					onClick={() => onApply(candidate)}
				>
					{__("Apply recommendation", "faracart")}
				</Button>
			</Stack>

			<Divider sx={{ my: 2 }} />

			{/* Why this recommendation? — max 4 concise business reasons. */}
			{simpleReasons.length > 0 && (
				<>
					<Button
						size="small"
						startIcon={reasonsOpen ? <ExpandLessIcon /> : <ExpandMoreIcon />}
						onClick={onToggleReasons}
						aria-expanded={reasonsOpen}
						sx={{ textTransform: "none", color: "text.primary" }}
					>
						{__("Why this recommendation?", "faracart")}
					</Button>
					<Collapse in={reasonsOpen} timeout="auto" unmountOnExit>
						<Stack spacing={0.5} sx={{ mt: 1 }}>
							{simpleReasons.map((reason, index) => (
								<Typography
									key={`${reason}-${index}`}
									variant="body2"
									color="text.secondary"
								>
									• {reason}
								</Typography>
							))}
						</Stack>
					</Collapse>
				</>
			)}

			{/* Advanced analysis — technical factors, collapsed by default. */}
			<Button
				size="small"
				startIcon={advancedOpen ? <ExpandLessIcon /> : <ExpandMoreIcon />}
				onClick={onToggleAdvanced}
				aria-expanded={advancedOpen}
				sx={{ textTransform: "none", color: "text.primary", mt: 1 }}
			>
				{__("Advanced analysis", "faracart")}
			</Button>
			<Collapse in={advancedOpen} timeout="auto" unmountOnExit>
				<Box sx={{ mt: 1.5, pt: 1.5, borderTop: 1, borderColor: "divider" }}>
					<CurrentMissionBlock history={missionHistory} />
					<AdvancedDetails candidate={candidate} />
				</Box>
			</Collapse>
		</Paper>
	);
}

/** The analyzed store data (§33 keeps the context behind the "why"). */
function AnalyzedData({ payload }: { payload: MissionRecommendationsPayload }) {
	const data = payload.data;

	if (!data) {
		return null;
	}

	return (
		<Paper variant="outlined" sx={{ p: 2 }}>
			<Stack direction="row" spacing={1} sx={{ alignItems: "center", mb: 1.5 }}>
				<InfoOutlinedIcon fontSize="small" color="action" />
				<Typography variant="h6" component="h3" sx={{ mb: 0 }}>
					{__("Analyzed store data", "faracart")}
				</Typography>
			</Stack>
			<Box
				sx={{
					display: "grid",
					gridTemplateColumns: { xs: "repeat(2, 1fr)", md: "repeat(4, 1fr)" },
					gap: 2,
				}}
			>
				<Stack spacing={0.5}>
					<Typography variant="caption" color="text.secondary">
						{__("Average order value", "faracart")}
					</Typography>
					<Typography variant="body1" sx={{ fontWeight: 600 }}>
						{formatCurrency(data.aov)}
					</Typography>
				</Stack>
				<Stack spacing={0.5}>
					<Typography variant="caption" color="text.secondary">
						{__("Median order value", "faracart")}
					</Typography>
					<Typography variant="body1" sx={{ fontWeight: 600 }}>
						{formatCurrency(data.median)}
					</Typography>
				</Stack>
				<Stack spacing={0.5}>
					<Typography variant="caption" color="text.secondary">
						{__("Orders analyzed", "faracart")}
					</Typography>
					<Typography variant="body1" sx={{ fontWeight: 600 }}>
						{formatNumber(payload.orders)}
					</Typography>
				</Stack>
				<Stack spacing={0.5}>
					<Typography variant="caption" color="text.secondary">
						{__("Window", "faracart")}
					</Typography>
					<Typography variant="body1" sx={{ fontWeight: 600 }}>
						{sprintf(
							/* translators: 1: days. */
							__("%d days", "faracart"),
							payload.window_days,
						)}
					</Typography>
				</Stack>
				{data.shipping.available && (
					<Stack spacing={0.5}>
						<Typography variant="caption" color="text.secondary">
							{__("Avg. shipping", "faracart")}
						</Typography>
						<Typography variant="body1" sx={{ fontWeight: 600 }}>
							{formatCurrency(data.shipping.average_shipping ?? 0)}
						</Typography>
					</Stack>
				)}
				{data.margin && data.margin.available && (
					<Stack spacing={0.5}>
						<Typography variant="caption" color="text.secondary">
							{__("Avg. margin", "faracart")}
						</Typography>
						<Typography variant="body1" sx={{ fontWeight: 600 }}>
							{/* average_margin_pct is a 0–1 rate; the formatter renders
                  null as "—" (never a fabricated 0%). */}
							{formatPercent(data.margin.average_margin_pct)}
						</Typography>
					</Stack>
				)}
				<Stack spacing={0.5}>
					<Typography variant="caption" color="text.secondary">
						{__("Data sufficiency", "faracart")}
					</Typography>
					<Typography variant="body1" sx={{ fontWeight: 600 }}>
						{sufficiencyLabel(payload.status)}
					</Typography>
				</Stack>
			</Box>

			{/* Order distribution — the engine sends an array of buckets with a
          0–1 `share` rate each; render the bucket's translated label and
          the share as a real percentage (never NaN on a 0-denominator). */}
			<Box sx={{ mt: 2 }}>
				<Typography variant="caption" color="text.secondary">
					{__("Order value distribution (share of orders)", "faracart")}
				</Typography>
				<Stack spacing={0.75} sx={{ mt: 1 }}>
					{data.distribution.map((bucket) => (
						<Box key={bucket.label}>
							<Box
								sx={{
									display: "flex",
									justifyContent: "space-between",
									mb: 0.25,
								}}
							>
								<Typography variant="caption">{bucket.label}</Typography>
								<Typography variant="caption" color="text.secondary">
									{formatPercent(bucket.share)}
								</Typography>
							</Box>
							<LinearProgress
								variant="determinate"
								value={Math.min(100, Math.max(0, bucket.share * 100))}
								sx={{ height: 5, borderRadius: 3 }}
							/>
						</Box>
					))}
				</Stack>
			</Box>
		</Paper>
	);
}

/**
 * Recommendations (engine — UPSELL_REFACTOR §4/§5/§8;
 * UICHANGES.md §40 label).
 *
 * The admin-facing surface that answers "what Mission configuration should
 * I use?" — the `GET /faracart/v1/revenue/mission-recommendations` payload:
 * analyzed store data plus the single best recommendation. The backend
 * engine generates and ranks every eligible candidate deterministically
 * (score desc, ties → lower threshold) and returns the best one as
 * `recommendation`; this page renders ONLY that one — never a list of
 * competing candidates (UICHANGES.md Best-Recommendation UX). It
 * recommends Mission targets and reward economics only — never products
 * (product recommendations belong to Upsells, §11/§59). The card is
 * manager-facing, not a debugging screen: recommended target first, then
 * current → recommended, expected impact, profit, a qualitative confidence
 * label and reach in plain language — the raw scoring factors stay behind
 * the collapsed "Advanced analysis" expander, and up to four plain reasons
 * live behind "Why this recommendation?". An unavailable expected profit
 * explains how to enable it (§24). Applying is always an explicit admin
 * action (ConfirmDialog → the dedicated apply endpoint, which changes only
 * the mission target and records the feedback-loop event) — the engine
 * itself never modifies a mission (§10/§41).
 *
 * Mission selection is REQUIRED: the page opens with no mission selected and
 * shows an instruction state (the API is not called, no fake loading),
 * the admin picks exactly one mission, and the analysis runs only for that
 * mission (`mission_id` is required by the endpoint and echoed back for
 * ownership validation). There is no "all missions" mode and no reward-type
 * filter — reward type stays part of each mission's data model, it is just
 * never an independent page-level filter. Switching missions clears the
 * previous mission's card before the new one loads, so a stale
 * recommendation can never survive a mission change.
 */
export default function Recommendations() {
	const queryClient = useQueryClient();
	const { notify } = useSnackbar();

	// Mission selection is REQUIRED (0 = no mission selected): the page never
	// analyzes an "all missions" context and never picks a mission automatically.
	const [missionId, setMissionId] = useState<number>(0);
	const [applyTarget, setApplyTarget] =
		useState<RecommendationCandidate | null>(null);
	const [showReasons, setShowReasons] = useState<boolean>(false);
	const [showAdvanced, setShowAdvanced] = useState<boolean>(false);

	// The store's missions (same query key RevenueToolbar uses, so it is a
	// shared cache): validates that the selected mission still exists and
	// supplies its name for the UI.
	const missionsQuery = useQuery({
		queryKey: ["missions", "revenue-filter-options"],
		queryFn: () => fetchMissions({ per_page: 100 }),
	});

	const selectedMission =
		(missionsQuery.data?.items ?? []).find(
			(mission) => mission.id === missionId,
		) ?? null;

	// A selected mission id that no longer exists (deleted/archived) is
	// invalid — never show recommendations (or a fake loading state) for it.
	const missionMissing =
		missionId > 0 && missionsQuery.isSuccess && selectedMission === null;

	const handleMissionChange = (nextMissionId: number) => {
		setMissionId(nextMissionId);
		// Clear every mission-scoped UI state so a previous mission's card,
		// expanders or apply dialog can never linger while the new mission loads.
		setShowReasons(false);
		setShowAdvanced(false);
		setApplyTarget(null);
	};

	// Recommendations always analyze a stable 90-day window — the date-range
	// filter is not forwarded to the engine because short windows (last7 etc.)
	// fall below the minimum-order threshold and produce no recommendation.
	// The filter is hidden from the toolbar (showDateRange={false}) so an
	// inert control is never shown.
	const query = useQuery({
		queryKey: ["revenue", "recommendations", { missionId }],
		queryFn: () =>
			fetchMissionRecommendations({
				mission_id: missionId,
				window_days: 90,
			}),
		// No recommendations without a selected mission: the API is not called
		// (and no fake loading state shown) until a valid mission is chosen.
		enabled: missionId > 0 && !missionMissing,
	});

	// Product-cost coverage (UPSELL_REFACTOR §24/§25/§26): when the store
	// lacks margin data, explain exactly how much of the catalog is covered
	// and where to add costs — never a guessed margin.
	const coverageQuery = useQuery({
		queryKey: ["revenue", "cost-coverage"],
		queryFn: () => fetchCostCoverage(),
		enabled:
			query.data?.available === true &&
			query.data?.data?.margin?.available === false,
	});

	const payload = query.data;
	const top = payload?.recommendation;
	const missionHistory = payload?.data?.mission_history ?? null;
	const coverage: CostCoveragePayload | undefined = coverageQuery.data;

	// Ownership guard: a payload must belong to the selected mission — if the
	// response's mission_id does not match, the recommendation is invalid and
	// is never rendered.
	const ownsMission = payload ? payload.mission_id === missionId : false;

	const applyMutation = useMutation({
		mutationFn: async (target: number) => {
			if (missionId < 1) {
				throw new Error(
					__("Select a mission to apply the recommendation to.", "faracart"),
				);
			}

			await applyMissionRecommendation(missionId, target);
		},
		onSuccess: () => {
			notify(__("Mission target updated.", "faracart"));
			setApplyTarget(null);
			queryClient.invalidateQueries({ queryKey: ["missions"] });
			queryClient.invalidateQueries({ queryKey: ["revenue"] });
		},
		onError: (error: Error) => {
			notify(error.message, "error");
			setApplyTarget(null);
		},
	});

	const handleApply = (candidate: RecommendationCandidate) => {
		setApplyTarget(candidate);
	};

	return (
		<PageContainer
			title={__("Recommendations", "faracart")}
			description={__(
				"Improve your Missions using store performance data — which target and reward configuration to use, and why.",
				"faracart",
			)}
		>
			{/* §39: the one-line distinction that removes the conceptual confusion. */}
			<Alert
				severity="info"
				variant="outlined"
				icon={<TipsAndUpdatesIcon fontSize="small" />}
			>
				{__(
					"Recommendations helps you choose better Mission targets and reward configurations. It does not recommend products — product recommendations for customers live under Upsells.",
					"faracart",
				)}
			</Alert>

			<RevenueToolbar
				missionId={missionId}
				onMissionChange={handleMissionChange}
				missionRequired
				showDateRange={true}
			/>

			{missionId < 1 ? (
				<EmptyState
					icon={<TipsAndUpdatesIcon fontSize="large" />}
					title={__("Select a mission", "faracart")}
					description={__(
						"To see the best optimization recommendation for a mission, first choose one of your store missions.",
						"faracart",
					)}
				/>
			) : missionMissing ? (
				<EmptyState
					icon={<TipsAndUpdatesIcon fontSize="large" />}
					title={__("The selected mission could not be found", "faracart")}
					description={__("Please select another mission.", "faracart")}
				/>
			) : query.isError ? (
				<Alert severity="error" variant="outlined">
					{query.error instanceof Error
						? query.error.message
						: __("Could not load recommendations.", "faracart")}
				</Alert>
			) : query.isLoading ? (
				<Stack spacing={2}>
					<Skeleton variant="rounded" height={120} />
					<Skeleton variant="rounded" height={420} />
				</Stack>
			) : !payload ? null : !payload.available || !ownsMission ? (
				<EmptyState
					icon={<TipsAndUpdatesIcon fontSize="large" />}
					title={__("No recommendation available", "faracart")}
					description={
						payload.insufficient_reason ??
						__("Not enough data for a reliable recommendation.", "faracart")
					}
				/>
			) : payload.data ? (
				<>
					{/* Top recommendation — business outcome first (§33). */}
					{top && (
						<TopRecommendationCard
							candidate={top}
							missionId={missionId}
							missionName={selectedMission?.name ?? null}
							missionHistory={missionHistory}
							reasonsOpen={showReasons}
							advancedOpen={showAdvanced}
							onToggleReasons={() => setShowReasons((current) => !current)}
							onToggleAdvanced={() => setShowAdvanced((current) => !current)}
							onApply={handleApply}
						/>
					)}

					{/* §24/§25/§26: margin data missing → show the coverage and the
              path to enable profit estimates instead of a guessed number. */}
					{payload.data.margin &&
						!payload.data.margin.available &&
						coverage && (
							<Alert
								severity="warning"
								variant="outlined"
								action={
									<Button
										size="small"
										color="inherit"
										href={`${getBootData().adminUrl}edit.php?post_type=product`}
										target="_blank"
										rel="noreferrer"
									>
										{__("Manage product costs", "faracart")}
									</Button>
								}
							>
								{coverage.product_coverage.coverage_pct !== null
									? sprintf(
											/* translators: 1: products with cost, 2: total products, 3: coverage percentage. */
											__(
												"Product Cost Coverage: %1$s / %2$s products (%3$s). Profit estimates need product cost data — add it to enable Mission economics.",
												"faracart",
											),
											formatNumber(
												coverage.product_coverage.products_with_cost,
											),
											formatNumber(coverage.product_coverage.total_products),
											// coverage_pct is already a 0–100 percentage point value
											// (never divide by 100 and print as a 0–1 rate).
											formatPercentValue(
												coverage.product_coverage.coverage_pct,
											),
										)
									: __(
											"Profit estimates need product cost data. Add product costs on your products to enable Mission economics.",
											"faracart",
										)}
							</Alert>
						)}

					{/* Analyzed store data — the context behind the "why". */}
					<AnalyzedData payload={payload} />
				</>
			) : null}

			<ConfirmDialog
				open={applyTarget !== null}
				title={__("Apply recommendation?", "faracart")}
				description={
					applyTarget ? (
						<>
							{missionHistory ? (
								<>
									{sprintf(
										/* translators: 1: current target. */
										__("Current target: %1$s", "faracart"),
										formatCurrency(missionHistory.current_target),
									)}{" "}
									{sprintf(
										/* translators: 1: recommended target. */
										__("→ %1$s", "faracart"),
										formatCurrency(applyTarget.threshold),
									)}
								</>
							) : (
								sprintf(
									/* translators: 1: threshold. */
									__("Set the mission target to %s?", "faracart"),
									formatCurrency(applyTarget.threshold),
								)
							)}{" "}
							{__(
								"This changes a production mission — the action is not reversible from here.",
								"faracart",
							)}
						</>
					) : undefined
				}
				confirmLabel={__("Apply", "faracart")}
				busy={applyMutation.isPending}
				onConfirm={() => {
					if (applyTarget) {
						applyMutation.mutate(applyTarget.threshold);
					}
				}}
				onCancel={() => setApplyTarget(null)}
			/>
		</PageContainer>
	);
}
