import CloseIcon from "@mui/icons-material/Close";
import FlagIcon from "@mui/icons-material/Flag";
import InfoOutlinedIcon from "@mui/icons-material/InfoOutlined";
import InsightsIcon from "@mui/icons-material/Insights";
import ToggleOffIcon from "@mui/icons-material/ToggleOff";
import ToggleOnIcon from "@mui/icons-material/ToggleOn";
import Alert from "@mui/material/Alert";
import Box from "@mui/material/Box";
import Card from "@mui/material/Card";
import CardContent from "@mui/material/CardContent";
import IconButton from "@mui/material/IconButton";
import Paper from "@mui/material/Paper";
import Skeleton from "@mui/material/Skeleton";
import Typography from "@mui/material/Typography";
import { useQuery } from "@tanstack/react-query";
import { __, sprintf } from "@wordpress/i18n";
import { type ReactNode, useState } from "react";
import { fetchGoals } from "../api/goals";
import { getBootData } from "../boot";
import EmptyState from "../components/EmptyState";
import PageContainer from "../components/PageContainer";

interface StatCardProps {
	label: string;
	value: string;
	icon: ReactNode;
	loading: boolean;
}

/** Small KPI card with a skeleton while loading. */
function StatCard({ label, value, icon, loading }: StatCardProps) {
	return (
		<Card variant="outlined">
			<CardContent>
				<Box
					sx={{
						display: "flex",
						alignItems: "center",
						gap: 1,
						mb: 1,
						color: "text.secondary",
					}}
				>
					{icon}
					<Typography variant="body2" color="text.secondary">
						{label}
					</Typography>
				</Box>
				{loading ? (
					<Skeleton variant="text" width={96} height={40} />
				) : (
					<Typography variant="h4" component="p" sx={{ m: 0 }}>
						{value}
					</Typography>
				)}
			</CardContent>
		</Card>
	);
}

/**
 * Dashboard (P08-T03): the admin shell's landing page.
 *
 * Shows a live summary of the goals the plugin is running (read from the
 * Phase 7 REST API) plus the current system state — the full analytics
 * dashboard is built by Phases 16–17, so this page stays a summary until
 * then. Loading skeletons, an error alert and an empty state cover every
 * query state.
 */
export default function Dashboard() {
	const boot = getBootData();

	const goalsQuery = useQuery({
		queryKey: ["goals", "summary"],
		queryFn: () => fetchGoals({ per_page: 100 }),
	});

	const goals = goalsQuery.data?.items ?? [];
	const active = goals.filter((goal) => goal.status === "active").length;
	const inactive = goals.length - active;

	return (
		<PageContainer
			title={__("Dashboard", "goalcart")}
			description={__(
				"An overview of your cart goals and the plugin status. Full analytics arrive in a later phase.",
				"goalcart",
			)}
		>
			{goalsQuery.isError && (
				<Alert severity="error" variant="outlined">
					{goalsQuery.error instanceof Error
						? goalsQuery.error.message
						: __("Could not load the goal summary.", "goalcart")}
				</Alert>
			)}

			{/* UPSELL_REFACTOR §40 — first-use store-owner education, dismissible
          and never shown on every page. */}
			<HowGoalCartWorks />

			{!goalsQuery.isLoading && !goalsQuery.isError && goals.length === 0 ? (
				<EmptyState
					icon={<FlagIcon fontSize="large" />}
					title={__("No goals yet", "goalcart")}
					description={__(
						"Create your first goal to start increasing the average order value — progress bars and rewards appear on the storefront once a goal is active.",
						"goalcart",
					)}
				/>
			) : (
				<Box
					sx={{
						display: "grid",
						gridTemplateColumns: {
							xs: "repeat(2, 1fr)",
							sm: "repeat(3, 1fr)",
							xl: "repeat(5, 1fr)",
						},
						gap: 2,
					}}
				>
					<StatCard
						label={__("Total goals", "goalcart")}
						value={String(goalsQuery.data?.total ?? "")}
						icon={<FlagIcon fontSize="small" />}
						loading={goalsQuery.isLoading}
					/>
					<StatCard
						label={__("Active", "goalcart")}
						value={String(active)}
						icon={<ToggleOnIcon fontSize="small" />}
						loading={goalsQuery.isLoading}
					/>
					<StatCard
						label={__("Inactive", "goalcart")}
						value={String(inactive)}
						icon={<ToggleOffIcon fontSize="small" />}
						loading={goalsQuery.isLoading}
					/>
					<StatCard
						label={__("Currency", "goalcart")}
						value={boot.currency || "—"}
						icon={<InsightsIcon fontSize="small" />}
						loading={goalsQuery.isLoading}
					/>
					<StatCard
						label={__("Plugin version", "goalcart")}
						value={`v${boot.version}`}
						icon={<InfoOutlinedIcon fontSize="small" />}
						loading={false}
					/>
				</Box>
			)}

			<Paper variant="outlined" sx={{ p: { xs: 2.5, md: 3 } }}>
				<Typography variant="h6" component="h3" gutterBottom>
					{__("What is Goal Cart?", "goalcart")}
				</Typography>
				<Typography variant="body2" color="text.secondary">
					{sprintf(
						/* translators: %s: site name. */
						__(
							"%s uses cart goals — like “spend %s more for free shipping” — to encourage bigger carts. Goals, rewards and progress bars are configured from the Goals page.",
							"goalcart",
						),
						boot.siteName,
						boot.currency,
					)}
				</Typography>
			</Paper>
		</PageContainer>
	);
}

