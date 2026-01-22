import React from 'react'
import ReactDOM from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import App from './App.tsx'
import './index.css'
import { LanguageProvider } from './i18n/LanguageContext'
import { WorkspaceProvider } from './workspaces/WorkspaceContext'

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <LanguageProvider>
      <WorkspaceProvider>
        <BrowserRouter>
          <App />
        </BrowserRouter>
      </WorkspaceProvider>
    </LanguageProvider>
  </React.StrictMode>,
)
