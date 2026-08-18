import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import App from './App';
import AppProviders from './providers/AppProviders';
import '@ncdai/react-wheel-picker/style.css';
import './components/wheel-picker/wheelPicker.css';
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
