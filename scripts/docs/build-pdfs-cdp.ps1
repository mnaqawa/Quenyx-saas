<#
  Quenyx vOPS HUB — Documentation PDF builder (DETERMINISTIC, CDP-driven).

  Pipeline: Markdown --(PHP CommonMark + branded template)--> HTML
            --(headless Edge via DevTools Protocol)--> PDF.

  Why this exists: the simple `--print-to-pdf` CLI captures on a virtual-time
  heuristic that RACES Paged.js/Mermaid pagination, so longer documents were
  silently truncated to 1-2 pages (and the result was non-deterministic between
  runs). This builder instead waits for the page's own `data-pdf-ready` signal
  (set after Paged.js + Mermaid finish) and only THEN calls Page.printToPDF with
  preferCSSPageSize=true, producing complete, repeatable, A4-paged output.

  Usage:
    pwsh -File scripts/docs/build-pdfs-cdp.ps1                 # build all docs
    pwsh -File scripts/docs/build-pdfs-cdp.ps1 07_AI_PLATFORM_BIBLE 05_...  # subset
#>
$Only = $args

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path "$PSScriptRoot\..\..").Path
$htmlDir = Join-Path $repo 'build\docs-html'
$pdfDir = Join-Path $repo 'docs\pdf'
New-Item -ItemType Directory -Force -Path $htmlDir, $pdfDir | Out-Null

# --- locate PHP + mbstring ----------------------------------------------------
$php = (Get-Command php).Source
$phpDir = Split-Path $php
$extDir = Join-Path $phpDir 'ext'
if (-not (Test-Path (Join-Path $extDir 'php_mbstring.dll'))) { throw "php_mbstring.dll not found in $extDir" }

