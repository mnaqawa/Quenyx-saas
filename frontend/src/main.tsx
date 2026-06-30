import React from 'react'
import ReactDOM from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import App from './App.tsx'
import './index.css'
import { LanguageProvider } from './i18n/LanguageContext'
import { WorkspaceProvider } from './workspaces/WorkspaceContext'
import { OnboardingProvider } from './onboarding/OnboardingContext'
import { ErrorBoundary } from './components/ErrorBoundary'
import { ToastProvider } from './components/toast/ToastProvider'

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <ErrorBoundary>
      <LanguageProvider>
        <ToastProvider>
          <OnboardingProvider>
            <WorkspaceProvider>
              <BrowserRouter>
                <App />
              </BrowserRouter>
            </WorkspaceProvider>
          </OnboardingProvider>
        </ToastProvider>
      </LanguageProvider>
    </ErrorBoundary>
  </React.StrictMode>,
)
