<#
  Quenyx vOPS HUB — Documentation PDF builder.

  Pipeline: Markdown --(PHP CommonMark + branded template)--> HTML --(headless Edge/Chrome)--> PDF.
  Produces professional, branded PDFs (title page, TOC, page numbers, classification footer,
  rendered Mermaid diagrams) for the external-facing documents in Documentation Pack v2.0.

  Usage:  pwsh -File scripts/docs/build-pdfs.ps1
#>

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path "$PSScriptRoot\..\..").Path
$htmlDir = Join-Path $repo 'build\docs-html'
# Single canonical output folder for ALL externally shareable PDFs.
$pdfDir = Join-Path $repo 'docs\pdf'
New-Item -ItemType Directory -Force -Path $htmlDir, $pdfDir | Out-Null

# --- locate PHP ext dir (for mbstring, required by CommonMark) ---
$php = (Get-Command php).Source
$phpDir = Split-Path $php
$extDir = Join-Path $phpDir 'ext'
if (-not (Test-Path (Join-Path $extDir 'php_mbstring.dll'))) {
    throw "php_mbstring.dll not found in $extDir"
}

# --- locate Edge (fall back to Chrome) ---
$edgeCandidates = @(
    "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    "C:\Program Files\Microsoft\Edge\Application\msedge.exe",
    "C:\Program Files\Google\Chrome\Application\chrome.exe",
    "C:\Program Files (x86)\Google\Chrome\Application\chrome.exe"
)
$browser = $edgeCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $browser) { throw "No headless Edge/Chrome found." }
Write-Host "Browser: $browser"

# --- documents to render: every externally shareable doc (01-21) -> docs\pdf ---
$docs = @(
    @{ md = 'docs\quenyx-v1\01_EXECUTIVE_OVERVIEW.md';                  out = $pdfDir },
    @{ md = 'docs\quenyx-v1\02_PRODUCT_BROCHURE.md';                    out = $pdfDir },
    @{ md = 'docs\quenyx-v1\03_INVESTOR_DECK_OUTLINE.md';               out = $pdfDir },
    @{ md = 'docs\quenyx-v1\04_EXECUTIVE_WHITEPAPER.md';                out = $pdfDir },
    @{ md = 'docs\quenyx-v1\05_PLATFORM_ARCHITECTURE_BIBLE.md';         out = $pdfDir },
    @{ md = 'docs\quenyx-v1\06_QCIF_ARCHITECTURE_BIBLE.md';             out = $pdfDir },
    @{ md = 'docs\quenyx-v1\07_AI_PLATFORM_BIBLE.md';                   out = $pdfDir },
    @{ md = 'docs\quenyx-v1\08_API_REFERENCE.md';                       out = $pdfDir },
    @{ md = 'docs\quenyx-v1\09_DATABASE_REFERENCE.md';                  out = $pdfDir },
    @{ md = 'docs\quenyx-v1\10_DEPLOYMENT_GUIDE.md';                    out = $pdfDir },
    @{ md = 'docs\quenyx-v1\11_DEVELOPER_GUIDE.md';                     out = $pdfDir },
    @{ md = 'docs\quenyx-v1\12_ADMINISTRATOR_GUIDE.md';                 out = $pdfDir },
    @{ md = 'docs\quenyx-v1\13_CUSTOMER_USER_GUIDE.md';                 out = $pdfDir },
    @{ md = 'docs\quenyx-v1\14_QYNSIGHT_GUIDE.md';                      out = $pdfDir },
    @{ md = 'docs\quenyx-v1\15_QYNSHIELD_GUIDE.md';                     out = $pdfDir },
    @{ md = 'docs\quenyx-v1\16_AI_USER_GUIDE.md';                       out = $pdfDir },
    @{ md = 'docs\quenyx-v1\17_IMPLEMENTATION_GUIDE.md';                out = $pdfDir },
    @{ md = 'docs\quenyx-v1\18_OPERATIONS_RUNBOOK.md';                  out = $pdfDir },
    @{ md = 'docs\quenyx-v1\19_SECURITY_WHITEPAPER.md';                 out = $pdfDir },
    @{ md = 'docs\quenyx-v1\20_COMPLIANCE_WHITEPAPER.md';               out = $pdfDir },
    @{ md = 'docs\quenyx-v1\21_ENGINEERING_PRINCIPLES_AND_STANDARDS.md'; out = $pdfDir }
)

$results = @()
$i = 0
foreach ($d in $docs) {
    $i++
    $mdPath = Join-Path $repo $d.md
    if (-not (Test-Path $mdPath)) { Write-Host "SKIP (missing): $($d.md)"; continue }
    $name = [System.IO.Path]::GetFileNameWithoutExtension($mdPath)
    $htmlPath = Join-Path $htmlDir "$name.html"
    $pdfPath = Join-Path $d.out "$name.pdf"

    Write-Host "[$i/$($docs.Count)] $name : generating HTML..."
    & cmd /c "`"$php`" -d extension_dir=`"$extDir`" -d extension=mbstring `"$PSScriptRoot\build-pdf.php`" `"$mdPath`" `"$htmlPath`"" | Out-Null
    if (-not (Test-Path $htmlPath)) { Write-Host "  HTML FAILED"; continue }

    $url = "file:///" + ($htmlPath -replace '\\', '/')
    # Headless Edge is unreliable writing its profile/output under the repo tree; use a fresh temp
    # profile + temp output, then copy the finished PDF into the repo.
    $stamp = [Guid]::NewGuid().ToString('N')
    $tmpPdf = Join-Path $env:TEMP "qpdf_$stamp.pdf"
    $tmpUd = Join-Path $env:TEMP "qprof_$stamp"
    # Headless Edge writes a benign startup line to stderr; under $ErrorActionPreference='Stop'
    # that would abort the render. Run the browser with 'Continue' and merge stderr to stdout so it
    # is harmless, then poll for the output file (renders can complete slightly after the process
    # returns).
    $prevEAP = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    & $browser --headless=new --disable-gpu --user-data-dir="$tmpUd" --no-pdf-header-footer --virtual-time-budget=25000 --print-to-pdf="$tmpPdf" "$url" 2>&1 | Out-Null
    $ErrorActionPreference = $prevEAP
    for ($w = 0; $w -lt 40 -and -not (Test-Path $tmpPdf); $w++) { Start-Sleep -Milliseconds 500 }
    if (Test-Path $tmpPdf) {
        Copy-Item $tmpPdf $pdfPath -Force
        Remove-Item $tmpPdf -Force -ErrorAction SilentlyContinue
        Remove-Item $tmpUd -Recurse -Force -ErrorAction SilentlyContinue
    }

    if (Test-Path $pdfPath) {
        $pages = 0
        try {
            $txt = [System.IO.File]::ReadAllText($pdfPath, [System.Text.Encoding]::GetEncoding(28591))
            $pages = ([regex]::Matches($txt, '/Type\s*/Page[^s]')).Count
        } catch { $pages = 0 }
        $kb = [math]::Round((Get-Item $pdfPath).Length / 1KB, 1)
        Write-Host "  PDF OK -> $pdfPath ($kb KB, ~$pages pages)"
        $results += [pscustomobject]@{ Doc = $name; KB = $kb; Pages = $pages; Path = $pdfPath }
    }
    else {
        Write-Host "  PDF FAILED"
        $results += [pscustomobject]@{ Doc = $name; KB = 0; Pages = 0; Path = '(failed)' }
    }
}

Write-Host "`n==== PDF build summary ===="
$results | Format-Table -AutoSize
