import BuildIcon from "@mui/icons-material/Build";
import CalculateIcon from "@mui/icons-material/Calculate";
import SmartButtonIcon from "@mui/icons-material/SmartButton";
import SpeedIcon from "@mui/icons-material/Speed";
import StorefrontIcon from "@mui/icons-material/Storefront";
import TuneIcon from "@mui/icons-material/Tune";
import Alert from "@mui/material/Alert";
import Box from "@mui/material/Box";
import Button from "@mui/material/Button";
import Checkbox from "@mui/material/Checkbox";
import Chip from "@mui/material/Chip";
import FormControlLabel from "@mui/material/FormControlLabel";
import Grid from "@mui/material/Grid";
import MenuItem from "@mui/material/MenuItem";
import Paper from "@mui/material/Paper";
import Skeleton from "@mui/material/Skeleton";
import Stack from "@mui/material/Stack";
import Switch from "@mui/material/Switch";
import Tab from "@mui/material/Tab";
import Tabs from "@mui/material/Tabs";
import TextField from "@mui/material/TextField";
import Typography from "@mui/material/Typography";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { __ } from "@wordpress/i18n";
import { useRef, useState } from "react";
import {
	type Control,
	Controller,
	type Path,
	useForm,
	useWatch,
} from "react-hook-form";

import { fetchSettingsEnvelope, saveSettings } from "../api/settings";

import {
	FLOATING_PRESETS,
	type FloatingDevice,
	type FloatingDraft,
	type FloatingPosition,
	type FloatingPreset,
	resolveFloatingPosition,
} from "../components/floating/floating";
import SectionCard from "../components/mission-builder/SectionCard";
import { useSnackbar } from "../components/notifications/SnackbarProvider";
import PageContainer from "../components/PageContainer";
import { useStickyBarActions } from "../providers/ActionBarProvider";
import { useFullscreen } from "../providers/FullscreenProvider";
import type { FaraCartSettings, FrontendLocation } from "../types";

/* ------------------------------------------------------------------ *
 * Field helpers (mirror the reference plugin's Settings page)
 * ------------------------------------------------------------------ */

/** A switch-backed boolean setting. */
function BooleanField({
	control,
	name,
	label,
	description,
}: {
	control: Control<FaraCartSettings>;
	name: Path<FaraCartSettings>;
	label: string;
	description?: string;
}) {
	return (
		<Controller
			control={control}
			name={name}
			render={({ field }) => (
				<FormControlLabel
					control={
						<Switch
							checked={Boolean(field.value)}
							onChange={(event) => field.onChange(event.target.checked)}
						/>
					}
					label={
						<Box>
							<Typography variant="body2" sx={{ fontWeight: 600 }}>
								{label}
							</Typography>
							{description && (
								<Typography variant="caption" color="text.secondary">
									{description}
								</Typography>
							)}
						</Box>
					}
					sx={{ m: 0, width: "100%" }}
				/>
			)}
		/>
	);
}

/** A select of string options. */
function SelectField({
	control,
	name,
	label,
	description,
	options,
}: {
	control: Control<FaraCartSettings>;
	name: Path<FaraCartSettings>;
	label: string;
	description?: string;
	options: Array<{ value: string; label: string }>;
}) {
	return (
		<Controller
			control={control}
			name={name}
			render={({ field }) => (
				<TextField
					select
					size="small"
					fullWidth
					label={label}
					value={String(field.value)}
					onChange={(event) => field.onChange(event.target.value)}
					helperText={description}
					sx={{ maxWidth: 360 }}
				>
					{options.map((option) => (
						<MenuItem key={option.value} value={option.value}>
							{option.label}
						</MenuItem>
					))}
				</TextField>
			)}
		/>
	);
}

/** The storefront widget locations as a checkbox group. */
const LOCATION_OPTIONS: Array<{ value: FrontendLocation; label: string }> = [
	{ value: "cart", label: __("Cart page", "faracart") },
	{ value: "mini-cart", label: __("Mini cart", "faracart") },
	{ value: "checkout", label: __("Checkout", "faracart") },
	{ value: "shop", label: __("Shop / archives", "faracart") },
	{ value: "product", label: __("Product pages", "faracart") },
];

