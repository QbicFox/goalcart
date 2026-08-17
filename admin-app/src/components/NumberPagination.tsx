import Pagination from '@mui/material/Pagination';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import type { SxProps, Theme } from '@mui/material/styles';
import { __, sprintf } from '@wordpress/i18n';

interface NumberPaginationProps {
  /** Total number of rows (before paging). */
  count: number;
  /** Zero-indexed current page. */
  page: number;
  /** Number of rows shown per page. */
  rowsPerPage: number;
  /** Called with the next zero-indexed page. */
  onPageChange: (page: number) => void;
  /** Optional rows-per-page selector (server-side lists). */
  rowsPerPageOptions?: number[];
  onRowsPerPageChange?: (rowsPerPage: number) => void;
  /** Optional MUI system props passthrough (spacing, etc.). */
  sx?: SxProps<Theme>;
}

/**
 * Shared numbered pagination for admin tables.
 *
 * Renders the standard "Showing X–Y of Z" caption plus an explicit page
 * number control (1 2 3 …) — the MUI <TablePagination> default only shows
 * prev/next arrows, which hides the page count. Used across the mission,
 * campaign and analytics tables; client-side tables pass `page`/`count`
 * derived from their in-memory rows, server-side lists (Missions) pass the
 * envelope totals.
 */
export default function NumberPagination({
  count,
  page,
  rowsPerPage,
  onPageChange,
  rowsPerPageOptions,
  onRowsPerPageChange,
  sx,
}: NumberPaginationProps) {
  const pageCount = Math.max(1, Math.ceil(count / rowsPerPage));
  const current = Math.min(page + 1, pageCount);

  const from = count === 0 ? 0 : page * rowsPerPage + 1;
  const to = Math.min(count, (page + 1) * rowsPerPage);

  return (
    <Stack
      direction="row"
      spacing={2}
      useFlexGap
      sx={{
        alignItems: 'center',
        justifyContent: 'space-between',
        flexWrap: 'wrap',
        gap: 1,
        py: 1,
        ...sx,
      }}
    >
      <Typography variant="body2" color="text.secondary">
        {count === 0
          ? __('No rows', 'faracart')
          : sprintf(
              /* translators: 1: first visible row, 2: last visible row, 3: total rows. */
              __('Showing %d–%d of %d', 'faracart'),
              from,
              to,
              count
            )}
      </Typography>

      {rowsPerPageOptions && onRowsPerPageChange && (
        <TextField
          select
          label={__('Rows per page', 'faracart')}
          size="small"
          sx={{ minWidth: 110 }}
          value={rowsPerPage}
          onChange={(event) => onRowsPerPageChange(Number(event.target.value))}
        >
          {rowsPerPageOptions.map((option) => (
            <MenuItem key={option} value={option}>
              {option}
            </MenuItem>
          ))}
        </TextField>
      )}

      <Pagination
        count={pageCount}
        page={current}
        onChange={(_event, value) => onPageChange(value - 1)}
        size="small"
        shape="rounded"
        color="primary"
        showFirstButton
        showLastButton
      />
    </Stack>
  );
}
