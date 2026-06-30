<?php

declare(strict_types=1);

/**
 * Quenyx vOPS HUB — Documentation HTML builder (Markdown -> branded, print-ready HTML).
 *
 * Converts a Markdown document into a single self-contained HTML file with:
 *   - a branded title page (logo, title, document metadata),
 *   - an auto-generated table of contents,
 *   - GitHub-flavored Markdown rendering (tables, code, etc.),
 *   - Mermaid diagrams rendered in-browser,
 *   - Paged.js paged-media (real page numbers, running header/footer, classification footer).
 *
 * The HTML is then printed to PDF by headless Edge/Chrome (see build-pdfs.ps1).
 *
 * Usage: php build-pdf.php <input.md> <output.html>
 */

require __DIR__ . '/../../backend/vendor/autoload.php';

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

$in  = $argv[1] ?? null;
$out = $argv[2] ?? null;
if ($in === null || $out === null) {
    fwrite(STDERR, "usage: php build-pdf.php <input.md> <output.html>\n");
    exit(2);
}
if (!is_file($in)) {
    fwrite(STDERR, "input not found: {$in}\n");
    exit(2);
}

$assetsDir = str_replace('\\', '/', realpath(__DIR__ . '/assets'));
$mermaidUri = 'file:///' . $assetsDir . '/mermaid.min.js';
$pagedUri   = 'file:///' . $assetsDir . '/paged.polyfill.min.js';

$md = file_get_contents($in);
$md = str_replace(["\r\n", "\r"], "\n", $md);
$lines = explode("\n", $md);

// ---- Extract title (first H1) -------------------------------------------------
$title = 'Quenyx vOPS HUB';
$titleIdx = -1;
foreach ($lines as $i => $ln) {
    if (preg_match('/^#\s+(.+?)\s*$/', $ln, $m)) {
        $title = trim($m[1]);
        $titleIdx = $i;
        break;
    }
}

// ---- Extract the leading metadata blockquote (contiguous "> " lines after H1) --
$meta = [];
$revisions = [];
$bqStart = -1;
$bqEnd = -1;
if ($titleIdx >= 0) {
    $j = $titleIdx + 1;
    // skip blank lines
    while ($j < count($lines) && trim($lines[$j]) === '') {
        $j++;
    }
    if ($j < count($lines) && str_starts_with(ltrim($lines[$j]), '>')) {
        $bqStart = $j;
        while ($j < count($lines) && (str_starts_with(ltrim($lines[$j]), '>') || trim($lines[$j]) === '')) {
            // stop if a blank line is followed by a non-blockquote line
            if (trim($lines[$j]) === '') {
                $k = $j + 1;
                while ($k < count($lines) && trim($lines[$k]) === '') {
                    $k++;
                }
                if ($k >= count($lines) || !str_starts_with(ltrim($lines[$k]), '>')) {
                    break;
                }
            }
            $j++;
        }
        $bqEnd = $j - 1;
    }
}

$knownKeys = [
    'Document Version', 'Software Version', 'Applies To', 'Classification',
    'Owner', 'Status', 'Last Updated', 'Document Type',
];

if ($bqStart >= 0) {
    for ($i = $bqStart; $i <= $bqEnd; $i++) {
        $row = ltrim($lines[$i], '> ');
        // 3-column revision rows: | v | date | notes |
        if (preg_match('/^\|\s*(.+?)\s*\|\s*(.+?)\s*\|\s*(.+?)\s*\|\s*$/', $row, $rm)) {
            if (preg_match('/^\d/', $rm[1])) {
                $revisions[] = [$rm[1], $rm[2], $rm[3]];
            }
            continue;
        }
        // 2-column metadata rows: | Key | Value |
        if (preg_match('/^\|\s*(.+?)\s*\|\s*(.+?)\s*\|\s*$/', $row, $mm2)) {
            $key = trim($mm2[1]);
            if (in_array($key, $knownKeys, true)) {
                $meta[$key] = trim($mm2[2]);
            }
        }
    }
}

