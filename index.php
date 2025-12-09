<?php
// ===============================
//  Ping monitor – latencia a WAN IP
// ===============================

// KONFIGURÁCIA
$csvFile = __DIR__ . '/ping_multi_log.csv';
$mainIp  = '90.183.84.114';

// Čítanie filtrov z POST
$ipFilter   = isset($_POST['ipFilter']) ? trim($_POST['ipFilter']) : 'ALL';
$timeFilter = isset($_POST['timeRange']) ? trim($_POST['timeRange']) : '1h';

// Definícia časových rozsahov (v sekundách)
$rangeOptions = array(
    '30m' => 30 * 60,
    '1h'  => 60 * 60,
    '2h'  => 2 * 60 * 60,
    '8h'  => 8 * 60 * 60,
    '24h' => 24 * 60 * 60,
    '7d'  => 7 * 24 * 60 * 60,
    '30d' => 30 * 24 * 60 * 60,
    'ALL' => null,
);

$now = time();
$rangeSeconds = isset($rangeOptions[$timeFilter]) ? $rangeOptions[$timeFilter] : $rangeOptions['1h'];
$minTimestamp = null;
if ($rangeSeconds !== null) {
    $minTimestamp = $now - $rangeSeconds;
}

// Polia pre graf
$labels      = array();
$gwTimes     = array();
$adTimes     = array();
$net1Times   = array();
$net2Times   = array();
$net3Times   = array();
$nxmsTimes   = array();
$glpiTimes   = array();
$sw229Times  = array();
$sw249Times  = array();
$wanStatus   = array(); // 1 = iná IP ako mainIp, 0 = mainIp alebo N/A

// Štatistika Public IP
$ipStats = array(); // [ip] => ['count'=>n,'first'=>ts,'last'=>ts]

$errorMsg = null;
$csvContent = null;

if (file_exists($csvFile)) {
    // Načítame celý súbor a znormalizujeme na UTF-8
    $raw = @file_get_contents($csvFile);
    if ($raw === false) {
        $errorMsg = "Nedá sa načítať CSV súbor.";
    } else {
        // Detekcia UTF-16 BOM (FF FE alebo FE FF)
        $bom2 = substr($raw, 0, 2);
        if ($bom2 === "\xFF\xFE" || $bom2 === "\xFE\xFF") {
            if (function_exists('mb_convert_encoding')) {
                $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
            } else {
                $raw = iconv('UTF-16', 'UTF-8//IGNORE', $raw);
            }
        }
        // Odstránenie UTF-8 BOM, ak by tam bol
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $csvContent = $raw;
    }
} else {
    $errorMsg = "CSV súbor neexistuje: " . htmlspecialchars($csvFile);
}

