<?php

/*
  MIT License

  Copyright (c) 2020-2022 b3rs3rk

  Permission is hereby granted, free of charge, to any person obtaining a copy
  of this software and associated documentation files (the "Software"), to deal
  in the Software without restriction, including without limitation the rights
  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
  copies of the Software, and to permit persons to whom the Software is
  furnished to do so, subject to the following conditions:

  The above copyright notice and this permission notice shall be included in all
  copies or substantial portions of the Software.

  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
  SOFTWARE.
*/

namespace gpustat\lib;

use JsonException;

/**
 * Class Intel
 * @package gpustat\lib
 */
class Intel extends Main
{
    const CMD_UTILITY = 'intel_gpu_top';
    const INVENTORY_UTILITY = 'lspci';
    const INVENTORY_PARAM = " -Dmm | grep -E 'Display|VGA' ";
    const INVENTORY_REGEX =
        '/VGA.+:\s+Intel\s+Corporation\s+(?P<model>.*)\s+(\[|Family|Integrated|Graphics|Controller|Series|\()/iU';
    const STATISTICS_PARAM = '-J -s 1000 -n 2 -d  pci:slot="';
    const STATISTICS_WRAPPER = 'timeout -k ';
    const QMASSA_CMD = 'qmassa';
    const QMASSA_TIMEOUT = 10; // seconds - increased for GPU initialization
    const QMASSA_FALLBACK_TIMEOUT = 5; // seconds - short timeout for i915 fallback metrics only
    const QMASSA_FALLBACK_INTERVAL_MS = 200;
    
    /**
     * Intel constructor.
     * @param array $settings
     */
    public function __construct(array $settings = [])
    {
        $settings += ['cmd' => self::CMD_UTILITY];
        parent::__construct($settings);
    }

    /**
     * Hide internal collection tools from displayed app/session lists.
     */
    private function isExcludedClientProcess(?string $name): bool
    {
        if ($name === null) {
            return false;
        }

        $processName = strtolower(trim($name));
        return $processName === 'qmassa'
            || strpos($processName, '/qmassa') !== false
            || $processName === 'timeout';
    }

    /**
     * Read Intel dGPU package/card power limits from hwmon and return max limit in watts.
     */
    private function getIntelPowerLimitWatts(string $pciId): ?float
    {
        $candidates = [
            "power1_max",
            "power2_max",
            "power1_cap",
            "power2_cap",
            "power1_crit",
            "power2_crit",
            "power1_rated_max",
            "power2_rated_max"
        ];

        $limits = [];
        foreach ($candidates as $entry) {
            $paths = glob("/sys/bus/pci/devices/$pciId/hwmon/*/$entry");
            if (!$paths) {
                continue;
            }
            foreach ($paths as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $raw = trim((string)@file_get_contents($path));
                if ($raw === '' || !is_numeric($raw)) {
                    continue;
                }
                $value = (float)$raw;
                if ($value > 0) {
                    // hwmon power limits are typically in microwatts.
                    $limits[] = $value / 1000000.0;
                }
            }
        }

        if (empty($limits)) {
            return null;
        }

        return round(max($limits), 1);
    }

    /**
     * Read Intel temperature limit from hwmon and return max in Celsius.
     */
    private function getIntelTempLimitC(string $pciId): ?float
    {
        $candidates = [
            "temp2_crit",
            "temp1_crit",
            "temp2_max",
            "temp1_max"
        ];

        foreach ($candidates as $entry) {
            $paths = glob("/sys/bus/pci/devices/$pciId/hwmon/*/$entry");
            if (!$paths) {
                continue;
            }
            foreach ($paths as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $raw = trim((string)@file_get_contents($path));
                if ($raw === '' || !is_numeric($raw)) {
                    continue;
                }
                $value = (float)$raw;
                if ($value > 0) {
                    return round($value / 1000.0, 1);
                }
            }
        }

        return null;
    }

    private function getIntelFanCacheFile(string $pciId): string
    {
        $safePci = str_replace(':', '_', $pciId);
        return "/tmp/gpustat_fan_cache_{$safePci}.json";
    }

    private function getCachedIntelFanRpm(string $pciId, int $ttlSeconds = 30): ?float
    {
        $cacheFile = $this->getIntelFanCacheFile($pciId);
        if (!is_file($cacheFile)) {
            return null;
        }

        $decoded = json_decode((string)@file_get_contents($cacheFile), true);
        if (!is_array($decoded) || !isset($decoded['rpm'], $decoded['ts'])) {
            return null;
        }

        $rpm = (float)$decoded['rpm'];
        $ts = (int)$decoded['ts'];
        if ($rpm <= 0 || (time() - $ts) > $ttlSeconds) {
            return null;
        }

        return $rpm;
    }

    private function setCachedIntelFanRpm(string $pciId, float $rpm): void
    {
        if ($rpm <= 0) {
            return;
        }

        $cacheFile = $this->getIntelFanCacheFile($pciId);
        @file_put_contents($cacheFile, json_encode([
            'rpm' => $rpm,
            'ts' => time()
        ]));
    }

    private function getIntelPowerReadingCacheFile(string $pciId): string
    {
        $safePci = str_replace(':', '_', $pciId);
        return "/tmp/gpustat_power_reading_cache_{$safePci}.json";
    }

    private function getCachedIntelPowerReading(string $pciId, int $ttlSeconds = 20): ?float
    {
        $cacheFile = $this->getIntelPowerReadingCacheFile($pciId);
        if (!is_file($cacheFile)) {
            return null;
        }

        $decoded = json_decode((string)@file_get_contents($cacheFile), true);
        if (!is_array($decoded) || !isset($decoded['power'], $decoded['ts'])) {
            return null;
        }

        $power = (float)$decoded['power'];
        $ts = (int)$decoded['ts'];
        if ($power <= 0 || (time() - $ts) > $ttlSeconds) {
            return null;
        }

        return $power;
    }

    private function setCachedIntelPowerReading(string $pciId, float $power): void
    {
        if ($power <= 0) {
            return;
        }

        $cacheFile = $this->getIntelPowerReadingCacheFile($pciId);
        @file_put_contents($cacheFile, json_encode([
            'power' => $power,
            'ts' => time()
        ]));
    }

    private function getQmassaSampleCacheFile(string $pciId): string
    {
        $safePci = str_replace(':', '_', $pciId);
        return "/tmp/gpustat_qmassa_sample_cache_{$safePci}.json";
    }

    private function getCachedQmassaSample(string $pciId, int $ttlSeconds = 20): ?array
    {
        $cacheFile = $this->getQmassaSampleCacheFile($pciId);
        if (!is_file($cacheFile)) {
            return null;
        }

        $decoded = json_decode((string)@file_get_contents($cacheFile), true);
        if (!is_array($decoded) || !isset($decoded['sample'], $decoded['ts']) || !is_array($decoded['sample'])) {
            return null;
        }

        $ts = (int)$decoded['ts'];
        if ((time() - $ts) > $ttlSeconds) {
            return null;
        }

        return $decoded['sample'];
    }

    private function setCachedQmassaSample(string $pciId, array $sample): void
    {
        $cacheFile = $this->getQmassaSampleCacheFile($pciId);
        @file_put_contents($cacheFile, json_encode([
            'ts' => time(),
            'sample' => $sample
        ]));
    }

    private function isLikelyTransientZeroSample(array $sample): bool
    {
        $gpuPower = isset($sample['power']['GPU']) && is_numeric($sample['power']['GPU']) ? (float)$sample['power']['GPU'] : 0.0;
        $pkgPower = isset($sample['power']['Package']) && is_numeric($sample['power']['Package']) ? (float)$sample['power']['Package'] : 0.0;
        $temp = isset($sample['temperature']) && is_numeric($sample['temperature']) ? (float)$sample['temperature'] : 0.0;
        $freq = isset($sample['frequency']['actual']) && is_numeric($sample['frequency']['actual']) ? (float)$sample['frequency']['actual'] : 0.0;
        $memUtil = isset($sample['memutil']) && is_numeric($sample['memutil']) ? (float)$sample['memutil'] : 0.0;

        $engineBusy = 0.0;
        if (isset($sample['engines']) && is_array($sample['engines'])) {
            foreach ($sample['engines'] as $engine) {
                if (is_array($engine) && isset($engine['busy']) && is_numeric($engine['busy'])) {
                    $engineBusy += (float)$engine['busy'];
                }
            }
        }

        return $gpuPower <= 0.0 && $pkgPower <= 0.0 && $temp <= 0.0 && $freq <= 0.0 && $memUtil <= 0.0 && $engineBusy <= 0.0;
    }

