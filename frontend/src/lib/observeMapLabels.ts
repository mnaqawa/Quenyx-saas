type TranslateFn = (key: string) => string

const VIEW_LABEL_KEYS: Record<string, string> = {
  'Logical View': 'map.view.logical',
  'Physical View': 'map.view.physical',
  'By Zone': 'map.view.byZone',
  'Security Zones': 'map.view.securityZones',
}

const LAYER_LABEL_KEYS: Record<string, string> = {
  'All Layers': 'map.layer.all',
  'Network Layer': 'map.layer.network',
  'Compute Layer': 'map.layer.compute',
  'Storage Layer': 'map.layer.storage',
  'Security Layer': 'map.layer.security',
}

const ZOOM_LABEL_KEYS: Record<string, string> = {
  'Fit to Screen': 'map.zoom.fit',
  '50%': 'map.zoom.50',
  '100%': 'map.zoom.100',
  '150%': 'map.zoom.150',
  '200%': 'map.zoom.200',
}

const ZONE_LABEL_KEYS: Record<string, string> = {
  'All Zones': 'map.zone.all',
  Unassigned: 'map.zone.unassigned',
}

export function mapViewLabel(value: string, t: TranslateFn): string {
  const key = VIEW_LABEL_KEYS[value]
  return key ? t(key) : value
}

export function mapLayerLabel(value: string, t: TranslateFn): string {
  const key = LAYER_LABEL_KEYS[value]
  return key ? t(key) : value
}

export function mapZoomLabel(value: string, t: TranslateFn): string {
  const key = ZOOM_LABEL_KEYS[value]
  return key ? t(key) : value
}

export function mapZoneLabel(value: string, t: TranslateFn): string {
  const key = ZONE_LABEL_KEYS[value]
  return key ? t(key) : value
}

export function mapConnectionStatusLabel(status: string, t: TranslateFn): string {
  switch (status) {
    case 'Online':
      return t('map.status.online')
    case 'Warning':
      return t('map.status.warning')
    case 'Critical':
      return t('map.status.critical')
    case 'Offline':
      return t('map.status.offline')
    default:
      return status
  }
}