if ($csvContent !== null && $errorMsg === null) {
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $csvContent);
    rewind($fh);

    // ===== Hlavička CSV =====
    $header = fgetcsv($fh, 0, ',');
    if ($header !== false) {

        // Normalizácia hlavičky (trim + odstránenie BOM + upper-case)
        $upperMap = array(); // UPPER(headerName) => index
        foreach ($header as $i => $nameRaw) {
            $name = trim($nameRaw);
            $name = preg_replace('/^\xEF\xBB\xBF/', '', $name); // odstránenie BOM na úrovni stĺpca
            $upperMap[strtoupper($name)] = $i;
        }
        $getIndex = function($upperName) use ($upperMap) {
            return isset($upperMap[$upperName]) ? $upperMap[$upperName] : null;
        };

        // Mapovanie kanonických názvov
        $canonicalCols = array(
            'Date'          => 'DATE',
            'Time'          => 'TIME',
            'PublicIP'      => 'PUBLICIP',
            'GW_Reply'      => 'GW_REPLY',
            'GW_TimeMS'     => 'GW_TIMEMS',
            'AD_Reply'      => 'AD_REPLY',
            'AD_TimeMS'     => 'AD_TIMEMS',
            'NET1_Reply'    => 'NET1_REPLY',
            'NET1_TimeMS'   => 'NET1_TIMEMS',
            'NET2_Reply'    => 'NET2_REPLY',
            'NET2_TimeMS'   => 'NET2_TIMEMS',
            'NET3_Reply'    => 'NET3_REPLY',
            'NET3_TimeMS'   => 'NET3_TIMEMS',
            'NXMS_Reply'    => 'NXMS_REPLY',
            'NXMS_TimeMS'   => 'NXMS_TIMEMS',
            'GLPI_Reply'    => 'GLPI_REPLY',
            'GLPI_TimeMS'   => 'GLPI_TIMEMS',
            'SW229_Reply'   => 'SW229_REPLY',
            'SW229_TimeMS'  => 'SW229_TIMEMS',
            'SW249_Reply'   => 'SW249_REPLY',
            'SW249_TimeMS'  => 'SW249_TIMEMS',
        );

        $idx = array();
        foreach ($canonicalCols as $canon => $upperName) {
            $i = $getIndex($upperName);
            if ($i !== null) {
                $idx[$canon] = $i;
            }
        }

        // Skontroluj povinné stĺpce
        $required = array_keys($canonicalCols);
        $missing = array();
        foreach ($required as $col) {
            if (!isset($idx[$col])) {
                $missing[] = $col;
            }
        }

        if (!empty($missing)) {
            $errorMsg = "V CSV chýbajú stĺpce: " . implode(', ', $missing);
        } else {
            // ===== Čítanie dát =====
            while (($row = fgetcsv($fh, 0, ',')) !== false) {
                if (count($row) < count($header)) continue;

                $date     = trim($row[$idx['Date']]);
                $time     = trim($row[$idx['Time']]);
                $publicIp = trim($row[$idx['PublicIP']]);

                if ($date === '' || $time === '') continue;

                $ts = strtotime($date . ' ' . $time);
                if ($ts === false) continue;

                // Štatistika IP (bez ohľadu na filter)
                if ($publicIp !== '' && $publicIp !== 'N/A') {
                    if (!isset($ipStats[$publicIp])) {
                        $ipStats[$publicIp] = array(
                            'count' => 0,
                            'first' => $ts,
                            'last'  => $ts,
                        );
                    }
                    $ipStats[$publicIp]['count']++;
                    if ($ts < $ipStats[$publicIp]['first']) $ipStats[$publicIp]['first'] = $ts;
                    if ($ts > $ipStats[$publicIp]['last'])  $ipStats[$publicIp]['last']  = $ts;
                }

                // Časový filter
                if ($minTimestamp !== null && $ts < $minTimestamp) continue;

                // Filter podľa IP pre graf
                if ($ipFilter !== 'ALL') {
                    if ($publicIp === '' || $publicIp === 'N/A' || $publicIp !== $ipFilter) {
                        continue;
                    }
                }

                // helper na čas/ms
                $getTimeValue = function($reply, $timeVal) {
                    $reply   = trim($reply);
                    $timeVal = trim($timeVal);
                    if ($reply !== 'OK' || $timeVal === '') return null;
                    if (is_numeric($timeVal)) return (float)$timeVal;
                    return null;
                };

                $gwVal    = $getTimeValue($row[$idx['GW_Reply']],    $row[$idx['GW_TimeMS']]);
                $adVal    = $getTimeValue($row[$idx['AD_Reply']],    $row[$idx['AD_TimeMS']]);
                $net1Val  = $getTimeValue($row[$idx['NET1_Reply']],  $row[$idx['NET1_TimeMS']]);
                $net2Val  = $getTimeValue($row[$idx['NET2_Reply']],  $row[$idx['NET2_TimeMS']]);
                $net3Val  = $getTimeValue($row[$idx['NET3_Reply']],  $row[$idx['NET3_TimeMS']]);
                $nxmsVal  = $getTimeValue($row[$idx['NXMS_Reply']],  $row[$idx['NXMS_TimeMS']]);
                $glpiVal  = $getTimeValue($row[$idx['GLPI_Reply']],  $row[$idx['GLPI_TimeMS']]);
                $sw229Val = $getTimeValue($row[$idx['SW229_Reply']], $row[$idx['SW229_TimeMS']]);
                $sw249Val = $getTimeValue($row[$idx['SW249_Reply']], $row[$idx['SW249_TimeMS']]);

                $labels[]     = $date . ' ' . $time;
                $gwTimes[]    = $gwVal;
                $adTimes[]    = $adVal;
                $net1Times[]  = $net1Val;
                $net2Times[]  = $net2Val;
                $net3Times[]  = $net3Val;
                $nxmsTimes[]  = $nxmsVal;
                $glpiTimes[]  = $glpiVal;
                $sw229Times[] = $sw229Val;
                $sw249Times[] = $sw249Val;

                // WAN status: 1 = iná IP ako mainIp
                if ($publicIp !== '' && $publicIp !== 'N/A' && $publicIp !== $mainIp) {
                    $wanStatus[] = 1;
                } else {
                    $wanStatus[] = 0;
                }
            }
        }
    } else {
        $errorMsg = "CSV súbor je prázdny alebo nemá hlavičku.";
    }

    fclose($fh);
}