    /**
     * Run qmassa once and extract fallback metrics for top_qmassa mode.
     */
    private function getQmassaFallbackMetrics(string $pciId): ?array
    {
        $qmassaPath = trim((string)shell_exec('which ' . self::QMASSA_CMD . ' 2>/dev/null'));
        if ($qmassaPath === '') {
            return null;
        }

        $safeFileName = str_replace(':', '_', $pciId);
        $tempJsonFile = "/tmp/gpustat_qmassa_fallback_{$safeFileName}.json";

        // Fast path for i915 fallback: short headless run; two samples avoids first-sample zeros.
        $command = sprintf(
            'timeout %d %s -x -w -m %d -n 2 -t %s -d %s 2>/dev/null',
            self::QMASSA_FALLBACK_TIMEOUT,
            $qmassaPath,
            self::QMASSA_FALLBACK_INTERVAL_MS,
            escapeshellarg($tempJsonFile),
            escapeshellarg($pciId)
        );

        exec($command, $output, $returnCode);
        if ($returnCode !== 0 || !is_file($tempJsonFile)) {
            @unlink($tempJsonFile);
            return null;
        }

        $qmassaLines = file($tempJsonFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        @unlink($tempJsonFile);
        if (!is_array($qmassaLines) || count($qmassaLines) < 3) {
            return null;
        }

        $decoded = null;
        for ($i = count($qmassaLines) - 1; $i >= 0; $i--) {
            $candidate = json_decode((string)$qmassaLines[$i], true);
            if (is_array($candidate) && isset($candidate['devs_state']) && is_array($candidate['devs_state'])) {
                $decoded = $candidate;
                break;
            }
        }
        if (!is_array($decoded) || !isset($decoded['devs_state']) || !is_array($decoded['devs_state'])) {
            return null;
        }

        $deviceData = null;
        foreach ($decoded['devs_state'] as $device) {
            if (isset($device['pci_dev']) && (string)$device['pci_dev'] === $pciId) {
                $deviceData = $device;
                break;
            }
        }
        if (!is_array($deviceData)) {
            return null;
        }

        $devStats = isset($deviceData['dev_stats']) && is_array($deviceData['dev_stats']) ? $deviceData['dev_stats'] : [];

        $powerGpu = 0.0;
        $powerPkg = 0.0;
        if (isset($devStats['power']) && is_array($devStats['power']) && !empty($devStats['power'])) {
            $lastPowerSample = $devStats['power'][count($devStats['power']) - 1];
            if (is_array($lastPowerSample)) {
                $powerGpu = isset($lastPowerSample['gpu_cur_power']) && is_numeric($lastPowerSample['gpu_cur_power'])
                    ? (float)$lastPowerSample['gpu_cur_power']
                    : 0.0;
                $powerPkg = isset($lastPowerSample['pkg_cur_power']) && is_numeric($lastPowerSample['pkg_cur_power'])
                    ? (float)$lastPowerSample['pkg_cur_power']
                    : 0.0;
            }
        }

        $fanRpm = null;
        if (isset($devStats['fans']) && is_array($devStats['fans']) && !empty($devStats['fans'])) {
            $lastFanSample = $devStats['fans'][count($devStats['fans']) - 1];
            $stack = [$lastFanSample];
            while (!empty($stack)) {
                $item = array_pop($stack);
                if (!is_array($item)) {
                    continue;
                }
                if (isset($item['speed']) && is_numeric($item['speed'])) {
                    $fanRpm = max($fanRpm ?? 0.0, (float)$item['speed']);
                }
                foreach ($item as $child) {
                    if (is_array($child)) {
                        $stack[] = $child;
                    }
                }
            }
        }

        $freqRequestedMHz = null;
        $freqActualMHz = null;
        if (isset($devStats['freqs']) && is_array($devStats['freqs']) && !empty($devStats['freqs'])) {
            $lastFreqSample = $devStats['freqs'][count($devStats['freqs']) - 1];
            if (is_array($lastFreqSample) && isset($lastFreqSample[0]) && is_array($lastFreqSample[0])) {
                $freqData = $lastFreqSample[0];
                $freqRequestedMHz = isset($freqData['cur_freq']) && is_numeric($freqData['cur_freq'])
                    ? (float)$freqData['cur_freq']
                    : null;
                $freqActualMHz = isset($freqData['act_freq']) && is_numeric($freqData['act_freq'])
                    ? (float)$freqData['act_freq']
                    : null;
            }
        }

        $memTotalMiB = 0.0;
        $memUsedMiB = 0.0;
        if (isset($devStats['mem_info']) && is_array($devStats['mem_info']) && !empty($devStats['mem_info'])) {
            $lastMemSample = $devStats['mem_info'][count($devStats['mem_info']) - 1];
            if (is_array($lastMemSample)) {
                $vramTotal = isset($lastMemSample['vram_total']) && is_numeric($lastMemSample['vram_total'])
                    ? (float)$lastMemSample['vram_total']
                    : 0.0;
                $vramUsed = isset($lastMemSample['vram_used']) && is_numeric($lastMemSample['vram_used'])
                    ? (float)$lastMemSample['vram_used']
                    : 0.0;
                if ($vramTotal > 0) {
                    $memTotalMiB = $vramTotal / (1024 * 1024);
                    $memUsedMiB = $vramUsed / (1024 * 1024);
                }
            }
        }

        $powerUnit = 'W';

        if ($this->settings['DISPPWRDRWSEL'] == "PACKAGE") {
            $power = $powerPkg;
        } elseif ($this->settings['DISPPWRDRWSEL'] == "GPU") {
            $power = $powerGpu;
        } else {
            $power = max($powerGpu, $powerPkg);
        }

        return [
            'power' => $power,
            'power_unit' => $powerUnit,
            'fan_rpm' => $fanRpm,
            'freq_requested_mhz' => $freqRequestedMHz,
            'freq_actual_mhz' => $freqActualMHz,
            'mem_total_mib' => $memTotalMiB,
            'mem_used_mib' => $memUsedMiB
        ];
    }

    private function getFdinfoCacheFile(string $pciId): string
    {
        $safePci = str_replace(':', '_', $pciId);
        return "/tmp/gpustat_fdinfo_cache_{$safePci}.json";
    }

    private function parseFdinfoLine(string $line): ?array
    {
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);
        if ($key === '') {
            return null;
        }

        return [$key, $value];
    }

    private function parseFdinfoUint(string $value): ?int
    {
        if (preg_match('/^[0-9]+$/', trim($value))) {
            return (int)$value;
        }
        return null;
    }

    private function parseFdinfoKiB(string $value): int
    {
        if (preg_match('/^([0-9]+)\s*(kB|KiB)$/i', trim($value), $m)) {
            return (int)$m[1] * 1024;
        }
        return 0;
    }

    /**
     * Read XE fdinfo counters and compute utilization from cycle deltas (nvtop style).
     *
     * @return array{engines: array, clients: array}
     */
    private function getXeFdinfoUsage(string $pciId): array
    {
        $engineMap = [
            'rcs' => 'Render/3D',
            'bcs' => 'Blitter',
            'vcs' => 'Video',
            'vecs' => 'VideoEnhance',
            'ccs' => 'Compute'
        ];

        $current = [];
        $clients = [];

        $procDirs = glob('/proc/[0-9]*', GLOB_NOSORT) ?: [];
        foreach ($procDirs as $procDir) {
            $pid = (int)basename($procDir);
            if ($pid <= 0) {
                continue;
            }

            $commPath = "$procDir/comm";
            $processName = is_file($commPath) ? trim((string)@file_get_contents($commPath)) : 'unknown';
            if ($this->isExcludedClientProcess($processName)) {
                continue;
            }

            $fdInfos = glob("$procDir/fdinfo/[0-9]*", GLOB_NOSORT) ?: [];
            foreach ($fdInfos as $fdInfoPath) {
                $lines = @file($fdInfoPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (!$lines) {
                    continue;
                }

                $kv = [];
                foreach ($lines as $line) {
                    $parsed = $this->parseFdinfoLine($line);
                    if ($parsed === null) {
                        continue;
                    }
                    [$k, $v] = $parsed;
                    $kv[$k] = $v;
                }

                if (!isset($kv['drm-pdev']) || strcasecmp($kv['drm-pdev'], $pciId) !== 0) {
                    continue;
                }

                $clientId = $kv['drm-client-id'] ?? basename($fdInfoPath);
                $clientKey = $pid . ':' . $clientId;
                if (isset($current[$clientKey])) {
                    continue;
                }

                $entry = [
                    'pid' => $pid,
                    'name' => $processName,
                    'memory' => 0,
                    'engines' => []
                ];

                // xe fdinfo memory key
                if (isset($kv['drm-total-vram0'])) {
                    $entry['memory'] = $this->parseFdinfoKiB($kv['drm-total-vram0']);
                }

                foreach ($engineMap as $suffix => $displayName) {
                    $cyclesKey = "drm-cycles-$suffix";
                    $totalCyclesKey = "drm-total-cycles-$suffix";
                    $cycles = isset($kv[$cyclesKey]) ? $this->parseFdinfoUint($kv[$cyclesKey]) : null;
                    $totalCycles = isset($kv[$totalCyclesKey]) ? $this->parseFdinfoUint($kv[$totalCyclesKey]) : null;

                    if ($cycles !== null && $totalCycles !== null) {
                        $entry['engines'][$displayName] = [
                            'cycles' => $cycles,
                            'total' => $totalCycles
                        ];
                    }
                }

                $current[$clientKey] = $entry;
            }
        }

        $cacheFile = $this->getFdinfoCacheFile($pciId);
        $previous = [];
        if (is_file($cacheFile)) {
            $decoded = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($decoded) && isset($decoded['clients']) && is_array($decoded['clients'])) {
                $previous = $decoded['clients'];
            }
        }

        $engineBusy = [
            'Render/3D' => 0.0,
            'Blitter' => 0.0,
            'Video' => 0.0,
            'VideoEnhance' => 0.0,
            'Compute' => 0.0
        ];

        foreach ($current as $clientKey => $entry) {
            $clientEngines = [];

            foreach ($entry['engines'] as $engineName => $counters) {
                $busy = 0.0;

                if (isset($previous[$clientKey]['engines'][$engineName])) {
                    $prevCounters = $previous[$clientKey]['engines'][$engineName];
                    $deltaCycles = (int)$counters['cycles'] - (int)($prevCounters['cycles'] ?? 0);
                    $deltaTotal = (int)$counters['total'] - (int)($prevCounters['total'] ?? 0);

                    if ($deltaTotal > 0 && $deltaCycles >= 0) {
                        $busy = ($deltaCycles * 100.0) / $deltaTotal;
                        if ($busy < 0) $busy = 0.0;
                        if ($busy > 100) $busy = 100.0;
                    }
                }

                $engineBusy[$engineName] += $busy;
                $clientEngines[$engineName] = ['busy' => $busy];
            }

            $clients[] = [
                'name' => $entry['name'],
                'pid' => $entry['pid'],
                'memory' => [
                    'system' => [
                        'total' => $entry['memory']
                    ]
                ],
                'engine-classes' => $clientEngines
            ];
        }

        // Save latest counters for next refresh interval.
        @file_put_contents($cacheFile, json_encode([
            'timestamp' => microtime(true),
            'clients' => $current
        ]));

        return [
            'engines' => $engineBusy,
            'clients' => $clients
        ];
    }

