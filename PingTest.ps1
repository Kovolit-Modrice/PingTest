# ============================================
# PingTest.ps1
# Multi-target ping + verejná IP + Excel-ready
# ============================================

$csvFile = "ping_multi_log.csv"

# --------- CIEĽOVÉ ADRESY -------------------
$targetGW     = "10.0.0.1"      # Gateway
$targetAD     = "10.0.2.2"      # AD / DNS1
$targetNET1   = "8.8.8.8"       # NET1 - hlavný internet (Google DNS)
$targetNET2   = "1.1.1.1"       # NET2 - Cloudflare
$targetNET3   = "8.8.4.4"       # NET3 - Google sekundárny
$targetNXMS   = "10.0.2.182"    # NetXMS server
$targetGLPI   = "10.0.2.183"    # GLPI server
$targetSW229  = "10.0.2.229"    # Switch OPTIKA
$targetSW249  = "10.0.2.249"    # Switch STHRAD

# --------- TLS 1.2 pre HTTP výstup (Public IP) -----
try {
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
} catch {
    # ak by to zlyhalo, iba preskočíme – fallback nižšie
}

function Get-PublicIP {
    try {
        $resp = Invoke-WebRequest -Uri "https://api.ipify.org" -UseBasicParsing -TimeoutSec 5
        $ip = $resp.Content.Trim()
        if ($ip) { return $ip }
    } catch {
        try {
            $resp = Invoke-WebRequest -Uri "https://ifconfig.me/ip" -UseBasicParsing -TimeoutSec 5
            $ip = $resp.Content.Trim()
            if ($ip) { return $ip }
        } catch {
            return "N/A"
        }
    }
    return "N/A"
}

# --------- HLAVIČKA CSV ---------------------
if (!(Test-Path $csvFile)) {
    "Date,Time,PublicIP,GW_Reply,GW_TimeMS,AD_Reply,AD_TimeMS,NET1_Reply,NET1_TimeMS,NET2_Reply,NET2_TimeMS,NET3_Reply,NET3_TimeMS,NXMS_Reply,NXMS_TimeMS,GLPI_Reply,GLPI_TimeMS,SW229_Reply,SW229_TimeMS,SW249_Reply,SW249_TimeMS" `
        | Out-File $csvFile -Encoding utf8
}

$pingObj = New-Object System.Net.NetworkInformation.Ping

# --------- HLAVNÝ CYKLUS --------------------
while ($true) {

    $now  = Get-Date
    $date = $now.ToString("yyyy-MM-dd")
    $time = $now.ToString("HH:mm:ss")

    $publicIP = Get-PublicIP

    try {
        $resGW = $pingObj.Send($targetGW)
        if ($resGW.Status -eq "Success") { $gwReply="OK"; $gwTime=$resGW.RoundtripTime }
        else { $gwReply="FAIL"; $gwTime="" }
    } catch { $gwReply="ERR"; $gwTime="" }

    try {
        $resAD = $pingObj.Send($targetAD)
        if ($resAD.Status -eq "Success") { $adReply="OK"; $adTime=$resAD.RoundtripTime }
        else { $adReply="FAIL"; $adTime="" }
    } catch { $adReply="ERR"; $adTime="" }

    try {
        $resNET1 = $pingObj.Send($targetNET1)
        if ($resNET1.Status -eq "Success") { $net1Reply="OK"; $net1Time=$resNET1.RoundtripTime }
        else { $net1Reply="FAIL"; $net1Time="" }
    } catch { $net1Reply="ERR"; $net1Time="" }

    try {
        $resNET2 = $pingObj.Send($targetNET2)
        if ($resNET2.Status -eq "Success") { $net2Reply="OK"; $net2Time=$resNET2.RoundtripTime }
        else { $net2Reply="FAIL"; $net2Time="" }
    } catch { $net2Reply="ERR"; $net2Time="" }

    try {
        $resNET3 = $pingObj.Send($targetNET3)
        if ($resNET3.Status -eq "Success") { $net3Reply="OK"; $net3Time=$resNET3.RoundtripTime }
        else { $net3Reply="FAIL"; $net3Time="" }
    } catch { $net3Reply="ERR"; $net3Time="" }

    try {
        $resNXMS = $pingObj.Send($targetNXMS)
        if ($resNXMS.Status -eq "Success") { $nxmsReply="OK"; $nxmsTime=$resNXMS.RoundtripTime }
        else { $nxmsReply="FAIL"; $nxmsTime="" }
    } catch { $nxmsReply="ERR"; $nxmsTime="" }

    try {
        $resGLPI = $pingObj.Send($targetGLPI)
        if ($resGLPI.Status -eq "Success") { $glpiReply="OK"; $glpiTime=$resGLPI.RoundtripTime }
        else { $glpiReply="FAIL"; $glpiTime="" }
    } catch { $glpiReply="ERR"; $glpiTime="" }

    try {
        $resSW229 = $pingObj.Send($targetSW229)
        if ($resSW229.Status -eq "Success") { $sw229Reply="OK"; $sw229Time=$resSW229.RoundtripTime }
        else { $sw229Reply="FAIL"; $sw229Time="" }
    } catch { $sw229Reply="ERR"; $sw229Time="" }

    try {
        $resSW249 = $pingObj.Send($targetSW249)
        if ($resSW249.Status -eq "Success") { $sw249Reply="OK"; $sw249Time=$resSW249.RoundtripTime }
        else { $sw249Reply="FAIL"; $sw249Time="" }
    } catch { $sw249Reply="ERR"; $sw249Time="" }

    "$date,$time,$publicIP,$gwReply,$gwTime,$adReply,$adTime,$net1Reply,$net1Time,$net2Reply,$net2Time,$net3Reply,$net3Time,$nxmsReply,$nxmsTime,$glpiReply,$glpiTime,$sw229Reply,$sw229Time,$sw249Reply,$sw249Time" `
        | Out-File $csvFile -Append -Encoding utf8

    Start-Sleep -Seconds 1
}