# --- locate Edge (fall back to Chrome) ---------------------------------------
$browser = @(
    "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    "C:\Program Files\Microsoft\Edge\Application\msedge.exe",
    "C:\Program Files\Google\Chrome\Application\chrome.exe",
    "C:\Program Files (x86)\Google\Chrome\Application\chrome.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $browser) { throw "No headless Edge/Chrome found." }
Write-Host "Browser: $browser"

# --- documents (01-32 + OBSERVE) ---------------------------------------------
$docs = @(
    'docs\quenyx-v1\01_EXECUTIVE_OVERVIEW.md', 'docs\quenyx-v1\02_PRODUCT_BROCHURE.md',
    'docs\quenyx-v1\03_INVESTOR_DECK_OUTLINE.md', 'docs\quenyx-v1\04_EXECUTIVE_WHITEPAPER.md',
    'docs\quenyx-v1\05_PLATFORM_ARCHITECTURE_BIBLE.md', 'docs\quenyx-v1\06_QCIF_ARCHITECTURE_BIBLE.md',
    'docs\quenyx-v1\07_AI_PLATFORM_BIBLE.md', 'docs\quenyx-v1\08_API_REFERENCE.md',
    'docs\quenyx-v1\09_DATABASE_REFERENCE.md', 'docs\quenyx-v1\10_DEPLOYMENT_GUIDE.md',
    'docs\quenyx-v1\11_DEVELOPER_GUIDE.md', 'docs\quenyx-v1\12_ADMINISTRATOR_GUIDE.md',
    'docs\quenyx-v1\13_CUSTOMER_USER_GUIDE.md', 'docs\quenyx-v1\14_QYNSIGHT_GUIDE.md',
    'docs\quenyx-v1\15_QYNSHIELD_GUIDE.md', 'docs\quenyx-v1\16_AI_USER_GUIDE.md',
    'docs\quenyx-v1\17_IMPLEMENTATION_GUIDE.md', 'docs\quenyx-v1\18_OPERATIONS_RUNBOOK.md',
    'docs\quenyx-v1\19_SECURITY_WHITEPAPER.md', 'docs\quenyx-v1\20_COMPLIANCE_WHITEPAPER.md',
    'docs\quenyx-v1\21_ENGINEERING_PRINCIPLES_AND_STANDARDS.md',
    'docs\quenyx-v1\22_QYNASSET_GUIDE.md', 'docs\quenyx-v1\23_AI_ADAPTER_DEVELOPER_GUIDE.md',
    'docs\quenyx-v1\24_AUTOMATION_PLATFORM_GUIDE.md', 'docs\quenyx-v1\25_QYNRUN_GUIDE.md',
    'docs\quenyx-v1\26_QYNREACT_GUIDE.md', 'docs\quenyx-v1\27_INCIDENT_RESPONSE_GUIDE.md',
    'docs\quenyx-v1\28_ENTERPRISE_KNOWLEDGE_GUIDE.md', 'docs\quenyx-v1\29_SERVICE_DESK_GUIDE.md',
    'docs\quenyx-v1\30_NOTIFICATION_GUIDE.md', 'docs\quenyx-v1\31_COLLABORATION_GUIDE.md',
    'docs\quenyx-v1\32_GLOBAL_TIMELINE_GUIDE.md',
    'docs\OBSERVE_RUNBOOK.md'
)
if ($Only) { $docs = $docs | Where-Object { $n = [IO.Path]::GetFileNameWithoutExtension($_); $Only -contains $n } }

# --- CDP helpers --------------------------------------------------------------
$script:ct = [System.Threading.CancellationToken]::None
function Send-Cdp($ws, [int]$id, [string]$method, $params) {
    $msg = @{ id = $id; method = $method }
    if ($params) { $msg.params = $params }
    $json = $msg | ConvertTo-Json -Depth 12 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($json)
    $ws.SendAsync([ArraySegment[byte]]::new($bytes), [System.Net.WebSockets.WebSocketMessageType]::Text, $true, $script:ct).Wait()
}
function Receive-CdpMessage($ws) {
    $buf = New-Object byte[] 65536
    $ms = New-Object System.IO.MemoryStream
    do {
        $res = $ws.ReceiveAsync([ArraySegment[byte]]::new($buf), $script:ct).GetAwaiter().GetResult()
        $ms.Write($buf, 0, $res.Count)
    } while (-not $res.EndOfMessage)
    return [Text.Encoding]::UTF8.GetString($ms.ToArray())
}
# Send a command and pump messages until the matching response id arrives.
function Invoke-Cdp($ws, [ref]$idRef, [string]$method, $params, [int]$timeoutSec = 90) {
    $id = ++($idRef.Value)
    Send-Cdp $ws $id $method $params
    $deadline = (Get-Date).AddSeconds($timeoutSec)
    while ((Get-Date) -lt $deadline) {
        $raw = Receive-CdpMessage $ws
        $obj = $raw | ConvertFrom-Json
        if ($obj.PSObject.Properties.Name -contains 'id' -and $obj.id -eq $id) {
            if ($obj.PSObject.Properties.Name -contains 'error' -and $obj.error) {
                throw "CDP $method error: $($obj.error.message)"
            }
            return $obj.result
        }
    }
    throw "CDP $method timed out after ${timeoutSec}s"
}

# --- launch one headless Edge with remote debugging --------------------------
$port = Get-Random -Minimum 9300 -Maximum 9899
$udir = Join-Path $env:TEMP ("qcdp_" + [Guid]::NewGuid().ToString('N'))
$proc = Start-Process -FilePath $browser -PassThru -WindowStyle Hidden -ArgumentList @(
    '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    "--remote-debugging-port=$port", "--user-data-dir=$udir", 'about:blank'
)

try {
    # wait for the DevTools HTTP endpoint + a page target
    $wsUrl = $null
    for ($i = 0; $i -lt 60 -and -not $wsUrl; $i++) {
        Start-Sleep -Milliseconds 500
        try {
            $targets = Invoke-RestMethod -Uri "http://127.0.0.1:$port/json" -TimeoutSec 3
            $page = $targets | Where-Object { $_.type -eq 'page' } | Select-Object -First 1
            if ($page) { $wsUrl = $page.webSocketDebuggerUrl }
        } catch { }
    }
    if (-not $wsUrl) { throw "DevTools endpoint not reachable on port $port" }

    $ws = [System.Net.WebSockets.ClientWebSocket]::new()
    $ws.ConnectAsync([Uri]$wsUrl, $script:ct).Wait()
    $idRef = [ref]0
    [void](Invoke-Cdp $ws $idRef 'Page.enable' $null)
    [void](Invoke-Cdp $ws $idRef 'Runtime.enable' $null)

    $results = @()
    $n = 0
    foreach ($md in $docs) {
        $n++
        $mdPath = Join-Path $repo $md
        if (-not (Test-Path $mdPath)) { Write-Host "SKIP (missing): $md"; continue }
        $name = [IO.Path]::GetFileNameWithoutExtension($mdPath)
        $htmlPath = Join-Path $htmlDir "$name.html"
        $pdfPath = Join-Path $pdfDir "$name.pdf"

        Write-Host ("[{0}/{1}] {2} : HTML..." -f $n, $docs.Count, $name)
        & cmd /c "`"$php`" -d extension_dir=`"$extDir`" -d extension=mbstring `"$PSScriptRoot\build-pdf.php`" `"$mdPath`" `"$htmlPath`"" | Out-Null
        if (-not (Test-Path $htmlPath)) { Write-Host "  HTML FAILED"; continue }

        $url = "file:///" + ($htmlPath -replace '\\', '/')
        [void](Invoke-Cdp $ws $idRef 'Page.navigate' @{ url = $url })

        # Deterministic readiness: wait until Paged.js has emitted its .pagedjs_page boxes AND the
        # count has stopped growing (layout settled). The page's own data-pdf-ready flag is NOT
        # reliable (the PagedPolyfill.preview() promise does not resolve in headless), but the
        # rendered page-box count is an accurate, observable completion signal.
        $ready = $false
        $pages = 0
        $prev = -1
        $stable = 0
        $deadline = (Get-Date).AddSeconds(120)
        while ((Get-Date) -lt $deadline) {
            Start-Sleep -Milliseconds 400
            $r = Invoke-Cdp $ws $idRef 'Runtime.evaluate' @{
                expression    = "(document.readyState==='complete')?document.querySelectorAll('.pagedjs_page').length:-1"
                returnByValue = $true
            }
            $cnt = [int]$r.result.value
            if ($cnt -gt 0 -and $cnt -eq $prev) { $stable++ } else { $stable = 0 }
            $prev = $cnt
            if ($cnt -gt 0 -and $stable -ge 3) { $ready = $true; $pages = $cnt; break }
        }
        if (-not $ready) { Write-Host "  WARN: pagination not detected (printing anyway)" }

        $pdf = Invoke-Cdp $ws $idRef 'Page.printToPDF' @{
            printBackground   = $true
            preferCSSPageSize = $true
            transferMode      = 'ReturnAsBase64'
        } 120
        [IO.File]::WriteAllBytes($pdfPath, [Convert]::FromBase64String($pdf.data))

        $kb = [math]::Round((Get-Item $pdfPath).Length / 1KB, 1)
        Write-Host ("  PDF OK -> {0} KB ({1} pages, settled={2})" -f $kb, $pages, $ready)
        $results += [pscustomobject]@{ Doc = $name; KB = $kb; Pages = $pages; Settled = $ready }
    }

    Write-Host "`n==== PDF build summary ===="
    $results | Format-Table -AutoSize | Out-String | Write-Host
}
finally {
    try { if ($ws) { $ws.Dispose() } } catch { }
    try { if ($proc -and -not $proc.HasExited) { $proc.Kill() } } catch { }
    Start-Sleep -Milliseconds 500
    Remove-Item $udir -Recurse -Force -ErrorAction SilentlyContinue
}
