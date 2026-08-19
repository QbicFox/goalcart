import Box from "@mui/material/Box";
import { alpha } from "@mui/material/styles";

import { useActionBar } from "../../providers/ActionBarProvider";

/**
 * The sticky bottom action bar (admin UX).
 *
 * Rendered once by AdminLayout below the routed content. Pages register
 * their save / reset / cancel buttons through `useStickyBarActions`; the
 * bar only appears while some page has registered actions.
 *
 * `position: sticky; bottom: 0` keeps it pinned to the bottom edge of the
 * app shell: in full-screen mode the shell fills the viewport and never
 * scrolls (the bar simply sits at the bottom), while in embedded mode it
 * rides the document scroll, docks at the viewport bottom and settles
 * flush at the end of the page. Because it lives outside the padded
 * content area, no negative-margin gymnastics are needed to align it.
 */
export default function ActionBar() {
	const { actions } = useActionBar();

	if (!actions) {
		return null;
	}

	return (
		<Box
			component="footer"
			sx={(theme) => ({
				position: "sticky",
				bottom: 0,
				zIndex: theme.zIndex.appBar,
				flexShrink: 0,
				display: "flex",
				alignItems: "center",
				justifyContent: "flex-end",
				flexWrap: "wrap",
				gap: 1.5,
				px: { xs: 2, md: 3 },
				py: 1.25,
				mt: 3,
				border: 1,
				borderColor: "divider",
				borderRadius: "4px",
				bgcolor: alpha(theme.palette.background.paper, 0.8),
				backdropFilter: "blur(8px)",
				WebkitBackdropFilter: "blur(8px)",
			})}
		>
			{actions}
		</Box>
	);
}