// ---- Build the body markdown (drop H1 + leading metadata blockquote) ----------
$bodyLines = [];
foreach ($lines as $i => $ln) {
    if ($i === $titleIdx) {
        continue;
    }
    if ($bqStart >= 0 && $i >= $bqStart && $i <= $bqEnd) {
        continue;
    }
    $bodyLines[] = $ln;
}
$body = trim(implode("\n", $bodyLines));

// ---- Convert markdown -> HTML (GFM) -------------------------------------------
$env = new Environment([
    'html_input' => 'allow',
    'allow_unsafe_links' => false,
    'renderer' => ['block_separator' => "\n", 'inner_separator' => "\n", 'soft_break' => "\n"],
]);
$env->addExtension(new CommonMarkCoreExtension());
$env->addExtension(new GithubFlavoredMarkdownExtension());
$converter = new MarkdownConverter($env);
$html = (string) $converter->convert($body);

// ---- Mermaid: <pre><code class="language-mermaid"> -> <pre class="mermaid"> ----
$html = preg_replace_callback(
    '#<pre>\s*<code class="language-mermaid">(.*?)</code>\s*</pre>#s',
    static function (array $m): string {
        $code = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return '<div class="mermaid">' . htmlspecialchars($code, ENT_NOQUOTES, 'UTF-8') . '</div>';
    },
    $html
);

// ---- Add heading ids + build TOC ----------------------------------------------
$toc = [];
$html = preg_replace_callback(
    '#<h([23])>(.*?)</h\1>#s',
    static function (array $m) use (&$toc): string {
        $level = (int) $m[1];
        $text = trim(strip_tags($m[2]));
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $text));
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'sec-' . count($toc);
        }
        $base = $slug;
        $n = 1;
        $existing = array_column($toc, 'slug');
        while (in_array($slug, $existing, true)) {
            $slug = $base . '-' . (++$n);
        }
        $toc[] = ['level' => $level, 'text' => $text, 'slug' => $slug];
        return '<h' . $level . ' id="' . $slug . '">' . $m[2] . '</h' . $level . '>';
    },
    $html
);

$tocHtml = '';
foreach ($toc as $item) {
    $cls = $item['level'] === 2 ? 'toc-l2' : 'toc-l3';
    $tocHtml .= '<li class="' . $cls . '"><a href="#' . $item['slug'] . '"><span class="toc-text">'
        . htmlspecialchars($item['text'], ENT_QUOTES, 'UTF-8')
        . '</span><span class="toc-dots"></span></a></li>' . "\n";
}

// ---- Metadata helpers ---------------------------------------------------------
$classification = $meta['Classification'] ?? 'Internal';
$docVersion = $meta['Document Version'] ?? '2.0';
$swVersion = $meta['Software Version'] ?? 'v1.0.0';
$esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$metaRowsHtml = '';
foreach ($knownKeys as $key) {
    if (isset($meta[$key])) {
        $metaRowsHtml .= '<tr><th>' . $esc($key) . '</th><td>' . $esc($meta[$key]) . '</td></tr>' . "\n";
    }
}

$revHtml = '';
if ($revisions) {
    $revHtml = '<table class="rev"><thead><tr><th>Version</th><th>Date</th><th>Notes</th></tr></thead><tbody>';
    foreach ($revisions as $r) {
        $revHtml .= '<tr><td>' . $esc($r[0]) . '</td><td>' . $esc($r[1]) . '</td><td>' . $esc($r[2]) . '</td></tr>';
    }
    $revHtml .= '</tbody></table>';
}

// Short title for running header (strip leading "NN — ")
$shortTitle = preg_replace('/^\d+\s*[—–-]\s*/u', '', $title);

// Pre-escaped values for the template (string interpolation cannot call closures).
$titleEsc = $esc($title);
$shortTitleEsc = $esc($shortTitle);
$classEsc = $esc($classification);
$swEsc = $esc($swVersion);
$docEsc = $esc($docVersion);

$logoSvg = <<<SVG
<svg class="logo" viewBox="0 0 220 48" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Quenyx">
  <defs><linearGradient id="qg" x1="0" y1="0" x2="1" y2="1">
    <stop offset="0" stop-color="#3b82f6"/><stop offset="1" stop-color="#6366f1"/>
  </linearGradient></defs>
  <circle cx="24" cy="24" r="18" fill="none" stroke="url(#qg)" stroke-width="4"/>
  <line x1="36" y1="36" x2="46" y2="46" stroke="url(#qg)" stroke-width="5" stroke-linecap="round"/>
  <text x="58" y="32" font-family="Segoe UI, Arial, sans-serif" font-size="26" font-weight="700" fill="#0f172a" letter-spacing="1.5">QUENYX</text>