function LocationField({
	control,
	name,
	description,
}: {
	control: Control<FaraCartSettings>;
	name: Path<FaraCartSettings>;
	description: string;
}) {
	return (
		<Controller
			control={control}
			name={name}
			render={({ field }) => {
				const selected: FrontendLocation[] = Array.isArray(field.value)
					? field.value
					: [];

				return (
					<Box>
						<Typography variant="body2" color="text.secondary" sx={{ mb: 0.5 }}>
							{description}
						</Typography>
						<Stack
							direction="row"
							useFlexGap
							sx={{
								flexWrap: "wrap",
								gap: 0,
								"& .MuiFormControlLabel-root": { mr: 0, minWidth: 190 },
							}}
						>
							{LOCATION_OPTIONS.map((option) => {
								const checked = selected.includes(option.value);

								return (
									<FormControlLabel
										key={option.value}
										control={
											<Checkbox
												size="small"
												checked={checked}
												onChange={(event) => {
													const next = new Set(selected);

													if (event.target.checked) {
														next.add(option.value);
													} else {
														next.delete(option.value);
													}

													field.onChange(Array.from(next));
												}}
											/>
										}
										label={option.label}
									/>
								);
							})}
						</Stack>
					</Box>
				);
			}}
		/>
	);
}

/** One row of the developer-hooks reference (Advanced tab). */
function HookRow({
	type,
	hook,
	description,
}: {
	type: string;
	hook: string;
	description: string;
}) {
	return (
		<Box
			sx={{
				display: "flex",
				alignItems: "flex-start",
				gap: 1.25,
				py: 0.75,
				borderBottom: "1px solid",
				borderColor: "divider",
				"&:last-child": { borderBottom: 0 },
			}}
		>
			<Chip
				size="small"
				variant="outlined"
				color={type === "action" ? "primary" : "default"}
				label={type}
			/>
			<Box sx={{ minWidth: 0 }}>
				<Typography
					variant="body2"
					sx={{
						fontWeight: 600,
						fontFamily: "monospace",
						wordBreak: "break-all",
					}}
				>
					{hook}
				</Typography>
				<Typography variant="caption" color="text.secondary">
					{description}
				</Typography>
			</Box>
		</Box>
	);
}

/**
 * One floating-widget position card (desktop or mobile): the position
 * preset (the only position control) plus the pixel offsets that
 * fine-tune it. The drawer always opens toward the screen center from
 * the chosen preset — there is no separate direction setting.
 */
function FloatingPositionCard({
	device,
	control,
	position,
	onApplyPreset,
}: {
	device: FloatingDevice;
	control: Control<FaraCartSettings>;
	position: FloatingPosition;
	onApplyPreset: (preset: FloatingPreset) => void;
}) {
	const base = device === "desktop" ? "floating_desktop" : "floating_mobile";
	const preset = position.preset;

	return (
		<Paper variant="outlined" sx={{ p: 2.5, height: "100%" }}>
			<Stack spacing={2}>
				<Box>
					<Typography variant="subtitle2" sx={{ fontWeight: 700 }}>
						{device === "desktop"
							? __("Desktop", "faracart")
							: __("Mobile", "faracart")}
					</Typography>
					<Typography variant="caption" color="text.secondary">
						{device === "desktop"
							? __("The button position on desktop screens.", "faracart")
							: __(
									"The button position on mobile screens — handy when the button must clear mobile navigation or sticky cart buttons.",
									"faracart",
								)}
					</Typography>
				</Box>

				<TextField
					select
					size="small"
					fullWidth
					label={__("Position preset", "faracart")}
					value={preset}
					onChange={(event) => {
						const next = FLOATING_PRESETS.find(
							(candidate) => candidate.value === event.target.value,
						);

						if (next) {
							onApplyPreset(next);
						}
					}}
					helperText={__(
						"The button corner/edge — the drawer always opens toward the screen center from it. The offsets below fine-tune the exact spot.",
						"faracart",
					)}
					sx={{ maxWidth: 360 }}
				>
					{FLOATING_PRESETS.map((candidate) => (
						<MenuItem key={candidate.value} value={candidate.value}>
							{candidate.label}
						</MenuItem>
					))}
				</TextField>

				<Controller
					control={control}
					name={`${base}.offset_x` as Path<FaraCartSettings>}
					render={({ field }) => (
						<TextField
							{...field}
							type="number"
							size="small"
							fullWidth
							label={__("Horizontal offset (px)", "faracart")}
							helperText={__(
								"Distance from the chosen side (0–200).",
								"faracart",
							)}
							value={Number(field.value) || 0}
							onChange={(event) =>
								field.onChange(Number(event.target.value) || 0)
							}
							slotProps={{ htmlInput: { min: 0, max: 200 } }}
							sx={{ maxWidth: 360 }}
						/>
					)}
				/>
				<Controller
					control={control}
					name={`${base}.offset_y` as Path<FaraCartSettings>}
					render={({ field }) => (
						<TextField
							{...field}
							type="number"
							size="small"
							fullWidth
							label={__("Vertical offset (px)", "faracart")}
							helperText={__(
								"Distance from the chosen edge — or from the midline when the preset is centered (0–200).",
								"faracart",
							)}
							value={Number(field.value) || 0}
							onChange={(event) =>
								field.onChange(Number(event.target.value) || 0)
							}
							slotProps={{ htmlInput: { min: 0, max: 200 } }}
							sx={{ maxWidth: 360 }}
						/>
					)}
				/>
			</Stack>
		</Paper>
	);
}

