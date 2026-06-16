<?php

namespace App\Services;

/**
 * Parses Nagios-style performance data / plugin output into normalized metric
 * percentages (cpu, memory, disk, network). PHP port of the frontend
 * utils/perfData.ts logic so stored history matches what the UI derives live.
 */
class PerfMetricExtractor
{
    /** @var array<string, string> metric kind => keyword regex */
    private const METRIC_KEYWORDS = [
        'cpu' => '/\b(cpu|processor|load)\b/i',
        'memory' => '/\b(mem|memory|ram|swap)\b/i',
        'disk' => '/\b(disk|partition|storage|filesystem|mount|space|volume)\b/i',
        'network' => '/\b(network|traffic|bandwidth|latency|ping|interface|eth\d*|nic)\b/i',
    ];

    private const BYTE_UOM = '/^(b|kb|mb|gb|tb|kib|mib|gib|tib)$/i';

    /**
     * Returns metric kind => percent (0..100) for any kind whose keyword matches
     * the service name and yields a numeric percentage. Best-effort; never throws.
     *
     * @return array<string, float>
     */
    public function extract(string $serviceName, ?string $perfdata, ?string $output): array
    {
        $result = [];
        foreach (array_keys(self::METRIC_KEYWORDS) as $kind) {
            if (! preg_match(self::METRIC_KEYWORDS[$kind], $serviceName)) {
                continue;
            }
            $percent = $this->percentForKind($kind, $perfdata, $output);
            if ($percent !== null) {
                $result[$kind] = $percent;
            }
        }

        return $result;
    }

    /**
     * @return list<array{label: string, value: float, uom: string, max: float|null}>
     */
    private function parsePerfData(?string $perf): array
    {
        if ($perf === null || trim($perf) === '') {
            return [];
        }
        $out = [];
        $regex = "/('[^']+'|\"[^\"]+\"|[^=\s]+)=([^;\s]+)(?:;[^;\s]*)?(?:;[^;\s]*)?(?:;[^;\s]*)?(?:;([^;\s]*))?/";
        if (! preg_match_all($regex, $perf, $matches, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($matches as $m) {
            $label = preg_replace('/^[\'"]|[\'"]$/', '', $m[1]);
            $valueStr = $m[2];
            if (! preg_match('/-?\d+(?:\.\d+)?/', $valueStr, $num)) {
                continue;
            }
            $value = (float) $num[0];
            $uom = substr($valueStr, strlen($num[0]));
            $max = (isset($m[3]) && $m[3] !== '' && is_numeric($m[3])) ? (float) $m[3] : null;
            $out[] = ['label' => $label, 'value' => $value, 'uom' => $uom, 'max' => $max];
        }

        return $out;
    }

    private function percentForKind(string $kind, ?string $perfdata, ?string $output): ?float
    {
        $perf = $this->parsePerfData($perfdata);
        $info = (string) ($output ?? '');
        $keyword = self::METRIC_KEYWORDS[$kind];

        $percent = null;

        // 1) Prefer a percentage metric in perfdata (matching kind, else any %).
        foreach ($perf as $p) {
            if ($p['uom'] === '%' && preg_match($keyword, $p['label'])) {
                $percent = $p['value'];
                break;
            }
        }
        if ($percent === null) {
            foreach ($perf as $p) {
                if ($p['uom'] === '%') {
                    $percent = $p['value'];
                    break;
                }
            }
        }

        // 2) Byte metric with a max -> used%.
        if ($percent === null) {
            foreach ($perf as $p) {
                if ($p['max'] !== null && $p['max'] > 0 && preg_match(self::BYTE_UOM, $p['uom'])) {
                    $percent = ($p['value'] / $p['max']) * 100;
                    break;
                }
            }
        }

        // 3) Fallback: a percentage in the plugin output text.
        if ($percent === null && $info !== '' && preg_match('/(\d+(?:\.\d+)?)\s*%/', $info, $pm)) {
            $v = (float) $pm[1];
            if (preg_match('/\b(free|available)\b/i', $info)) {
                $v = 100 - $v;
            }
            $percent = $v;
        }

        if ($percent === null || ! is_finite($percent)) {
            return null;
        }

        return (float) round(max(0, min(100, $percent)), 2);
    }
}
