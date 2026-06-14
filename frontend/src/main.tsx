import React from 'react'
import ReactDOM from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import App from './App.tsx'
import './index.css'
import { LanguageProvider } from './i18n/LanguageContext'
import { WorkspaceProvider } from './workspaces/WorkspaceContext'
import { OnboardingProvider } from './onboarding/OnboardingContext'

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <LanguageProvider>
      <OnboardingProvider>
        <WorkspaceProvider>
          <BrowserRouter>
            <App />
          </BrowserRouter>
        </WorkspaceProvider>
      </OnboardingProvider>
    </LanguageProvider>
  </React.StrictMode>,
)