</svg>
SVG;

$css = <<<CSS
:root{
  --brand:#2563eb; --brand2:#6366f1; --ink:#0f172a; --muted:#64748b;
  --line:#e2e8f0; --soft:#f8fafc; --code:#f1f5f9;
}
*{box-sizing:border-box}
html{font-family:"Segoe UI",-apple-system,Arial,sans-serif;color:var(--ink);font-size:11pt;line-height:1.55}
body{margin:0}

@page{
  size:A4; margin:18mm 16mm 16mm 16mm;
  @top-right{ content: "$shortTitle"; font-size:8pt; color:#94a3b8; }
  @bottom-left{ content: "Quenyx vOPS HUB · $swVersion"; font-size:8pt; color:#94a3b8; }
  @bottom-center{ content: "$classification"; font-size:8pt; color:#94a3b8; letter-spacing:.06em; }
  @bottom-right{ content: "Page " counter(page) " / " counter(pages); font-size:8pt; color:#94a3b8; }
}
@page :first{
  margin:0;
  @top-right{content:none} @bottom-left{content:none} @bottom-center{content:none} @bottom-right{content:none}
}

/* ---- Title page ---- */
.title-page{ page:first; break-after:page; height:297mm; width:210mm; position:relative;
  background:linear-gradient(160deg,#0f172a 0%,#1e293b 48%,#312e81 100%); color:#fff;
  padding:34mm 24mm; display:flex; flex-direction:column; }
.title-page .logo{height:42px;width:auto}
.title-page .logo text{fill:#ffffff}
.tp-brandbar{display:flex;align-items:center;gap:14px}
.tp-product{font-size:11pt;letter-spacing:.32em;color:#cbd5e1;text-transform:uppercase;margin-top:2px}
.tp-spacer{flex:1}
.tp-class{display:inline-block;align-self:flex-start;border:1px solid rgba(255,255,255,.35);
  border-radius:999px;padding:5px 14px;font-size:9pt;letter-spacing:.12em;text-transform:uppercase;color:#e2e8f0}
.tp-title{font-size:30pt;font-weight:800;line-height:1.12;margin:10px 0 6px}
.tp-sub{font-size:12pt;color:#cbd5e1;margin-bottom:26px}
.tp-meta{width:100%;border-collapse:collapse;font-size:9.5pt;color:#e2e8f0}
.tp-meta th{ text-align:left;width:42%;padding:5px 10px 5px 0;color:#93c5fd;font-weight:600;
  border-bottom:1px solid rgba(255,255,255,.12);vertical-align:top}
.tp-meta td{padding:5px 0;border-bottom:1px solid rgba(255,255,255,.12)}
.tp-foot{font-size:8.5pt;color:#94a3b8;border-top:1px solid rgba(255,255,255,.18);padding-top:10px}

/* ---- TOC ---- */
.toc{break-after:page}
.toc h2{color:var(--brand);border:none;font-size:17pt;margin:0 0 14px}
.toc ol{list-style:none;margin:0;padding:0}
.toc li{margin:3px 0}
.toc a{display:flex;align-items:baseline;text-decoration:none;color:var(--ink)}
.toc .toc-l2{font-weight:600;margin-top:9px;border-left:3px solid var(--brand);padding-left:10px}
.toc .toc-l3{padding-left:26px;font-weight:400;color:#334155;font-size:10pt}
.toc .toc-text{white-space:normal}
.toc .toc-dots{display:none}

/* ---- Content ---- */
.content{padding-top:2mm}
.content h1{font-size:20pt;color:var(--ink);margin:0 0 8px}
.content h2{font-size:15pt;color:#0f172a;margin:20px 0 8px;padding-bottom:5px;border-bottom:2px solid var(--brand);break-after:avoid}
.content h3{font-size:12.5pt;color:#1e293b;margin:15px 0 6px;break-after:avoid}
.content h4{font-size:11pt;color:#334155;margin:12px 0 5px}
.content p{margin:7px 0}
.content a{color:var(--brand);text-decoration:none}
.content ul,.content ol{margin:7px 0;padding-left:22px}
.content li{margin:3px 0}
.content blockquote{margin:10px 0;padding:8px 14px;background:var(--soft);
  border-left:4px solid var(--brand);color:#334155;border-radius:0 6px 6px 0}
.content blockquote p{margin:4px 0}
.content code{background:var(--code);padding:1px 5px;border-radius:4px;font-family:"Cascadia Code",Consolas,monospace;font-size:9.5pt}
.content pre{background:#0f172a;color:#e2e8f0;padding:12px 14px;border-radius:8px;overflow:auto;
  font-size:8.8pt;line-height:1.45;break-inside:avoid}
.content pre code{background:none;color:inherit;padding:0;font-size:inherit}
.content table{border-collapse:collapse;width:100%;margin:10px 0;font-size:9.3pt;break-inside:avoid}
.content th{background:var(--brand);color:#fff;text-align:left;padding:6px 9px;font-weight:600}
.content td{border:1px solid var(--line);padding:6px 9px;vertical-align:top}
.content tr:nth-child(even) td{background:var(--soft)}
.content hr{border:none;border-top:1px solid var(--line);margin:16px 0}
.content img{max-width:100%}
.mermaid{break-inside:avoid;text-align:center;margin:12px 0}
.rev{border-collapse:collapse;margin-top:6px;font-size:9pt;color:#e2e8f0}
.rev th{color:#93c5fd;text-align:left;padding:3px 12px 3px 0;border-bottom:1px solid rgba(255,255,255,.15)}
.rev td{padding:3px 12px 3px 0;border-bottom:1px solid rgba(255,255,255,.08);vertical-align:top}
CSS;

$metaBlockFront = $metaRowsHtml !== '' ? '<table class="tp-meta"><tbody>' . $metaRowsHtml . '</tbody></table>' : '';
$revFront = $revHtml !== '' ? '<div style="margin-top:14px"><div style="color:#93c5fd;font-size:9pt;font-weight:600;letter-spacing:.04em;margin-bottom:4px">REVISION HISTORY</div>' . $revHtml . '</div>' : '';

$page = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{$shortTitleEsc} — Quenyx vOPS HUB</title>
<style>{$css}</style>
</head>
<body>
<section class="title-page">
  <div class="tp-brandbar">{$logoSvg}<div class="tp-product">vOPS&nbsp;HUB</div></div>
  <div class="tp-spacer"></div>
  <div class="tp-class">{$classEsc}</div>
  <div class="tp-title">{$shortTitleEsc}</div>
  <div class="tp-sub">Quenyx vOPS HUB — {$swEsc} · Document v{$docEsc}</div>
  {$metaBlockFront}
  {$revFront}
  <div class="tp-spacer"></div>
  <div class="tp-foot">© Quenyx vOPS HUB. {$classEsc}. This document is part of Documentation Pack v3.0 and reflects v1.0.0 (GA).</div>
</section>

<nav class="toc">
  <h2>Table of Contents</h2>
  <ol>{$tocHtml}</ol>
</nav>

<main class="content">
<h1>{$titleEsc}</h1>
{$html}
</main>

<script src="{$mermaidUri}"></script>
<script src="{$pagedUri}"></script>
<script>
  window.PagedConfig = { auto: false };
  (async function(){
    try{
      if (window.mermaid){
        mermaid.initialize({ startOnLoad:false, theme:'neutral', securityLevel:'loose', flowchart:{useMaxWidth:true} });
        await mermaid.run({ querySelector: '.mermaid' });
      }
    }catch(e){ console.error('mermaid', e); }
    try{
      if (window.PagedPolyfill){ await window.PagedPolyfill.preview(); }
    }catch(e){ console.error('paged', e); }
    document.body.setAttribute('data-pdf-ready','1');
    document.title = document.title + ' [READY]';
  })();
</script>
</body>
</html>
HTML;

file_put_contents($out, $page);
fwrite(STDOUT, "wrote {$out} (" . count($toc) . " toc entries, " . count($revisions) . " revisions)\n");
