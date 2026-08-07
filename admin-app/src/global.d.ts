import type { GoalCartBootData } from './types';

declare global {
  interface Window {
    goalcart?: GoalCartBootData;
  }
}

export {};