/* ------------------------------------------------------------------ *
 * Settings page (full surface in six tabs)
 * ------------------------------------------------------------------ */

/**
 * Settings (the full configuration surface).
 *
 * Six tabs over a single react-hook-form instance: General, Frontend,
 * Mission Calculation, Performance, Advanced and Floating. Every control
 * maps 1:1 to a persisted setting key validated server-side by the
 * REST schema; saving updates the query data so the form
 * re-syncs, and the full-screen toggle still previews live through
 * FullscreenProvider.
 *
 * Mirrors the reference plugin's tabbed Settings page.
 */
export default function Settings() {
	const queryClient = useQueryClient();
	const { notify } = useSnackbar();
	const { setFullscreen } = useFullscreen();

	const [tab, setTab] = useState(0);

	// The save button lives in the sticky bottom bar, outside the <form>;
	// submitting through the form element keeps react-hook-form's
	// validation + submit flow (the button calls requestSubmit()).
	const formRef = useRef<HTMLFormElement>(null);

	const settingsQuery = useQuery({
		queryKey: ["settings"],
		queryFn: fetchSettingsEnvelope,
	});

	const data = settingsQuery.data?.data;
	const meta = settingsQuery.data?.meta ?? {};
	const hooks = meta.hooks ?? [];

	// `values` keeps the form in sync with the server state as it loads and
	// after saves (the mutation's setQueryData below updates `values`, which
	// resets the form — no manual reset needed). The defaultValues mirror the
	// PHP Settings::defaults() (keep them in sync if those change).
	const { control, handleSubmit, setValue } = useForm<FaraCartSettings>({
		defaultValues: { enabled: true, fullscreen_dashboard: true },
		values: data,
	});

	// Live form values: the Floating tab's position cards read the current
	// draft so a preset/offset change reflects immediately (e.g. the mobile
	// "reuse desktop" toggle) without a page reload.
	const watched = useWatch({ control }) as Partial<FaraCartSettings>;
	const floatingDraft = watched as FloatingDraft;
	const resolvedFloatingPosition = resolveFloatingPosition(floatingDraft);
	const floatingMobileUseDesktop =
		floatingDraft.floating_mobile_use_desktop !== false;

	const saveMutation = useMutation({
		mutationFn: (values: FaraCartSettings) => saveSettings(values),
		onSuccess: (saved) => {
			notify(__("Settings saved.", "faracart"));				// Re-sync the form and refresh the meta (developer hooks reference).
				void queryClient.setQueryData(["settings"], { data: saved, meta });
			void settingsQuery.refetch();

			// Apply the full-screen toggle live (no page reload): the provider
			// toggles the body class that hides/shows the WP admin chrome.
			if (typeof saved.fullscreen_dashboard === "boolean") {
				setFullscreen(saved.fullscreen_dashboard);
			}

		},
		onError: (error: Error) => {
			notify(error.message, "error");
		},
	});

	// Sticky bottom bar: the Save settings button (moved out of the page
	// body into the dashboard's bottom action bar). Hidden while the
	// settings are still loading so it never submits an empty form.
	useStickyBarActions([saveMutation.isPending, Boolean(data)], () =>
		data ? (
			<Button
				type="button"
				variant="contained"
				disabled={saveMutation.isPending}
				onClick={() => formRef.current?.requestSubmit()}
				sx={{ minWidth: 120 }}
			>
				{saveMutation.isPending
					? __("Saving…", "faracart")
					: __("Save settings", "faracart")}
			</Button>
		) : null,
	);

	if (settingsQuery.isLoading) {
		return (
			<PageContainer
				title={__("Settings", "faracart")}
				description={__("Plugin-wide configuration.", "faracart")}
			>
				<Stack spacing={2}>
					<Skeleton variant="rounded" height={120} />
					<Skeleton variant="rounded" height={160} />
				</Stack>
			</PageContainer>
		);
	}

	if (settingsQuery.isError) {
		return (
			<PageContainer
				title={__("Settings", "faracart")}
				description={__("Plugin-wide configuration.", "faracart")}
			>
				<Alert severity="error" variant="outlined">
					{settingsQuery.error instanceof Error
						? settingsQuery.error.message
						: __("Could not load the settings.", "faracart")}
				</Alert>
			</PageContainer>
		);
	}

	return (
		<PageContainer
			title={__("Settings", "faracart")}
			description={__(
				"Plugin-wide configuration. Changes apply immediately.",
				"faracart",
			)}
		>
			<form
				ref={formRef}
				onSubmit={handleSubmit((values) => saveMutation.mutate(values))}
			>
				<Stack spacing={3}>
					<Tabs
						value={tab}
						onChange={(_, next) => setTab(next)}
						variant="scrollable"
						scrollButtons="auto"
						sx={{ borderBottom: 1, borderColor: "divider" }}
					>
						<Tab
							icon={<TuneIcon />}
							iconPosition="start"
							label={__("General", "faracart")}
						/>
						<Tab
							icon={<StorefrontIcon />}
							iconPosition="start"
							label={__("Frontend", "faracart")}
						/>
						<Tab
							icon={<SmartButtonIcon />}
							iconPosition="start"
							label={__("Floating", "faracart")}
						/>
						<Tab
							icon={<CalculateIcon />}
							iconPosition="start"
							label={__("Mission Calculation", "faracart")}
						/>
						<Tab
							icon={<SpeedIcon />}
							iconPosition="start"
							label={__("Performance", "faracart")}
						/>
						<Tab
							icon={<BuildIcon />}
							iconPosition="start"
							label={__("Advanced", "faracart")}
						/>
					</Tabs>

					{tab === 0 && (
						<Stack spacing={2.5}>
							<SectionCard
								title={__("General", "faracart")}
								description={__(
									"Master toggle, display and default behavior.",
									"faracart",
								)}
							>
								<Stack spacing={2}>
									<BooleanField
										control={control}
										name="enabled"
										label={__("Enable FaraCart", "faracart")}
										description={__(
											"Turn the storefront missions, rewards and progress bars on or off.",
											"faracart",
										)}
									/>
									<SelectField
										control={control}
										name="default_mission_behavior"
										label={__("Default mission behavior", "faracart")}
										description={__(
											"How multiple active missions are presented when the shopper has no campaign.",
											"faracart",
										)}
										options={[
											{
												value: "all",
												label: __("Show all missions", "faracart"),
											},
											{
												value: "first",
												label: __("Show the first mission only", "faracart"),
											},
											{
												value: "closest",
												label: __("Show the closest mission only", "faracart"),
											},
										]}
									/>
									<SelectField
										control={control}
										name="conflict_resolution"
										label={__("Conflict resolution", "faracart")}
										description={__(
											"How completed missions grant rewards when several compete: combine them, grant only the highest-priority matching mission, or grant only the best reward. Per-mission exclusive flags and priorities are respected in every mode.",
											"faracart",
										)}
										options={[
											{
												value: "cumulative",
												label: __("Cumulative — all rewards stack", "faracart"),
											},
											{
												value: "first",
												label: __("First matching mission only", "faracart"),
											},
											{
												value: "best",
												label: __("Best reward only", "faracart"),
											},
										]}
									/>
									<SelectField
										control={control}
										name="calculation_mode"
										label={__("Calculation mode", "faracart")}
										description={__(
											"Default money basis for missions that do not set their own.",
											"faracart",
										)}
										options={[
											{
												value: "subtotal",
												label: __("Subtotal (before discounts)", "faracart"),
											},
											{
												value: "discounted_subtotal",
												label: __("Discounted subtotal", "faracart"),
											},
											{
												value: "total",
												label: __(
													"Cart total (incl. tax & shipping)",
													"faracart",
												),
											},
										]}
									/>
									<BooleanField
										control={control}
										name="fullscreen_dashboard"
										label={__("Full-screen dashboard", "faracart")}
										description={__(
											"Hide the WordPress admin chrome and let the dashboard fill the whole browser window.",
											"faracart",
										)}
									/>
								</Stack>
							</SectionCard>
						</Stack>
					)}

					{tab === 1 && (
						<Stack spacing={2.5}>
							<SectionCard
								title={__("Frontend", "faracart")}
								description={__(
									"Where and how the storefront widgets appear.",
									"faracart",
								)}
							>
								<Stack spacing={2}>
									<LocationField
										control={control}
										name="frontend_locations"
										description={__("Display locations", "faracart")}
									/>
									<SelectField
										control={control}
										name="frontend_position"
										label={__("Position", "faracart")}
										options={[
											{ value: "top", label: __("Top", "faracart") },
											{ value: "bottom", label: __("Bottom", "faracart") },
										]}
									/>
									<SelectField
										control={control}
										name="frontend_template"
										label={__("Template", "faracart")}
										description={__(
											"Store-wide progress widget variant.",
											"faracart",
										)}
										options={[
											{
												value: "template-1",
												label: __("Template 1", "faracart"),
											},
											{
												value: "template-2",
												label: __("Template 2", "faracart"),
											},
											{
												value: "template-3",
												label: __("Template 3", "faracart"),
											},
											{
												value: "template-4",
												label: __("Template 4", "faracart"),
											},
											{
												value: "template-5",
												label: __("Template 5", "faracart"),
											},
											{
												value: "template-6",
												label: __("Template 6", "faracart"),
											},
										]}
									/>
									<BooleanField
										control={control}
										name="frontend_animation"
										label={__("Animate progress", "faracart")}
										description={__(
											"Slide the progress-bar fill on updates.",
											"faracart",
										)}
									/>
									<SelectField
										control={control}
										name="frontend_mobile"
										label={__("Mobile behavior", "faracart")}
										description={__(
											"Whether the widgets render on small screens (≤782px).",
											"faracart",
										)}
										options={[
											{
												value: "show",
												label: __("Show on mobile", "faracart"),
											},
											{
												value: "hide",
												label: __("Hide on mobile", "faracart"),
											},
										]}
									/>
									<BooleanField
										control={control}
										name="frontend_countdown"
										label={__("Countdown timer", "faracart")}
										description={__(
											"Show a live countdown to the mission/campaign deadline (Phase 32).",
											"faracart",
										)}
									/>
									<BooleanField
										control={control}
										name="frontend_celebrate"
										label={__("Celebration animation", "faracart")}
										description={__(
											"Play a confetti burst when a mission is reached (Phase 32).",
											"faracart",
										)}
									/>
								</Stack>
							</SectionCard>
						</Stack>
					)}

					{tab === 2 && (
						<Stack spacing={2.5}>
							<SectionCard
								title={__("Floating widget", "faracart")}
								description={__(
									"A floating missions/campaigns button with a progress drawer — always reachable while shopping.",
									"faracart",
								)}
							>
								<Stack spacing={2}>
									<BooleanField
										control={control}
										name="floating_enabled"
										label={__("Enable floating widget", "faracart")}
										description={__(
											"Show the floating button on widget pages whenever the cart has an eligible mission.",
											"faracart",
										)}
									/>
									<Stack
										direction="row"
										useFlexGap
										sx={{ flexWrap: "wrap", gap: 2 }}
									>
										<Box sx={{ minWidth: 280, flex: 1 }}>
											<BooleanField
												control={control}
												name="floating_show_desktop"
												label={__("Show on desktop", "faracart")}
												description={__(
													"Display the button on desktop screens.",
													"faracart",
												)}
											/>
										</Box>
										<Box sx={{ minWidth: 280, flex: 1 }}>
											<BooleanField
												control={control}
												name="floating_show_mobile"
												label={__("Show on mobile", "faracart")}
												description={__(
													"Display the button on mobile screens.",
													"faracart",
												)}
											/>
										</Box>
									</Stack>
								</Stack>
							</SectionCard>

							<SectionCard
								title={__("Position & display", "faracart")}
								description={__(
									"Where the button sits — separately for desktop and mobile. Mobile can reuse the desktop position, or pin its own so it never clashes with mobile navigation or sticky cart buttons. The progress drawer always opens toward the screen center.",
									"faracart",
								)}
							>
								<Stack spacing={2}>
									<BooleanField
										control={control}
										name="floating_mobile_use_desktop"
										label={__("Use the desktop position on mobile", "faracart")}
										description={__(
											"When on, mobile reuses the desktop position and the separate mobile fields are hidden.",
											"faracart",
										)}
									/>
									<Grid container spacing={2}>
										<Grid size={{ xs: 12, md: 6 }}>
											<FloatingPositionCard
												device="desktop"
												control={control}
												position={resolvedFloatingPosition.desktop}
												onApplyPreset={(preset) => {
													setValue("floating_desktop.preset", preset.value);
												}}
											/>
										</Grid>
										<Grid size={{ xs: 12, md: 6 }}>
											{floatingMobileUseDesktop ? (
												<Paper
													variant="outlined"
													sx={{
														p: 2.5,
														height: "100%",
														display: "flex",
														alignItems: "center",
														justifyContent: "center",
														bgcolor: "action.hover",
													}}
												>
													<Typography
														variant="body2"
														color="text.secondary"
														align="center"
													>
														{__(
															"Mobile reuses the desktop position. Turn the toggle above off to configure a separate mobile position.",
															"faracart",
														)}
													</Typography>
												</Paper>
											) : (
												<FloatingPositionCard
													device="mobile"
													control={control}
													position={resolvedFloatingPosition.mobile}
													onApplyPreset={(preset) => {
														setValue("floating_mobile.preset", preset.value);
													}}
												/>
											)}
										</Grid>
									</Grid>
								</Stack>
							</SectionCard>

							<SectionCard
								title={__("Display", "faracart")}
								description={__("The button look.", "faracart")}
							>
								<Stack spacing={2}>
									<Controller
										control={control}
										name="floating_button_size"
										render={({ field }) => (
											<TextField
												{...field}
												type="number"
												size="small"
												fullWidth
												label={__("Button size (px)", "faracart")}
												helperText={__(
													"The round button diameter (32–96).",
													"faracart",
												)}
												value={Number(field.value) || 56}
												onChange={(event) =>
													field.onChange(Number(event.target.value) || 56)
												}
												slotProps={{ htmlInput: { min: 32, max: 96 } }}
												sx={{ maxWidth: 360 }}
											/>
										)}
									/>
									<BooleanField
										control={control}
										name="floating_animation"
										label={__("Animate the button and drawer", "faracart")}
										description={__(
											"Smooth open/close transitions for the floating button and its drawer.",
											"faracart",
										)}
									/>
									<Controller
										control={control}
										name="floating_icon"
										render={({ field }) => (
											<TextField
												{...field}
												size="small"
												fullWidth
												label={__("Button icon", "faracart")}
												helperText={__(
													"A custom glyph/emoji shown inside the button (leave empty for the default cart icon).",
													"faracart",
												)}
												sx={{ maxWidth: 360 }}
											/>
										)}
									/>
									<Controller
										control={control}
										name="floating_label"
										render={({ field }) => (
											<TextField
												{...field}
												size="small"
												fullWidth
												label={__("Button label / tooltip", "faracart")}
												helperText={__(
													"The accessible label and tooltip for the button (leave empty for the default).",
													"faracart",
												)}
												sx={{ maxWidth: 360 }}
											/>
										)}
									/>
								</Stack>
							</SectionCard>
						</Stack>
					)}

					{tab === 3 && (
						<Stack spacing={2.5}>
							<SectionCard
								title={__("Mission Calculation", "faracart")}
								description={__(
									"Which cart money counts toward missions. Defaults match the storefront behavior before these options existed.",
									"faracart",
								)}
							>
								<Stack spacing={1}>
									<BooleanField
										control={control}
										name="calculation_include_tax"
										label={__("Include taxes", "faracart")}
										description={__(
											"Add line taxes to the subtotal-style money bases.",
											"faracart",
										)}
									/>
									<BooleanField
										control={control}
										name="calculation_include_discount"
										label={__("Include discounts", "faracart")}
										description={__(
											"When off, cart coupons and discounts do not reduce the discounted-subtotal basis.",
											"faracart",
										)}
									/>
									<BooleanField
										control={control}
										name="calculation_include_shipping"
										label={__("Include shipping", "faracart")}
										description={__(
											"When on, shipping charges count toward the cart-total basis.",
											"faracart",
										)}
									/>
									<BooleanField
										control={control}
										name="calculation_include_sale"
										label={__("Include sale items", "faracart")}
										description={__(
											"When off, products currently on sale are excluded from every mission.",
											"faracart",
										)}
									/>
									<BooleanField
										control={control}
										name="calculation_include_virtual"
										label={__("Include virtual products", "faracart")}
										description={__(
											"When off, virtual and downloadable products are excluded from every mission.",
											"faracart",
										)}
									/>
								</Stack>
							</SectionCard>
						</Stack>
					)}

					{tab === 4 && (
						<Stack spacing={2.5}>
							<SectionCard
								title={__("Performance", "faracart")}
								description={__(
									"Caching and what the storefront measures.",
									"faracart",
								)}
							>
								<Stack spacing={1}>
									<BooleanField
										control={control}
										name="performance_caching"
										label={__("Cache progress payloads", "faracart")}
										description={__(
											"Serve repeat widget polls from a short-lived cache (10s) keyed by the cart — fewer mission evaluations, at the cost of a brief staleness window after cart changes.",
											"faracart",
										)}
									/>
									<BooleanField
										control={control}
										name="analytics_enabled"
										label={__("Analytics tracking", "faracart")}
										description={__(
											"Collect mission and suggestion events. Keep the missions running while turning event collection off.",
											"faracart",
										)}
									/>
									<BooleanField
										control={control}
										name="performance_suggestions"
										label={__("Product suggestions", "faracart")}
										description={__(
											"Show recommended products that help reach the mission.",
											"faracart",
										)}
									/>
									<SelectField
										control={control}
										name="suggestions_ranking"
										label={__("Suggestion ranking", "faracart")}
										description={__(
											"How suggested products are ordered (Phase 32 advanced upsell ranking).",
											"faracart",
										)}
										options={[
											{ value: "balanced", label: __("Balanced", "faracart") },
											{
												value: "price",
												label: __("Cheapest first", "faracart"),
											},
											{
												value: "popularity",
												label: __("Most popular first", "faracart"),
											},
										]}
									/>
								</Stack>
							</SectionCard>
						</Stack>
					)}

					{tab === 5 && (
						<Stack spacing={2.5}>
							<SectionCard
								title={__("Advanced", "faracart")}
								description={__(
									"Storefront customization and the developer surface.",
									"faracart",
								)}
							>
								<Stack spacing={2}>
									{/* Debug logging is a developer feature, not a settings toggle —
                    enable it with the FARACART_DEBUG / FARACART_LOGGING
                    constants or the faracart_debug_mode /
                    faracart_logging_enabled filters (Utils\Logger). */}
									<Controller
										control={control}
										name="frontend_custom_css"
										render={({ field }) => (
											<TextField
												{...field}
												multiline
												minRows={3}
												size="small"
												fullWidth
												label={__("Custom CSS", "faracart")}
												helperText={__(
													"Appended to the storefront widget stylesheet (tags are stripped).",
													"faracart",
												)}
												sx={{ maxWidth: 560 }}
											/>
										)}
									/>
								</Stack>
							</SectionCard>

							{Boolean(data?.developer_hooks) && (
								<SectionCard
									title={__("Developer hooks", "faracart")}
									description={__(
										"The plugin’s public actions and filters — a reference for theme and plugin developers.",
										"faracart",
									)}
								>
									<Box sx={{ mt: -1 }}>
										{hooks.map((hook) => (
											<HookRow
												key={`${hook.type}-${hook.hook}`}
												type={hook.type}
												hook={hook.hook}
												description={hook.description}
											/>
										))}
									</Box>
								</SectionCard>
							)}
						</Stack>
					)}
				</Stack>
			</form>
		</PageContainer>
	);
}
