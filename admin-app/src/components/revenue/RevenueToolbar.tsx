import { useQuery } from '@tanstack/react-query';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { fetchGoals } from '../../api/goals';
import DateRangeFilter from '../date-range/DateRangeFilter';

interface RevenueToolbarProps {
  /** Currently selected goal id (0 = all goals). */
  goalId: number;
  onGoalChange: (goalId: number) => void;
  /** Hide the goal selector (pages that do not support goal filtering). */
  showGoal?: boolean;
  /** Extra filter controls rendered after the goal selector. */
  children?: ReactNode;
}

/**
 * Shared filter toolbar for the Phase 33.6 Revenue pages: the global
 * date-range filter (DateRangeContext) plus a goal selector and any
 * page-specific extra controls. Keeps every revenue page's filter
 * behavior consistent with the existing Analytics page.
 */
export default function RevenueToolbar({
  goalId,
  onGoalChange,
  showGoal = true,
  children,
}: RevenueToolbarProps) {
  const goalsQuery = useQuery({
    queryKey: ['goals', 'revenue-filter-options'],
    queryFn: () => fetchGoals({ per_page: 100 }),
  });

  return (
    <Stack
      direction={{ xs: 'column', lg: 'row' }}
      spacing={1.5}
      useFlexGap
      sx={{ alignItems: { xs: 'stretch', lg: 'center' }, flexWrap: 'wrap' }}
    >
      <DateRangeFilter />

      {showGoal && (
        <TextField
          select
          label={__('Goal', 'goalcart')}
          size="small"
          sx={{ minWidth: 190, flexGrow: { lg: 1 } }}
          value={goalId}
          onChange={(event) => onGoalChange(Number(event.target.value))}
        >
          <MenuItem value={0}>{__('All goals', 'goalcart')}</MenuItem>
          {(goalsQuery.data?.items ?? []).map((goal) => (
            <MenuItem key={goal.id} value={goal.id}>
              {goal.name}
            </MenuItem>
          ))}
        </TextField>
      )}

      {children}
    </Stack>
  );
}
