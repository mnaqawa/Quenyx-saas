import type { ReactElement } from 'react'
import type { ModuleKey } from '../../constants/platformRegistry'

type IconProps = { className?: string; size?: number }

function base({ className, size = 16 }: IconProps) {
  return { className: className ?? 'text-white/70', width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const }
}

export function IconInfrastructure({ className, size }: IconProps) {
  const p = base({ className, size })
  return (
    <svg {...p}>
      <rect x="2" y="3" width="20" height="14" rx="2" />
      <path d="M8 21h8M12 17v4" />
    </svg>
  )
}

export function IconAssets({ className, size }: IconProps) {
  const p = base({ className, size })
  return (
    <svg {...p}>
      <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
    </svg>
  )
}

export function IconAutomation({ className, size }: IconProps) {
  const p = base({ className, size })
  return (
    <svg {...p}>
      <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83" />
      <circle cx="12" cy="12" r="3" />
    </svg>
  )
}

export function IconIncidents({ className, size }: IconProps) {
  const p = base({ className, size })
  return (
    <svg {...p}>
      <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
      <line x1="12" y1="9" x2="12" y2="13" />
      <line x1="12" y1="17" x2="12.01" y2="17" />
    </svg>
  )
}

export function IconKnowledge({ className, size }: IconProps) {
  const p = base({ className, size })
  return (
    <svg {...p}>
      <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
      <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />
    </svg>
  )
}

export function IconSupport({ className, size }: IconProps) {
  const p = base({ className, size })
  return (
    <svg {...p}>
      <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
    </svg>
  )
}

export function IconNotifications({ className, size }: IconProps) {
  const p = base({ className, size })
  return (
    <svg {...p}>
      <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
      <path d="M13.73 21a2 2 0 0 1-3.46 0" />
    </svg>
  )
}

export function IconAi({ className, size }: IconProps) {
  const p = base({ className, size })
  return (
    <svg {...p}>
      <path d="M12 3l1.5 4.5L18 9l-4.5 1.5L12 15l-1.5-4.5L6 9l4.5-1.5L12 3z" />
      <path d="M5 19l1-3M19 19l-1-3" />
    </svg>
  )
}

export function IconCompliance({ className, size }: IconProps) {
  const p = base({ className, size })
  return (
    <svg {...p}>
      <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
      <path d="M9 12l2 2 4-4" />
    </svg>
  )
}

export function IconPlatform({ className, size }: IconProps) {
  const p = base({ className, size })
  return (
    <svg {...p}>
      <circle cx="12" cy="12" r="10" />
      <path d="M12 6v6l4 2" />
    </svg>
  )
}

export function IconHosts({ className, size }: IconProps) {
  return <IconInfrastructure className={className} size={size} />
}

export function IconAlerts({ className, size }: IconProps) {
  const p = base({ className, size })
  return (
    <svg {...p}>
      <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
      <path d="M13.73 21a2 2 0 0 1-3.46 0" />
    </svg>
  )
}

const MODULE_ICON_MAP: Record<string, (props: IconProps) => ReactElement> = {
  qynsight: IconInfrastructure,
  qynasset: IconAssets,
  qynrun: IconAutomation,
  qynreact: IconIncidents,
  qynknow: IconKnowledge,
  qynsupport: IconSupport,
  qynnotify: IconNotifications,
  qynshield: IconCompliance,
  qynva: IconPlatform,
  qynbalance: IconPlatform,
  qyncore: IconPlatform,
}

export function ModuleIcon({ moduleKey, className, size }: { moduleKey: ModuleKey | string } & IconProps) {
  const Icon = MODULE_ICON_MAP[moduleKey] ?? IconPlatform
  return <Icon className={className} size={size} />
}