    /**
     * Retrieves Intel inventory using lspci and returns an array
     *
     * @return array
     */
    public function getInventory(): array
    {
        $result = [];

        // Inventory should still work when intel_gpu_top is unavailable.
        $statsCmdExists = $this->cmdexists;
        $this->checkCommand(self::INVENTORY_UTILITY, false);
        if ($this->cmdexists) {
            $this->runCommand(self::INVENTORY_UTILITY, self::INVENTORY_PARAM, false);
            if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                foreach(explode(PHP_EOL,$this->stdout) AS $vga) {
                    preg_match_all('/"([^"]*)"|(\S+)/', $vga, $matches);
                    if (!isset( $matches[0][0])) continue ;
                    $id = str_replace('"', '', $matches[0][0]) ;
                    $vendor = str_replace('"', '',$matches[0][2]) ;
                    $model = str_replace('"', '',$matches[0][3]) ;
                    if ($vendor != "Intel Corporation") continue ;
                    $result[$id] = [
                        'id' => substr($id,5) ,
                        'model' => $model,
                        'vendor' => 'intel',
                        'guid' => $id
                    ];

                 }
             }
        }

        $this->cmdexists = $statsCmdExists;
        return $result;
    }

    /**
     * Retrieves Intel iGPU statistics
     */
    public function getStatistics()
    {
        $driver = $this->getKernelDriver($this->settings['GPUID']);
        if ($driver == "xe") $driver = "XE";
        if ($driver != "XE" && $driver != "i915") $driver = "i915";
        $intelBackend = isset($this->settings['INTELBACKEND']) ? strtolower((string)$this->settings['INTELBACKEND']) : 'top';
        if ($intelBackend === 'intel_gpu_top') {
            $intelBackend = 'top';
        }
        if ($intelBackend === 'auto') {
            $intelBackend = 'top_qmassa';
        }
        if ($intelBackend !== 'top' && $intelBackend !== 'top_qmassa' && $intelBackend !== 'qmassa') {
            $intelBackend = 'top';
        }
        if (!$this->checkVFIO($this->settings['GPUID']))
        {
            if (($this->cmdexists && $driver == "i915") || $driver =="XE") {
                //Command invokes intel_gpu_top in JSON output mode with an update rate of 5 seconds
                if ($driver != "XE") {
                    if ($intelBackend === 'qmassa') {
                        $this->stdout = $this->buildXEJSONQmassa($this->settings['GPUID']);
                    } else {
                        $command = self::CMD_UTILITY;
                        $this->runCommand($command, self::STATISTICS_PARAM. $this->settings['GPUID'].'"', false);
                    }
                } else {
                    // XE backend is fixed to qmassa.
                    $this->stdout = $this->buildXEJSONQmassa($this->settings['GPUID']);
                    $this->cmdexists = true;
                }
                file_put_contents("/tmp/gpurawdata".$this->settings['GPUID'],json_encode($this->stdout));
                #$this->runCommand("cat ", " /tmp/i915.txt", false); 
                if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                    $this->parseStatistics();
                } else {
                    $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_NOT_RETURNED);
                }
                $this->pageData["vfio"] = false ;
                $this->pageData["vfiochk"] = $this->checkVFIO($this->settings['GPUID']) ;
                $this->pageData["vfiochkid"] = $this->settings['GPUID'] ;
                $this->pageData['vfiovm'] = false;
                $this->pageData['driver'] = $driver;
            } else {
                $this->pageData['error'][] = Error::get(Error::VENDOR_UTILITY_NOT_FOUND);
                $this->pageData["vendor"] = "Intel" ;
                $this->pageData["name"] = $this->settings['GPUID'] ;
                $this->pageData['driver'] = $driver;
            }
        } else {
            $this->pageData["vfio"] = true ;
            $this->pageData["vendor"] = "Intel" ;
            $this->pageData["vfiochk"] = $this->checkVFIO($this->settings['GPUID']) ;
            $this->pageData["vfiochkid"] = $this->settings['GPUID'] ;
            $this->pageData['vfiovm'] = $this->get_gpu_vm($this->settings['PCIID']);
            $this->pageData['driver'] = $driver;
            $gpus = $this->getInventory() ;
            if ($gpus) {
                if (isset($gpus[$this->settings['GPUID']])) {
                    $this->pageData['name'] = $gpus[$this->settings['GPUID']]["model"] ;
                }
            }
        }
        return json_encode($this->pageData) ;    
    }

    /**
     * Loads JSON into array then retrieves and returns specific definitions in an array
     */
    private function parseStatistics()
    {
        // Try to decode as proper JSON array first (for qmassa/buildXEJSON output)
        try {
            $decoded = json_decode($this->stdout, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded) && count($decoded) >= 2) {
                // Valid JSON array with at least 2 elements, use second element
                $data = $decoded[1];
            } else {
                throw new JsonException("Not a valid array with 2+ elements");
            }
        } catch (JsonException $e) {
            // Fallback to old intel_gpu_top string manipulation method
            // JSON output from intel_gpu_top with multiple array indexes isn't properly formatted
            $stdout= str_replace(['[',']'],['',''],$this->stdout);
            $stdout = str_replace('}{', '},{', str_replace(["\n","\t"], '', $stdout));

            try {
                // Split the string into two JSON objects
                $splitJson = preg_split('/\}\s*,\s*\{/m', $stdout);
                // Format the split parts correctly for JSON decoding
                $splitJson[0] .= '}';
                $splitJson[1] = '{' . $splitJson[1];
                $data = json_decode($splitJson[1], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e2) {
                $data = [];
                $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_BAD_PARSE, $e2->getMessage());
            }
        }

        // Need to make sure we have at least two array indexes to take the second one
        $count = count($data);
        if ($count < 1) {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_NOT_ENOUGH, "Count: $count");
        }
        file_put_contents("/tmp/gpudata".$this->settings['GPUID'],json_encode($data));
        #$data=json_decode(file_get_contents("/tmp/jsonin"),true);
        // intel_gpu_top will never show utilization counters on the first sample so we need the second position
        unset($stdout, $this->stdout);

        $currentDriver = $this->getKernelDriver($this->settings['GPUID']);
        if ($currentDriver == "xe") {
            $currentDriver = "XE";
        }
        if ($currentDriver != "XE" && $currentDriver != "i915") {
            $currentDriver = "i915";
        }
        $intelBackend = isset($this->settings['INTELBACKEND']) ? strtolower((string)$this->settings['INTELBACKEND']) : 'top';
        if ($intelBackend === 'intel_gpu_top') {
            $intelBackend = 'top';
        }
        if ($intelBackend === 'auto') {
            $intelBackend = 'top_qmassa';
        }
        if ($intelBackend !== 'top' && $intelBackend !== 'top_qmassa' && $intelBackend !== 'qmassa') {
            $intelBackend = 'top';
        }
        $qmassaFallbackMetrics = null;

        if (!empty($data)) {

            $this->pageData += [
                'vendor'        => 'Intel',
                'name'          => 'iGPU/GPU',
                '3drender'      => 'N/A',
                'blitter'       => 'N/A',
                'interrupts'    => 'N/A',
                'powerutil'     => 'N/A',
                'video'         => 'N/A',
                'videnh'        => 'N/A',
                'compute'       => 0,
                'sessions'      => 0,
            ];
            $gpus = $this->getInventory() ;
            if ($gpus) {
                if (isset($gpus[$this->settings['GPUID']])) {
                    $this->pageData['name'] = $gpus[$this->settings['GPUID']]["model"] ;
                }
            }
            if ($this->settings['DISP3DRENDER']) {
                if (isset($data['engines']['Render/3D/0']['busy'])) {
                    $this->pageData['util'] = $this->pageData['3drender'] = $this->roundFloat($data['engines']['Render/3D/0']['busy']) . '%';
                } elseif (isset($data['engines']['Render/3D']['busy'])) {
                    $this->pageData['util'] = $this->pageData['3drender'] = $this->roundFloat($data['engines']['Render/3D']['busy']) . '%';
                }
            }
            if ($this->settings['DISPBLITTER']) {
                if (isset($data['engines']['Blitter/0']['busy'])) {
                    $this->pageData['blitter'] = $this->roundFloat($data['engines']['Blitter/0']['busy']) . '%';
                } elseif (isset($data['engines']['Blitter']['busy'])) {
                    $this->pageData['blitter'] = $this->roundFloat($data['engines']['Blitter']['busy']) . '%';
                }
            }
            if ($this->settings['DISPVIDEO']) {
                if (isset($data['engines']['Video/0']['busy'])) {
                    $this->pageData['video'] = $this->roundFloat($data['engines']['Video/0']['busy']) . '%';
                } elseif (isset($data['engines']['Video']['busy'])) {
                    $this->pageData['video'] = $this->roundFloat($data['engines']['Video']['busy']) . '%';
                }
            }
            if ($this->settings['DISPVIDENH']) {
                if (isset($data['engines']['VideoEnhance/0']['busy'])) {
                    $this->pageData['videnh'] = $this->roundFloat($data['engines']['VideoEnhance/0']['busy']) . '%';
                } elseif (isset($data['engines']['VideoEnhance']['busy'])) {
                    $this->pageData['videnh'] = $this->roundFloat($data['engines']['VideoEnhance']['busy']) . '%';
                }
            }
            if ($this->settings['DISPPCIUTIL']) {
                if (isset($data['imc-bandwidth']['reads'], $data['imc-bandwidth']['writes'])) {
                    $this->pageData['rxutil'] = $this->roundFloat($data['imc-bandwidth']['reads'], 2) . " MB/s";
                    $this->pageData['txutil'] = $this->roundFloat($data['imc-bandwidth']['writes'], 2) . " MB/s";
                }
            }
   
            if (is_numeric(rtrim($this->pageData['3drender'],"%"))) $max3drenderchk = intval(rtrim($this->pageData['3drender'],"%")); else $max3drenderchk = 0;
            if (is_numeric(rtrim($this->pageData['blitter'],"%"))) $maxblitterchk = intval(rtrim($this->pageData['blitter'],"%")); else $maxblitterchk = 0;
            if (is_numeric(rtrim($this->pageData['video'],"%"))) $maxvideochk = intval(rtrim($this->pageData['video'],"%")); else $maxvideochk = 0;
            if (is_numeric(rtrim($this->pageData['videnh'],"%"))) $maxvidenhchk =intval(rtrim( $this->pageData['videnh'],"%")); else $maxvidenhchk = 0;

            if ($this->settings['DISPPWRDRAW']) {
                // Older versions of intel_gpu_top in case people haven't updated
                if (isset($data['power']['value'])) {
                    $this->pageData['power'] = $this->roundFloat($data['power']['value'], 1) . $data['power']['unit'];
                // Newer version of intel_gpu_top includes GPU and package power readings, just scrape GPU for now
                } else {
                    if (isset($data['power']['Package']) && ($this->settings['DISPPWRDRWSEL'] == "MAX" || $this->settings['DISPPWRDRWSEL'] == "PACKAGE" )) $powerPackage = $this->roundFloat($data['power']['Package'], 1) ; else $powerPackage = 0 ;
                    if (isset($data['power']['GPU']) && ($this->settings['DISPPWRDRWSEL'] == "MAX" || $this->settings['DISPPWRDRWSEL'] == "GPU" )) $powerGPU = $this->roundFloat($data['power']['GPU'], 1) ;  else $powerGPU = 0 ;
                    if (isset($data['power']['unit'])) $powerunit = $data['power']['unit'] ; else $powerunit = "" ;
                    $this->pageData['power'] = max($powerGPU,$powerPackage) . $powerunit ;               
                }

                // Avoid transient 0W flicker by reusing last good reading briefly.
                if (preg_match('/([0-9]+(?:\.[0-9]+)?)/', (string)$this->pageData['power'], $powerMatches)) {
                    $powerValue = (float)$powerMatches[1];
                    if ($powerValue > 0) {
                        $this->setCachedIntelPowerReading($this->settings['GPUID'], $powerValue);
                    } else {
                        $cachedPower = $this->getCachedIntelPowerReading($this->settings['GPUID']);
                        if ($cachedPower !== null) {
                            $powerUnitOut = isset($powerunit) && $powerunit !== '' ? $powerunit : 'W';
                            $this->pageData['power'] = $this->roundFloat($cachedPower, 1) . $powerUnitOut;
                        }
                    }
                }

                // i915 fallback: if power is still zero, query qmassa and use only power reading.
                $powerValueCurrent = 0.0;
                if (preg_match('/([0-9]+(?:\.[0-9]+)?)/', (string)$this->pageData['power'], $powerCurrentMatches)) {
                    $powerValueCurrent = (float)$powerCurrentMatches[1];
                }
                if ($currentDriver == "i915" && $intelBackend == "top_qmassa" && $powerValueCurrent <= 0) {
                    if ($qmassaFallbackMetrics === null) {
                        $qmassaFallbackMetrics = $this->getQmassaFallbackMetrics($this->settings['GPUID']);
                    }
                    if (is_array($qmassaFallbackMetrics) && isset($qmassaFallbackMetrics['power']) && (float)$qmassaFallbackMetrics['power'] > 0) {
                        $powerUnitOut = isset($qmassaFallbackMetrics['power_unit']) && $qmassaFallbackMetrics['power_unit'] !== ''
                            ? $qmassaFallbackMetrics['power_unit']
                            : (isset($powerunit) && $powerunit !== '' ? $powerunit : 'W');
                        $powerFallback = (float)$qmassaFallbackMetrics['power'];
                        $this->pageData['power'] = $this->roundFloat($powerFallback, 1) . $powerUnitOut;
                        $this->setCachedIntelPowerReading($this->settings['GPUID'], $powerFallback);
                    }
                }

                $powerMax = null;
                if (isset($data['power']['max']) && is_numeric($data['power']['max'])) {
                    $powerMax = (float)$data['power']['max'];
                } else {
                    $powerMax = $this->getIntelPowerLimitWatts($this->settings['GPUID']);
                }
                if ($powerMax !== null && $powerMax > 0) {
                    $this->pageData['powermax'] = $powerMax;
                    if (preg_match('/([0-9]+(?:\.[0-9]+)?)/', (string)$this->pageData['power'], $matches)) {
                        $powerCurrent = (float)$matches[1];
                        $powerUtil = ($powerCurrent / $powerMax) * 100;
                        $powerUtil = max(0.0, min(100.0, $powerUtil));
                        $this->pageData['powerutil'] = $this->roundFloat($powerUtil, 1) . "%";
                    }
                }
            }
            if ($this->settings['DISPFAN']) {
                $fanValue = null;
                // Prefer qmassa fan readings whenever qmassa is an enabled backend.
                if ($intelBackend == "qmassa"
                    && isset($data['fan']['rpm'])
                    && is_numeric($data['fan']['rpm'])) {
                    $fanValue = (float)$data['fan']['rpm'];
                    if ($fanValue > 0) {
                        $this->setCachedIntelFanRpm($this->settings['GPUID'], $fanValue);
                    }
                }

                if ($fanValue === null && $intelBackend == "top_qmassa") {
                    if ($qmassaFallbackMetrics === null) {
                        $qmassaFallbackMetrics = $this->getQmassaFallbackMetrics($this->settings['GPUID']);
                    }
                    if (is_array($qmassaFallbackMetrics)
                        && array_key_exists('fan_rpm', $qmassaFallbackMetrics)
                        && $qmassaFallbackMetrics['fan_rpm'] !== null
                        && is_numeric($qmassaFallbackMetrics['fan_rpm'])) {
                        $fanValue = (float)$qmassaFallbackMetrics['fan_rpm'];
                        if ($fanValue > 0) {
                            $this->setCachedIntelFanRpm($this->settings['GPUID'], $fanValue);
                        }
                    }
                }

                if ($fanValue === null) {
                    $path = glob("/sys/bus/pci/devices/{$this->settings['GPUID']}/hwmon/*/fan1_input");
                    if (isset($path[0]) && is_file($path[0])) {
                        $fanReading = $this->readSysfsData($path[0]);
                        if ($fanReading >= 0) {
                            // Some cards legitimately report 0 RPM at idle (fan-stop mode).
                            $fanValue = $fanReading;
                        }
                        if ($fanReading > 0) {
                            $this->setCachedIntelFanRpm($this->settings['GPUID'], $fanReading);
                        } elseif ($fanValue === null) {
                            $cachedFan = $this->getCachedIntelFanRpm($this->settings['GPUID']);
                            if ($cachedFan !== null) {
                                $fanValue = $cachedFan;
                            }
                        }
                    }
                }

                if ($fanValue !== null && $fanValue >= 0) {
                    $this->pageData['fan'] = (int)$this->roundFloat($fanValue);
                } else {
                    $this->pageData['fan'] = 'N/A';
                }
                $this->pageData['fanmax'] = 4000;
            }
            if ($this->settings['DISPTEMP']) {
                if (isset($data['temperature']) && is_numeric($data['temperature'])) {
                    $this->pageData['temp'] = $this->roundFloat($data['temperature'], 1) . "C";
                } else {
                    $path = glob("/sys/bus/pci/devices/{$this->settings['GPUID']}/hwmon/*/temp2_input");
                    if (!isset($path[0]) || !is_file($path[0])) {
                        $path = glob("/sys/bus/pci/devices/{$this->settings['GPUID']}/hwmon/*/temp1_input");
                    }
                    if (isset($path[0]) && is_file($path[0])) {
                        $this->pageData['temp'] = $this->readSysfsData($path[0]);
                        $this->pageData['temp'] = $this->pageData['temp'] / 1000 . "C";
                    }
                }

                $tempLimit = $this->getIntelTempLimitC($this->settings['GPUID']);
                if ($tempLimit !== null) {
                    $this->pageData['tempmax'] = $tempLimit . "C";
                }

                if ($this->settings['TEMPFORMAT'] == 'F') {
                    foreach (['temp', 'tempmax'] as $key) {
                        if (isset($this->pageData[$key]) && $this->pageData[$key] !== 'N/A') {
                            $this->pageData[$key] = $this->convertCelsius((int) $this->stripText('C', $this->pageData[$key])) . 'F';
                        }
                    }
                }
            }
            // According to the sparse documentation, rc6 is a percentage of how little the GPU is requesting power
            if ($this->settings['DISPPWRSTATE']) {
                if (isset($data['rc6']['value'])) {
                    if ((!$this->settings['DISPPWRDRAW']) && (!isset($this->pageData['powerutil']) || $this->pageData['powerutil'] === 'N/A')) {
                        $this->pageData['powerutil'] = $this->roundFloat(100 - $data['rc6']['value'], 2) . "%";
                    }
                    if (isset($powerGPU) && $powerGPU == 0 && $this->pageData['powerutil'] != 0) $this->pageData['powerutil'] = "0%";
                }
            }

            if ($this->settings['DISPMEMUTIL']) {
                $memTotal = (isset($data['memtotal']) && is_numeric($data['memtotal'])) ? (float)$data['memtotal'] : null;
                $memUsed = null;
                if (isset($data['memusedmb']) && is_numeric($data['memusedmb'])) {
                    $memUsed = (float)$data['memusedmb'];
                } elseif (isset($data['memused']) && is_numeric($data['memused'])) {
                    $memUsed = (float)$data['memused'];
                }

                // i915 fallback: if memory is missing/zero, query qmassa and use only memory values.
                if ($currentDriver == "i915" && $intelBackend == "top_qmassa" && ($memTotal === null || $memTotal <= 0 || $memUsed === null || $memUsed <= 0)) {
                    if ($qmassaFallbackMetrics === null) {
                        $qmassaFallbackMetrics = $this->getQmassaFallbackMetrics($this->settings['GPUID']);
                    }
                    if (is_array($qmassaFallbackMetrics) && isset($qmassaFallbackMetrics['mem_total_mib']) && (float)$qmassaFallbackMetrics['mem_total_mib'] > 0) {
                        $memTotal = (float)$qmassaFallbackMetrics['mem_total_mib'];
                        $memUsed = isset($qmassaFallbackMetrics['mem_used_mib']) && is_numeric($qmassaFallbackMetrics['mem_used_mib'])
                            ? max(0.0, (float)$qmassaFallbackMetrics['mem_used_mib'])
                            : 0.0;
                    }
                }

                if ($memTotal !== null && $memUsed !== null && $memTotal > 0) {
                    $memUtilPercent = $this->roundFloat(($memUsed / $memTotal) * 100, 1) . "%";
                    $this->pageData['memutil'] = $memUtilPercent;
                    // Keep legacy UI bindings (gpu-memused*) showing percent instead of MiB.
                    $this->pageData['memused'] = $memUtilPercent;
                    $this->pageData['memusedmb'] = $this->roundFloat($memUsed, 1);
                    $this->pageData['memtotal'] = $this->roundFloat($memTotal, 1);
                }
            }
            if ($this->settings['DISPCLOCKS']) {
                // In top_qmassa mode, prefer qmassa frequency over intel_gpu_top frequency.
                if ($intelBackend == "top_qmassa") {
                    if ($qmassaFallbackMetrics === null) {
                        $qmassaFallbackMetrics = $this->getQmassaFallbackMetrics($this->settings['GPUID']);
                    }
                    if (is_array($qmassaFallbackMetrics)
                        && isset($qmassaFallbackMetrics['freq_actual_mhz'])
                        && is_numeric($qmassaFallbackMetrics['freq_actual_mhz'])) {
                        $this->pageData['clock'] = (int) $this->roundFloat((float)$qmassaFallbackMetrics['freq_actual_mhz']);
                    } elseif (isset($data['frequency']['actual'])) {
                        $this->pageData['clock'] = (int) $this->roundFloat($data['frequency']['actual']);
                    }
                } elseif (isset($data['frequency']['actual'])) {
                    $this->pageData['clock'] = (int) $this->roundFloat($data['frequency']['actual']);
                }
            }
            if ($this->settings['DISPINTERRUPT']) {
                if (isset($data['interrupts']['count'])) {
                    $this->pageData['interrupts'] = (int) $this->roundFloat($data['interrupts']['count']);
                }
            }
            if ($this->settings['DISPSESSIONS']) {
            $this->pageData['active_apps'] = [];
                if (isset($data['clients']) && count($data['clients']) > 0) {
                    $this->pageData['sessions'] = count($data['clients']);
                    if ($this->pageData['sessions'] > 0) {
                        $clientRender = $clientBlitter = $clientVideo = $clientVideoEnh = $clientCompute = 0 ;
                        foreach ($data['clients'] as $id => $process) {
                            if (isset($process["name"])) {
                                if ($this->isExcludedClientProcess($process["name"])) {
                                    continue;
                                }
                                $process_array = [
                                    "pid" => $process["pid"],
                                    "name" => $process["name"],
                                    "memory" => $process["memory"]['system']['total']/(1024*1024),
                                ];
                                $this->detectApplication($process_array);
                                if (isset($process['engine-classes']['Render/3D']['busy'])) $clientRender += $process['engine-classes']['Render/3D']['busy'];
                                if (isset($process['engine-classes']['Blitter']['busy'])) $clientBlitter += $process['engine-classes']['Blitter']['busy'];
                                if (isset($process['engine-classes']['Video']['busy'])) $clientVideo += $process['engine-classes']['Video']['busy'];
                                if (isset($process['engine-classes']['VideoEnhance']['busy'])) $clientVideoEnh += $process['engine-classes']['VideoEnhance']['busy'];
                                if (isset($process['engine-classes']['Compute']['busy'])) $clientCompute += $process['engine-classes']['Compute']['busy'];
                            }
                        }
                        $maxcomputechk = 0;
                        if ($max3drenderchk == 0) $this->pageData['3drender'] = $this->roundFloat($clientRender) . '%';
                        if ($maxblitterchk == 0) $this->pageData['blitter'] = $this->roundFloat($clientBlitter) . '%';
                        if ($maxvideochk == 0) $this->pageData['video'] = $this->roundFloat($clientVideo) . '%';
                        if ($maxvidenhchk == 0) $this->pageData['videnh'] = $this->roundFloat($clientVideoEnh) . '%';
                        if ($maxcomputechk == 0) $this->pageData['compute'] = $this->roundFloat($clientCompute) . '%';
                    }
                }
            }
            if (is_numeric(rtrim($this->pageData['3drender'],"%"))) $max3drenderchk = intval(rtrim($this->pageData['3drender'],"%")); else $max3drenderchk = 0;
            if (is_numeric(rtrim($this->pageData['blitter'],"%"))) $maxblitterchk = intval(rtrim($this->pageData['blitter'],"%")); else $maxblitterchk = 0;
            if (is_numeric(rtrim($this->pageData['video'],"%"))) $maxvideochk = intval(rtrim($this->pageData['video'],"%")); else $maxvideochk = 0;
            if (is_numeric(rtrim($this->pageData['videnh'],"%"))) $maxvidenhchk =intval(rtrim( $this->pageData['videnh'],"%")); else $maxvidenhchk = 0;
            if (is_numeric(rtrim($this->pageData['compute'],"%"))) $maxcomputechk =intval(rtrim( $this->pageData['compute'],"%")); else $maxcomputechk = 0;

            $maxload = (max($max3drenderchk ,$maxblitterchk, $maxvideochk, $maxvidenhchk, $maxcomputechk));
            $this->pageData['util'] = $maxload.'%';
            
            $this->getPCIeBandwidth($this->settings['GPUID']);
        } else {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_BAD_PARSE);
        }
      }

          // Function to read the sysfs file and return the data as float or 0 if the file doesn't exist
          private function readSysfsData($path)
          {
              if (file_exists($path)) {
                  $value = file_get_contents($path);
                  return is_numeric($value) ? (float)trim($value) : 0.0;
              }
              return 0.0;
          }
      
          // Function to find the sysfs path using the PCI ID
          private function getSysfsPathFromPciId($pciId)
          {
              $basePath = '/sys/class/drm/';
              $gpuDirs = scandir($basePath);
              
              foreach ($gpuDirs as $dir) {
                  if ($dir === '.' || $dir === '..') {
                      continue;
                  }
      
                  // Check if this directory matches the PCI ID
                  $pciPath = $basePath . $dir . '/device';
                  if (file_exists($pciPath)) {
                      $deviceId = trim(file_get_contents($pciPath . '/vendor'));
                      if ($deviceId === $pciId) {
                          return $basePath . $dir; // Return path if PCI ID matches
                      }
                  }
              }
              
              return null; // Return null if no matching PCI ID found
          }
      
          /**
           * Find DRI card number from PCI ID
           * Maps PCI ID like "0000:04:00.0" to card number like "0" or "1"
           * 
           * @param string $pciId PCI ID (e.g., "0000:04:00.0")
           * @return int|null Card number or null if not found
           */
          private function getDRICardNumber(string $pciId): ?int
          {
              $basePath = '/sys/class/drm';
              if (!is_dir($basePath)) {
                  return null;
              }
              
              $cards = glob("$basePath/card*");
              foreach ($cards as $cardPath) {
                  // Read the device symlink to get actual PCI path
                  $deviceLink = "$cardPath/device";
                  if (is_link($deviceLink)) {
                      $devicePath = readlink($deviceLink);
                      // Extract PCI ID from path like "../../../0000:04:00.0"
                      if (preg_match('/([0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-9])$/i', $devicePath, $matches)) {
                          if (strcasecmp($matches[1], $pciId) === 0) {
                              // Extract card number from path
                              if (preg_match('/card(\d+)$/', basename($cardPath), $cardMatches)) {
                                  return (int)$cardMatches[1];
                              }
                          }
                      }
                  }
              }
              
              return null;
          }
      
    // Function to generate the JSON from sysfs data based on PCI ID
    // This is a fallback method for XE driver when qmassa is not available
    protected function buildXEJSON(string $pciId): string
    {
      
                      // Construct the sysfs path based on the supplied PCI ID
        $basePath = "/sys/bus/pci/devices/$pciId";
        
        // Ensure the path exists
        if (!file_exists($basePath)) {
            return json_encode(['error' => 'Invalid PCI ID or GPU not found']);
        }

        // Set paths for sysfs data based on the PCI ID
        $freqPath = "$basePath/gt/gt0/rps_act_freq_mhz"; // Actual frequency (MHz)
        $freqReqPath = "$basePath/gt/gt0/rps_req_freq_mhz"; // Requested frequency (MHz)
        #$powerPath = "$basePath/hwmon/hwmon*/power1_input"; // Power usage (uW), needs conversion to W
        #$rc6Path = "$basePath/gt/gt0/rc6_residency_ms"; // RC6 residency in ms
        #$interruptPath = "$basePath/msi_irqs"; // IRQ count (if available)

        // Collect necessary data from sysfs
        $duration = 1000.0; // Default duration in ms
        #$frequencyRequested = $this->readSysfsData($freqReqPath);
        ##$frequencyActual = $this->readSysfsData($freqPath);
        #$interruptsCount = $this->readSysfsData($interruptPath);
        #$rc6Value = $this->readSysfsData($rc6Path) / 10.0; // Convert to percentage if needed
        #$powerGpu = $this->readSysfsData($powerPath) / 1e6; // Convert µW to W
        #$powerPackage = $powerGpu * 0.8; // Approximate package power
        $frequencyRequested = null;
        $frequencyActual = null;
        $interruptsCount = null;
        $rc6Value = 100; // Convert to percentage if needed
        $powerGpu = null; // Convert µW to W
        $powerPackage = null; // Approximate package power
        $clients = null; // Initialize clients array

        $clientsPath = "/sys/kernel/debug/dri/$pciId/clients";

        if (file_exists($clientsPath)) {
            $lines = file($clientsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            #$lines[] = "command  tgid dev master a   uid      magic";
            #$lines[] = "                Xorg  5226   1   y    y     0          0";
            #$lines[] = "                qemu  5227   1   y    y     0          0";
            
            array_shift($lines); // Remove the header row
            if ($lines) {
                foreach ($lines as $line) {
                    $columns = preg_split('/\s+/', trim($line));
                    if (count($columns) >= 6) {
                        list($command, $tgid, $dev, $master, $a, $uid) = $columns;
                        if ($this->isExcludedClientProcess($command)) {
                            continue;
                        }
                        $totalmem['system']['total']= 0;
                        $clients[$tgid] = [
                            "name" => $command,
                            "pid" => $tgid,
                            "gpu_instance_id" => "N/A",
                            "compute_instance_id" => "N/A",
                            "type" => "C",
                            "used_memory" => "N/A",
                            "memory" => $totalmem
                        ];
                    }
                }
            } else $clients = null;
        }

        // Build the JSON structure
        $jsonOutput = [
            "period" => [
                "duration" => $duration,
                "unit" => "ms"
            ],
            "frequency" => [
                "requested" => $frequencyRequested,
                "actual" => $frequencyActual,
                "unit" => "MHz"
            ],
            "interrupts" => [
                "count" => $interruptsCount,
                "unit" => "irq/s"
            ],
            "rc6" => [
                "value" => $rc6Value,
                "unit" => "%"
            ],
            "power" => [
                "GPU" => $powerGpu,
                "Package" => $powerPackage,
                "unit" => "W"
            ],
            "engines" => [
                "Render/3D" => [
                    "busy" => 0.0, // Placeholder, requires actual path
                    "sema" => 0.0,
                    "wait" => 0.0,
                    "unit" => "%"
                ],
                "Blitter" => [
                    "busy" => 0.0,
                    "sema" => 0.0,
                    "wait" => 0.0,
                    "unit" => "%"
                ],
                "Video" => [
                    "busy" => 0.0,
                    "sema" => 0.0,
                    "wait" => 0.0,
                    "unit" => "%"
                ],
                "VideoEnhance" => [
                    "busy" => 0.0,
                    "sema" => 0.0,
                    "wait" => 0.0,
                    "unit" => "%"
                ]
            ],
            "clients" => $clients // Extend with real client data if available
        ];
        $returnjson[] = $jsonOutput;
        $returnjson[] = $jsonOutput;
        $return = json_encode($returnjson, JSON_PRETTY_PRINT);
        file_put_contents("/tmp/inteljson",$return);
        return $return;
    }

    /**
     * Function to generate JSON from qmassa for XE driver
     * Uses qmassa (https://github.com/ulissesf/qmassa) for comprehensive XE GPU statistics
     * 
     * qmassa outputs JSONL format with multiple samples
     * Each sample has 3 lines: version, config, data
     * With -n 2 we get 6 lines total, using the last sample for activity data
     * 
     * @param string $pciId PCI ID of the GPU (e.g., "0000:00:02.0")
     * @return string JSON formatted string compatible with intel_gpu_top output
     */
    protected function buildXEJSONQmassa(string $pciId): string
    {
        // Check if qmassa is available
        $qmassaPath = trim(shell_exec('which ' . self::QMASSA_CMD . ' 2>/dev/null'));
        
        // Debug: log qmassa path
        error_log("gpustat: qmassa path lookup result: " . ($qmassaPath ?: 'NOT FOUND'));
        
        if (empty($qmassaPath)) {
            // Fallback to sysfs method if qmassa not found
            error_log("gpustat: qmassa binary not found in PATH, falling back to sysfs");
            return $this->buildXEJSON($pciId);
        }
        
        // Temporary file for qmassa JSON output (replace colons to avoid filesystem issues)
        $safeFileName = str_replace(':', '_', $pciId);
        $tempJsonFile = "/tmp/gpustat_qmassa_{$safeFileName}.json";
        
        // Build qmassa command
        // -x: no TUI (headless mode)
        // -w: show all sensors
        // -a: show all clients (not just active ones)
        // -t: output JSON to file
        // -d: specific device by PCI ID
        // -n 2: TWO iterations to get activity data (like intel_gpu_top)
        // -m 1000: 1000ms interval between samples
        $command = sprintf(
            'timeout %d %s -wxa -m 1000 -n 2 -t %s -d %s 2>&1',
            self::QMASSA_TIMEOUT,
            $qmassaPath, // Use full path
            escapeshellarg($tempJsonFile),
            escapeshellarg($pciId)
        );
        
        // Debug: log the command being executed
        error_log("gpustat: Executing qmassa command: $command");
        
        // Execute qmassa
        exec($command, $output, $returnCode);
        
        // Debug: log execution results
        error_log("gpustat: qmassa return code: $returnCode, output lines: " . count($output));
        if ($returnCode !== 0) {
            error_log("gpustat: qmassa stderr: " . implode("\n", $output));
        }
        
        // Check if qmassa executed successfully
        if ($returnCode !== 0 || !file_exists($tempJsonFile)) {
            // Log error and fallback
            error_log("gpustat: qmassa failed for $pciId (code: $returnCode), file exists: " . (file_exists($tempJsonFile) ? 'yes' : 'no') . ", falling back to sysfs");
            @unlink($tempJsonFile);
            $cachedSample = $this->getCachedQmassaSample($pciId);
            if ($cachedSample !== null) {
                error_log("gpustat: Using cached qmassa sample for $pciId after qmassa execution failure");
                return json_encode([$cachedSample, $cachedSample], JSON_PRETTY_PRINT);
            }
            return $this->buildXEJSON($pciId);
        }
        
        // Read qmassa JSONL output
        // With -n 2: 6 lines total (3 per sample: version, config, data)
        // We want the LAST sample (line 6) which has accumulated activity data
        $qmassaLines = file($tempJsonFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        // Keep raw qmassa output for debugging (not deleting temp file)
        
        // Debug: log line count
        error_log("gpustat: qmassa output has " . count($qmassaLines) . " lines");
        
        if (empty($qmassaLines) || count($qmassaLines) < 3) {
            error_log("gpustat: qmassa returned insufficient output for $pciId (expected at least 3 lines, got " . count($qmassaLines) . ")");
            $cachedSample = $this->getCachedQmassaSample($pciId);
            if ($cachedSample !== null) {
                error_log("gpustat: Using cached qmassa sample for $pciId after insufficient qmassa output");
                return json_encode([$cachedSample, $cachedSample], JSON_PRETTY_PRINT);
            }
            return $this->buildXEJSON($pciId);
        }
        
        try {
            // Parse the LAST line (most recent sample with activity data)
            // With -n 2: lines are [0]=v1, [1]=cfg1, [2]=data1, [3]=v2, [4]=cfg2, [5]=data2
            // We want data2 (last line)
            $lastLine = $qmassaLines[count($qmassaLines) - 1];
            $qmassaData = json_decode($lastLine, true, 512, JSON_THROW_ON_ERROR);
            
            // Extract device data from devs_state array
            if (!isset($qmassaData['devs_state']) || empty($qmassaData['devs_state'])) {
                throw new JsonException("No devs_state in qmassa output");
            }
            
            // Find our device in the array
            $deviceData = null;
            foreach ($qmassaData['devs_state'] as $device) {
                if (isset($device['pci_dev']) && $device['pci_dev'] === $pciId) {
                    $deviceData = $device;
                    break;
                }
            }
            
            if (!$deviceData) {
                throw new JsonException("Device $pciId not found in qmassa devs_state");
            }
            
            // Debug: log device data structure
            $clientCount = isset($deviceData['clis_stats']) ? count($deviceData['clis_stats']) : 0;
            error_log("gpustat: Device $pciId has $clientCount clients in qmassa output");
            
            // Transform qmassa output to intel_gpu_top compatible format
            $jsonOutput = $this->transformQmassaToIntelFormat($deviceData, $pciId);

            // qmassa can intermittently emit empty client lists on XE; preserve recent clients/icons.
            if (empty($jsonOutput['clients'])) {
                $cachedSample = $this->getCachedQmassaSample($pciId);
                if ($cachedSample !== null && isset($cachedSample['clients']) && is_array($cachedSample['clients']) && !empty($cachedSample['clients'])) {
                    $jsonOutput['clients'] = $cachedSample['clients'];
                    error_log("gpustat: Reused cached qmassa clients for $pciId due to empty client sample");
                }
            }

            // qmassa occasionally yields transient all-zero samples on refresh.
            // Reuse the last good sample briefly to avoid flashing zeros.
            if ($this->isLikelyTransientZeroSample($jsonOutput)) {
                $cachedSample = $this->getCachedQmassaSample($pciId);
                if ($cachedSample !== null) {
                    $jsonOutput = $cachedSample;
                    error_log("gpustat: Using cached qmassa sample for $pciId due to transient zero sample");
                }
            } else {
                $this->setCachedQmassaSample($pciId, $jsonOutput);
            }
            
            // intel_gpu_top returns array with two samples, duplicate for compatibility
            $returnjson[] = $jsonOutput;
            $returnjson[] = $jsonOutput;
            
            $return = json_encode($returnjson, JSON_PRETTY_PRINT);
            $safeFileName = str_replace(':', '_', $pciId);
            file_put_contents("/tmp/inteljson_qmassa_{$safeFileName}", $return);
            
            return $return;
            
        } catch (JsonException $e) {
            error_log("gpustat: Failed to parse qmassa JSON for $pciId: " . $e->getMessage());
            $cachedSample = $this->getCachedQmassaSample($pciId);
            if ($cachedSample !== null) {
                error_log("gpustat: Using cached qmassa sample for $pciId after parse failure");
                return json_encode([$cachedSample, $cachedSample], JSON_PRETTY_PRINT);
            }
            return $this->buildXEJSON($pciId);
        }
    }
    
    /**
     * Transform qmassa JSON format to intel_gpu_top compatible format
     * 
     * qmassa structure: devs_state[].dev_stats contains metrics
     * - freqs: array of arrays [[{gt0}, {gt1}]]
     * - power: array [{gpu_cur_power, pkg_cur_power}]
     * - temps: array of arrays [[{name, temp}]]
     * - fans: array of arrays [[{name, speed}]]
     * - eng_usage: object with engine names as keys
     * 
     * @param array $qmassaData Parsed qmassa device data from devs_state
     * @param string $pciId PCI ID for reference
     * @return array Formatted data compatible with parseStatistics()
     */
    private function transformQmassaToIntelFormat(array $qmassaData, string $pciId): array
    {
        $output = [
            "period" => [
                "duration" => 1000.0,
                "unit" => "ms"
            ],
            "frequency" => [
                "requested" => null,
                "actual" => null,
                "unit" => "MHz"
            ],
            "interrupts" => [
                "count" => null,
                "unit" => "irq/s"
            ],
            "rc6" => [
                "value" => 0.0,
                "unit" => "%"
            ],
            "power" => [
                "GPU" => null,
                "Package" => null,
                "unit" => "W"
            ],
            "engines" => [
                "Render/3D" => ["busy" => 0.0, "sema" => 0.0, "wait" => 0.0, "unit" => "%"],
                "Blitter" => ["busy" => 0.0, "sema" => 0.0, "wait" => 0.0, "unit" => "%"],
                "Video" => ["busy" => 0.0, "sema" => 0.0, "wait" => 0.0, "unit" => "%"],
                "VideoEnhance" => ["busy" => 0.0, "sema" => 0.0, "wait" => 0.0, "unit" => "%"]
            ],
            "clients" => [],
            "imc-bandwidth" => [
                "reads" => 0.0,
                "writes" => 0.0,
                "unit" => "MB/s"
            ]
        ];
        
        // Extract dev_stats where the actual metrics are
        $devStats = $qmassaData['dev_stats'] ?? [];
        
        // Map frequency data - freqs is array of samples: [[{gt0}, {gt1}], [{gt0}, {gt1}]]
        // Use LAST sample which has the most recent data
        if (isset($devStats['freqs']) && is_array($devStats['freqs']) && !empty($devStats['freqs'])) {
            $lastFreqSample = $devStats['freqs'][count($devStats['freqs']) - 1];
            if (isset($lastFreqSample[0])) {
                // Use first GT (gt0) frequency data
                $freqData = $lastFreqSample[0];
                $output['frequency']['requested'] = $freqData['cur_freq'] ?? null;
                $output['frequency']['actual'] = $freqData['act_freq'] ?? null;
            }
        }
        
        // Map power data - power is array of samples: [{sample1}, {sample2}]
        // Use LAST sample which has accumulated activity data
        if (isset($devStats['power']) && is_array($devStats['power']) && !empty($devStats['power'])) {
            $lastPowerSample = $devStats['power'][count($devStats['power']) - 1];
            $output['power']['GPU'] = $lastPowerSample['gpu_cur_power'] ?? null;
            $output['power']['Package'] = $lastPowerSample['pkg_cur_power'] ?? null;
        }
        
        // Map temperature data - temps is array of samples: [[[{pkg}, {vram}]], [[{pkg}, {vram}]]]
        // Use LAST sample which has the most recent temperature
        if (isset($devStats['temps']) && is_array($devStats['temps']) && !empty($devStats['temps'])) {
            $lastTempSample = $devStats['temps'][count($devStats['temps']) - 1];
            if (isset($lastTempSample[0])) {
                // Find package temperature
                foreach ($lastTempSample[0] as $tempSensor) {
                    if (isset($tempSensor['name']) && $tempSensor['name'] === 'pkg' && isset($tempSensor['temp'])) {
                        $output['temperature'] = $tempSensor['temp'];
                        break;
                    }
                }
            }
        }

        // Map fan speed data - fans is array of samples with nested fan sensors.
        if (isset($devStats['fans']) && is_array($devStats['fans']) && !empty($devStats['fans'])) {
            $lastFanSample = $devStats['fans'][count($devStats['fans']) - 1];
            $fanRpm = 0.0;
            $stack = [$lastFanSample];
            while (!empty($stack)) {
                $item = array_pop($stack);
                if (!is_array($item)) {
                    continue;
                }
                if (isset($item['speed']) && is_numeric($item['speed'])) {
                    $fanRpm = max($fanRpm, (float)$item['speed']);
                }
                foreach ($item as $child) {
                    if (is_array($child)) {
                        $stack[] = $child;
                    }
                }
            }
            if ($fanRpm > 0) {
                $output['fan'] = [
                    'rpm' => $fanRpm,
                    'unit' => 'RPM'
                ];
            }
        }

        // Map memory info (bytes) to MiB for dashboard usage bar.
        if (isset($devStats['mem_info']) && is_array($devStats['mem_info']) && !empty($devStats['mem_info'])) {
            $lastMemSample = $devStats['mem_info'][count($devStats['mem_info']) - 1];
            $vramTotal = isset($lastMemSample['vram_total']) ? (float)$lastMemSample['vram_total'] : 0.0;
            $vramUsed = isset($lastMemSample['vram_used']) ? (float)$lastMemSample['vram_used'] : 0.0;
            if ($vramTotal > 0) {
                $output['memtotal'] = $vramTotal / (1024 * 1024);
                $output['memused'] = $vramUsed / (1024 * 1024);
                $output['memusedmb'] = $output['memused'];
                $output['memutil'] = ($vramUsed / $vramTotal) * 100.0;
            }
        }
        
        // Map engine usage - eng_usage is object with engine names as keys
        if (isset($devStats['eng_usage']) && is_array($devStats['eng_usage'])) {
            foreach ($devStats['eng_usage'] as $engineName => $engineData) {
                $busy = is_array($engineData) ? ($engineData['busy'] ?? 0.0) : 0.0;
                
                // Map qmassa engine names to intel_gpu_top names
                $engineLower = strtolower($engineName);
                if (strpos($engineLower, 'render') !== false || strpos($engineLower, '3d') !== false || strpos($engineLower, 'rcs') !== false) {
                    $output['engines']['Render/3D']['busy'] = $busy;
                } elseif (strpos($engineLower, 'blitter') !== false || strpos($engineLower, 'blt') !== false || strpos($engineLower, 'bcs') !== false) {
                    $output['engines']['Blitter']['busy'] = $busy;
                } elseif (strpos($engineLower, 'video') !== false && strpos($engineLower, 'enhance') === false && strpos($engineLower, 'vecs') === false) {
                    $output['engines']['Video']['busy'] = $busy;
                } elseif (strpos($engineLower, 'videoenhance') !== false || strpos($engineLower, 'vebox') !== false || strpos($engineLower, 'vecs') !== false) {
                    $output['engines']['VideoEnhance']['busy'] = $busy;
                }
            }
        }

        $hasQmassaEngineUsage =
            $output['engines']['Render/3D']['busy'] > 0 ||
            $output['engines']['Blitter']['busy'] > 0 ||
            $output['engines']['Video']['busy'] > 0 ||
            $output['engines']['VideoEnhance']['busy'] > 0;

        $fdinfoUsage = null;
        if (!$hasQmassaEngineUsage) {
            $fdinfoUsage = $this->getXeFdinfoUsage($pciId);
            foreach ($fdinfoUsage['engines'] as $engineName => $busy) {
                if (isset($output['engines'][$engineName])) {
                    $output['engines'][$engineName]['busy'] = $busy;
                }
            }
            if (isset($fdinfoUsage['engines']['Compute'])) {
                // Keep compatibility with the plugin's explicit Compute row.
                $output['engines']['Compute'] = [
                    'busy' => $fdinfoUsage['engines']['Compute'],
                    'sema' => 0.0,
                    'wait' => 0.0,
                    'unit' => '%'
                ];
            }
        }
        
        // Map DRM clients from clis_stats
        if (isset($qmassaData['clis_stats']) && is_array($qmassaData['clis_stats'])) {
            // Debug: log client count
            error_log("gpustat: Found " . count($qmassaData['clis_stats']) . " DRM clients for $pciId");
            
            foreach ($qmassaData['clis_stats'] as $client) {
                $clientName = $client['comm'] ?? ($client['command'] ?? 'unknown');
                if ($this->isExcludedClientProcess($clientName)) {
                    continue;
                }

                $clientEntry = [
                    "name" => $clientName,
                    "pid" => $client['pid'] ?? 0,
                    "memory" => [
                        "system" => [
                            "total" => ($client['smem_rss'] ?? 0) // Already in bytes
                        ]
                    ],
                    "engine-classes" => []
                ];
                
                // Debug: log client details
                error_log("gpustat: Client {$clientEntry['name']} (PID {$clientEntry['pid']})");
                
                // Map client engine usage
                if (isset($client['eng_usage']) && is_array($client['eng_usage'])) {
                    foreach ($client['eng_usage'] as $engineName => $engineData) {
                        $busy = is_array($engineData) ? ($engineData['busy'] ?? 0.0) : 0.0;
                        
                        $engineLower = strtolower($engineName);
                        if (strpos($engineLower, 'render') !== false || strpos($engineLower, '3d') !== false || strpos($engineLower, 'rcs') !== false) {
                            $clientEntry['engine-classes']['Render/3D'] = ['busy' => $busy];
                        } elseif (strpos($engineLower, 'blitter') !== false || strpos($engineLower, 'bcs') !== false) {
                            $clientEntry['engine-classes']['Blitter'] = ['busy' => $busy];
                        } elseif (strpos($engineLower, 'video') !== false && strpos($engineLower, 'enhance') === false && strpos($engineLower, 'vecs') === false) {
                            $clientEntry['engine-classes']['Video'] = ['busy' => $busy];
                        } elseif (strpos($engineLower, 'videoenhance') !== false || strpos($engineLower, 'vebox') !== false || strpos($engineLower, 'vecs') !== false) {
                            $clientEntry['engine-classes']['VideoEnhance'] = ['busy' => $busy];
                        } elseif (strpos($engineLower, 'compute') !== false || strpos($engineLower, 'ccs') !== false) {
                            $clientEntry['engine-classes']['Compute'] = ['busy' => $busy];
                        }
                    }
                }
                
                $output['clients'][] = $clientEntry;
            }
        }
        
        // Fallback to sysfs for client detection if qmassa didn't provide any
        // (qmassa may not support client tracking on XE driver yet)
        if (empty($output['clients'])) {
            if ($fdinfoUsage === null) {
                $fdinfoUsage = $this->getXeFdinfoUsage($pciId);
            }
            if (!empty($fdinfoUsage['clients'])) {
                $output['clients'] = $fdinfoUsage['clients'];
                error_log("gpustat: Found " . count($output['clients']) . " clients from fdinfo fallback");
            }
        }

        if (empty($output['clients'])) {
            error_log("gpustat: qmassa returned no clients, falling back to sysfs for client detection");
            $clientsPath = "/sys/kernel/debug/dri/$pciId/clients";
            if (file_exists($clientsPath)) {
                $lines = file($clientsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines && count($lines) > 1) {
                    array_shift($lines); // Remove header
                    foreach ($lines as $line) {
                        $columns = preg_split('/\s+/', trim($line));
                        if (count($columns) >= 2) {
                            if ($this->isExcludedClientProcess($columns[0])) {
                                continue;
                            }
                            $output['clients'][] = [
                                "name" => $columns[0],
                                "pid" => $columns[1],
                                "memory" => [
                                    "system" => [
                                        "total" => 0
                                    ]
                                ],
                                "engine-classes" => []
                            ];
                        }
                    }
                    error_log("gpustat: Found " . count($output['clients']) . " clients from sysfs fallback");
                }
            }
        }
        
        // Calculate RC6 residency from throttle status if available
        // For now, leave at 0 as the actual calculation would need historical data
        
        // Debug: log final client count
        error_log("gpustat: Transformed data has " . count($output['clients']) . " clients");
        
        return $output;
    }
      

}