/** localStorage key remembering the education card was dismissed. */
const EDUCATION_DISMISSED_KEY = "goalcart:educationDismissed";

/**
 * "How Goal Cart works" education card (UPSELL_REFACTOR §40).
 *
 * One-time, dismissible introduction to the three-part product model
 * (UICHANGES.md §4/§42): Sales Performance (measure purchases, sales and
 * profit) → Recommendations (choose better Goals) → Upsells (help
 * customers reach them). Only shown on the Dashboard and only until
 * dismissed.
 */
function HowGoalCartWorks() {
	const [dismissed, setDismissed] = useState<boolean>(() => {
		if (typeof window !== "undefined") {
			try {
				return window.localStorage.getItem(EDUCATION_DISMISSED_KEY) === "1";
			} catch {
				// Private mode / quota — show the card.
			}
		}
		return false;
	});

	if (dismissed) {
		return null;
	}

	const dismiss = () => {
		try {
			window.localStorage.setItem(EDUCATION_DISMISSED_KEY, "1");
		} catch {
			// Best-effort persistence.
		}
		setDismissed(true);
	};

	const steps: Array<{ title: string; text: string }> = [
		{
			title: __("1. Recommendations", "goalcart"),
			text: __(
				"Find better Goal targets and reward configurations using your store data.",
				"goalcart",
			),
		},
		{
			title: __("2. Upsells", "goalcart"),
			text: __(
				"Recommend products that help customers reach those Goals.",
				"goalcart",
			),
		},
		{
			title: __("3. Sales Performance", "goalcart"),
			text: __(
				"Measure purchases, sales and estimated profit — then optimize again.",
				"goalcart",
			),
		},
	];

	return (
		<Paper
			variant="outlined"
			sx={{ p: { xs: 2, md: 2.5 }, position: "relative" }}
		>
			<IconButton
				size="small"
				onClick={dismiss}
				aria-label={__("Dismiss how Goal Cart works", "goalcart")}
				sx={{
					position: "absolute",
					top: 8,
					insetInlineEnd: 8,
					color: "text.secondary",
				}}
			>
				<CloseIcon fontSize="small" />
			</IconButton>
			<Typography variant="h6" component="h3" gutterBottom>
				{__("How Goal Cart works", "goalcart")}
			</Typography>
			<Box
				sx={{
					display: "grid",
					gridTemplateColumns: { xs: "repeat(1, 1fr)", md: "repeat(3, 1fr)" },
					gap: 2,
				}}
			>
				{steps.map((step) => (
					<Box key={step.title}>
						<Typography variant="body2" sx={{ fontWeight: 600 }}>
							{step.title}
						</Typography>
						<Typography variant="body2" color="text.secondary">
							{step.text}
						</Typography>
					</Box>
				))}
			</Box>
		</Paper>
	);
}
