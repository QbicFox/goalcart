import type { FaraCartBootData } from './types';

declare global {
  interface Window {
    faracart?: FaraCartBootData;
  }
}

export {};