// Zoznam IP adries pre filter
$ipOptions = array('ALL');
foreach ($ipStats as $ip => $stat) {
    $ipOptions[] = $ip;
}
?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="utf-8">
    <title>Ping monitor – latencia a WAN IP</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h2 { margin-top: 0; }
        .controls { margin-bottom: 10px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .controls form { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        label { font-size: 0.9rem; }
        select, button { padding: 4px 8px; font-size: 0.9rem; }
        .range-buttons button,
        .series-buttons button {
            border: 1px solid #ccc;
            background: #f4f4f4;
            cursor: pointer;
        }
        .range-buttons button.active,
        .series-buttons button.active {
            background: #007bff;
            color: #fff;
            border-color: #007bff;
        }
        #ipTable { margin-top: 20px; }
        table { border-collapse: collapse; font-size: 0.85rem; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background-color: #f2f2f2; }
        .note { font-size: 0.8rem; color: #555; margin-top: 5px; }
        .series-wrapper { margin: 10px 0 5px 0; }
        .series-label { font-size: 0.85rem; margin-right: 5px; }
    </style>
</head>
<body>

<h2>Ping monitor – latencia a WAN IP</h2>
<p>Primárna WAN IP: <strong><?php echo htmlspecialchars($mainIp); ?></strong>
   | CSV súbor: <code><?php echo htmlspecialchars(basename($csvFile)); ?></code>
</p>

<div class="controls">
    <form method="post">
        <label>
            Filtrovať podľa Public IP:
            <select name="ipFilter">
                <?php foreach ($ipOptions as $ipOpt): ?>
                    <option value="<?php echo htmlspecialchars($ipOpt); ?>" <?php if ($ipFilter === $ipOpt) echo 'selected'; ?>>
                        <?php echo ($ipOpt === 'ALL') ? 'Všetky IP' : $ipOpt; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <div class="range-buttons">
            <?php
            $buttons = array(
                '30m' => '30 min',
                '1h'  => '1 hodina',
                '2h'  => '2 hodiny',
                '8h'  => '8 hodín',
                '24h' => '24 hodín',
                '7d'  => '7 dní',
                '30d' => '30 dní (mesiac)',
                'ALL' => 'Celý log',
            );
            foreach ($buttons as $value => $labelText):
            ?>
                <button type="submit" name="timeRange"
                        value="<?php echo $value; ?>"
                        class="<?php echo ($timeFilter === $value ? 'active' : ''); ?>">
                    <?php echo $labelText; ?>
                </button>
            <?php endforeach; ?>
        </div>

        <noscript>
            <button type="submit">Zobraziť</button>
        </noscript>
    </form>

    <button type="button" onclick="toggleIpTable()">Prehľad WAN IP</button>
</div>

<div class="series-wrapper">
    <span class="series-label">Zobraziť linku:</span>
    <div class="series-buttons">
        <button type="button" class="series-btn active" data-series="ALL">Všetko</button>
        <button type="button" class="series-btn" data-series="GW">GW 10.0.0.1</button>
        <button type="button" class="series-btn" data-series="AD">AD/DNS 10.0.2.2</button>
        <button type="button" class="series-btn" data-series="NET1">NET1 8.8.8.8</button>
        <button type="button" class="series-btn" data-series="NET2">NET2 1.1.1.1</button>
        <button type="button" class="series-btn" data-series="NET3">NET3 8.8.4.4</button>
        <button type="button" class="series-btn" data-series="NXMS">NetXMS 10.0.2.182</button>
        <button type="button" class="series-btn" data-series="GLPI">GLPI 10.0.2.183</button>
        <button type="button" class="series-btn" data-series="SW229">SW 10.0.2.229</button>
        <button type="button" class="series-btn" data-series="SW249">SW 10.0.2.249</button>
    </div>
</div>

<p class="note">
    Červené pásy v grafe označujú obdobia, keď je Public IP <strong>iná</strong> ako
    <strong><?php echo htmlspecialchars($mainIp); ?></strong>.
</p>

<?php if ($errorMsg !== null): ?>
    <p style="color:red;"><?php echo htmlspecialchars($errorMsg); ?></p>
<?php endif; ?>

<canvas id="latencyChart" height="120"></canvas>

<div id="ipTable" style="display:none;">
    <h3>Prehľad verejných IP (zo všetkých riadkov CSV)</h3>
    <?php if (empty($ipStats)): ?>
        <p>Žiadne Public IP sa v logu nenašli.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>Public IP</th>
                <th>Počet riadkov</th>
                <th>Prvý výskyt</th>
                <th>Posledný výskyt</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($ipStats as $ip => $stat): ?>
                <tr>
                    <td><?php echo htmlspecialchars($ip); ?></td>
                    <td><?php echo (int)$stat['count']; ?></td>
                    <td><?php echo date('Y-m-d H:i:s', $stat['first']); ?></td>
                    <td><?php echo date('Y-m-d H:i:s', $stat['last']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
function toggleIpTable() {
    var el = document.getElementById('ipTable');
    el.style.display = (el.style.display === 'none' || el.style.display === '') ? 'block' : 'none';
}

var labels    = <?php echo json_encode($labels); ?>;
var gwTimes   = <?php echo json_encode($gwTimes); ?>;
var adTimes   = <?php echo json_encode($adTimes); ?>;
var net1Times = <?php echo json_encode($net1Times); ?>;
var net2Times = <?php echo json_encode($net2Times); ?>;
var net3Times = <?php echo json_encode($net3Times); ?>;
var nxmsTimes = <?php echo json_encode($nxmsTimes); ?>;
var glpiTimes = <?php echo json_encode($glpiTimes); ?>;
var sw229Times= <?php echo json_encode($sw229Times); ?>;
var sw249Times= <?php echo json_encode($sw249Times); ?>;
var wanStatus = <?php echo json_encode($wanStatus); ?>;

var ctx = document.getElementById('latencyChart').getContext('2d');

var chart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: labels,
        datasets: [
            { label: 'GW 10.0.0.1',       data: gwTimes,   borderColor: '#1f77b4', backgroundColor: 'rgba(31,119,180,0.1)',  borderWidth: 1, tension: 0.2, yAxisID: 'y', spanGaps: true },
            { label: 'AD/DNS 10.0.2.2',   data: adTimes,   borderColor: '#ff7f0e', backgroundColor: 'rgba(255,127,14,0.1)', borderWidth: 1, tension: 0.2, yAxisID: 'y', spanGaps: true },
            { label: 'NET1 8.8.8.8',      data: net1Times, borderColor: '#2ca02c', backgroundColor: 'rgba(44,160,44,0.1)',  borderWidth: 1, tension: 0.2, yAxisID: 'y', spanGaps: true },
            { label: 'NET2 1.1.1.1',      data: net2Times, borderColor: '#17becf', backgroundColor: 'rgba(23,190,207,0.1)', borderWidth: 1, tension: 0.2, yAxisID: 'y', spanGaps: true },
            { label: 'NET3 8.8.4.4',      data: net3Times, borderColor: '#9467bd', backgroundColor: 'rgba(148,103,189,0.1)',borderWidth: 1, tension: 0.2, yAxisID: 'y', spanGaps: true },
            { label: 'NetXMS 10.0.2.182', data: nxmsTimes, borderColor: '#8c564b', backgroundColor: 'rgba(140,86,75,0.1)',  borderWidth: 1, tension: 0.2, yAxisID: 'y', spanGaps: true },
            { label: 'GLPI 10.0.2.183',   data: glpiTimes, borderColor: '#e377c2', backgroundColor: 'rgba(227,119,194,0.1)',borderWidth: 1, tension: 0.2, yAxisID: 'y', spanGaps: true },
            { label: 'SWITCH 10.0.2.229', data: sw229Times,borderColor: '#7f7f7f', backgroundColor: 'rgba(127,127,127,0.1)',borderWidth: 1, tension: 0.2, yAxisID: 'y', spanGaps: true },
            { label: 'SWITCH 10.0.2.249', data: sw249Times,borderColor: '#bcbd22', backgroundColor: 'rgba(188,189,34,0.1)', borderWidth: 1, tension: 0.2, yAxisID: 'y', spanGaps: true },
            {
                type: 'bar',
                label: 'WAN != <?php echo $mainIp; ?>',
                data: wanStatus,
                yAxisID: 'y1',
                backgroundColor: 'rgba(255,0,0,0.18)',
                borderWidth: 0,
                barPercentage: 1.0,
                categoryPercentage: 1.0
            }
        ]
    },
    options: {
        interaction: {
            mode: 'nearest',
            intersect: false
        },
        plugins: {
            legend: {
                display: true,
                onClick: function () {} // vypneme klikanie na legendu, používame tlačidlá
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        if (context.datasetIndex === 9) {
                            if (context.parsed.y === 1) {
                                return 'WAN != <?php echo $mainIp; ?>';
                            }
                            return null;
                        }
                        return context.dataset.label + ': ' + context.parsed.y + ' ms';
                    }
                }
            }
        },
        scales: {
            x: {
                ticks: { maxRotation: 60, minRotation: 30, autoSkip: true }
            },
            y: {
                beginAtZero: true,
                title: { display: true, text: 'ms' }
            },
            y1: {
                position: 'right',
                display: false,
                min: 0,
                max: 1
            }
        }
    }
});

