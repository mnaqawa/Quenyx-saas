export interface AlertConditionInput {
  condition?: string
  metric_condition?: string
  operator?: string
  threshold_value?: number
  duration_seconds?: number
}

const STATE_METRICS = new Set(['host_unreachable', 'service_critical', 'service_warning'])

function parseConditionString(condition: string): {
  metric: string
  operator: string
  threshold: string
  durationSeconds?: number
} | null {
  const durationMatch = condition.match(/^(.+?)\s+for\s+(\d+)s$/i)
  const base = durationMatch ? durationMatch[1].trim() : condition.trim()
  const durationSeconds = durationMatch ? Number(durationMatch[2]) : undefined

  const opMatch = base.match(/^(\S+)\s*(>=|<=|!=|>|<|=)\s*(.+)$/)
  if (!opMatch) return null

  return {
    metric: opMatch[1],
    operator: opMatch[2],
    threshold: opMatch[3].trim(),
    durationSeconds,
  }
}

function isTruthyThreshold(value: string): boolean {
  const v = value.toLowerCase()
  return v === '1' || v === 'true' || v === 'yes'
}

/** Readable alert condition for rules list, history, and detail views. */
export function formatReadableAlertCondition(
  input: AlertConditionInput,
  t: (key: string) => string,
): string {
  const metric = input.metric_condition
  const operator = input.operator
  const threshold =
    input.threshold_value != null ? String(input.threshold_value) : undefined

  let parsed: ReturnType<typeof parseConditionString> | null = null
  if (metric && operator && threshold != null) {
    parsed = { metric, operator, threshold, durationSeconds: input.duration_seconds }
  } else if (input.condition) {
    parsed = parseConditionString(input.condition)
  }

  if (!parsed) return input.condition ?? '—'

  const metricKey = `alerts.metric.${parsed.metric}`
  const metricLabel = t(metricKey)
  const metricText = metricLabel === metricKey ? parsed.metric : metricLabel

  if (STATE_METRICS.has(parsed.metric) && parsed.operator === '=' && isTruthyThreshold(parsed.threshold)) {
    const stateKey = `alerts.condition.state.${parsed.metric}`
    const stateText = t(stateKey)
    if (stateText !== stateKey) {
      return appendDuration(stateText, parsed.durationSeconds, t)
    }
  }

  const opKey = `alerts.operator.${parsed.operator}`
  const opLabel = t(opKey)
  const opText = opLabel === opKey ? parsed.operator : opLabel

  const suffix = metricUsesPercent(parsed.metric) ? '%' : ''
  const sentence = t('alerts.condition.sentence')
    .replace('{metric}', metricText)
    .replace('{operator}', opText)
    .replace('{value}', `${parsed.threshold}${suffix}`)

  return appendDuration(sentence, parsed.durationSeconds, t)
}

function metricUsesPercent(metric: string): boolean {
  return ['cpu', 'memory', 'disk', 'network', 'load'].includes(metric)
}

function appendDuration(text: string, durationSeconds: number | undefined, t: (key: string) => string): string {
  if (!durationSeconds || durationSeconds <= 0) return text
  return `${text} ${t('alerts.condition.forDuration').replace('{seconds}', String(durationSeconds))}`
}
