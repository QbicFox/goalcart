import { bool } from '../utils';
import TemplateBar from '../TemplateBar';
import type { GoalTemplateProps } from '../registry';

/**
 * Basic template body — the classic single progress bar. The message and
 * reward chip are rendered by the shared PreviewWidget chrome, not here.
 */
export default function BasicTemplateRenderer({
  goal,
  settings,
  animation,
}: GoalTemplateProps) {
  if (bool(settings, 'showBar', true) === false) {
    return null;
  }

  return (
    <TemplateBar
      settings={settings}
      percent={goal.percentage}
      completed={goal.completed}
      animation={animation}
    />
  );
}
