import { useQuery } from '@tanstack/react-query';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { fetchMissions } from '../../api/missions';

interface RevenueToolbarProps {
  /** Currently selected mission id (0 = none / all missions). */
  missionId: number;
  onMissionChange: (missionId: number) => void;
  /** Hide the mission selector (pages that do not support mission filtering). */
  showMission?: boolean;
  /**
   * Required-mission mode (Recommendations): hide the "All missions" option and
   * show a "Select a mission" placeholder — a mission must be chosen before the
   * page can do anything.
   */
  missionRequired?: boolean;
  /** Extra filter controls rendered after the mission selector. */
  children?: ReactNode;
}

/**
 * Shared filter toolbar for the Revenue pages: the mission selector plus
 * any page-specific extra controls. The global date-range filter lives in
 * the dashboard header (AdminLayout), so it is never duplicated here.
 */
export default function RevenueToolbar({
  missionId,
  onMissionChange,
  showMission = true,
  missionRequired = false,
  children,
}: RevenueToolbarProps) {
  const missionsQuery = useQuery({
    queryKey: ['missions', 'revenue-filter-options'],
    queryFn: () => fetchMissions({ per_page: 100 }),
  });

  return (
    <Stack
      direction={{ xs: 'column', lg: 'row' }}
      spacing={1.5}
      useFlexGap
      sx={{ alignItems: { xs: 'stretch', lg: 'center' }, flexWrap: 'wrap' }}
    >
      {showMission && (
        <TextField
          select
          label={__('Mission', 'faracart')}
          size="small"
          sx={{ minWidth: 190, flexGrow: { lg: 1 } }}
          value={missionId}
          onChange={(event) => onMissionChange(Number(event.target.value))}
        >
          {missionRequired ? (
            <MenuItem value={0} disabled>
              {__('Select a mission', 'faracart')}
            </MenuItem>
          ) : (
            <MenuItem value={0}>{__('All missions', 'faracart')}</MenuItem>
          )}
          {(missionsQuery.data?.items ?? []).map((mission) => (
            <MenuItem key={mission.id} value={mission.id}>
              {mission.name}
            </MenuItem>
          ))}
        </TextField>
      )}

      {children}
    </Stack>
  );
}
