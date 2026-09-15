# ============================================================
# GNCP System - Offline Font Downloader
# Run this script ONCE while you have internet.
# After that, the system works fully offline.
# ============================================================
$fontDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ua = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120"
Write-Host "=== GNCP Offline Font Downloader ===" -ForegroundColor Cyan
Write-Host "Downloading fonts to: $fontDir"

$urls = @{
    "outfit_jakarta" = "https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap"
    "school_site"    = "https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Open+Sans:ital,wght@0,300;0,400;0,600;0,700;1,300;1,400&display=swap"
}

$allCss = ""
foreach ($key in $urls.Keys) {
    Write-Host "Fetching CSS: $key ..."
    try {
        $css = (Invoke-WebRequest -Uri $urls[$key] -Headers @{"User-Agent"=$ua} -UseBasicParsing).Content
        $allCss += "`n/* $key */`n" + $css
        Write-Host "  OK"
    } catch { Write-Host "  FAILED: $_" }
}

$woff2Urls = [regex]::Matches($allCss, 'url\((https://fonts\.gstatic\.com/[^)]+\.woff2)\)') | ForEach-Object { $_.Groups[1].Value } | Select-Object -Unique
Write-Host "Found $($woff2Urls.Count) unique woff2 files."

$urlToFile = @{}
$i = 0
foreach ($url in $woff2Urls) {
    $i++
    $hash = [System.BitConverter]::ToString([System.Security.Cryptography.MD5]::Create().ComputeHash([System.Text.Encoding]::UTF8.GetBytes($url))).Replace("-","").ToLower().Substring(0,8)
    $filename = "font_$hash.woff2"
    $outPath = Join-Path $fontDir $filename
    Write-Host "[$i/$($woff2Urls.Count)] $filename"
    try {
        Invoke-WebRequest -Uri $url -OutFile $outPath -UseBasicParsing -Headers @{"User-Agent"=$ua}
        $urlToFile[$url] = $filename
    } catch { Write-Host "  FAILED: $_" }
}

$localCss = $allCss
foreach ($url in $urlToFile.Keys) { $localCss = $localCss.Replace($url, $urlToFile[$url]) }
$localCss | Out-File "$fontDir\fonts.css" -Encoding utf8
Write-Host "fonts.css written. DONE."
