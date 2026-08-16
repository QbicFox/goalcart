import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import App from './App';
import AppProviders from './providers/AppProviders';
import './styles.css';

const container = document.getElementById('faracart-admin');

if (container) {
  createRoot(container).render(
    <StrictMode>
      <AppProviders>
        <App />
      </AppProviders>
    </StrictMode>
  );
}