// mapovanie tlačidiel na indexy datasetov
var seriesMap = {
    'GW': 0,
    'AD': 1,
    'NET1': 2,
    'NET2': 3,
    'NET3': 4,
    'NXMS': 5,
    'GLPI': 6,
    'SW229': 7,
    'SW249': 8
    // index 9 = WAN bar, ten nechávame vždy viditeľný
};

var seriesButtons = document.querySelectorAll('.series-btn');

function setSeriesFilter(key) {
    seriesButtons.forEach(function(btn) {
        btn.classList.toggle('active', btn.getAttribute('data-series') === key);
        if (key === 'ALL' && btn.getAttribute('data-series') === 'ALL') {
            btn.classList.add('active');
        }
    });

    chart.data.datasets.forEach(function(ds, i) {
        if (i === 9) {
            // WAN bar vždy zapnutý
            ds.hidden = false;
            return;
        }

        if (key === 'ALL') {
            ds.hidden = false;
        } else {
            var targetIndex = seriesMap[key];
            ds.hidden = (i !== targetIndex);
        }
    });

    chart.update();
}

seriesButtons.forEach(function(btn) {
    btn.addEventListener('click', function() {
        var key = this.getAttribute('data-series');
        if (key === 'ALL') {
            setSeriesFilter('ALL');
        } else {
            setSeriesFilter(key);
        }
    });
});

// default: všetko zapnuté
setSeriesFilter('ALL');
</script>

</body>
</html>
