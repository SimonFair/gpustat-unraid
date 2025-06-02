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

/**
 * Class AMD
 * @package gpustat\lib
 */
class AMD extends Main
{
    const CMD_UTILITY = 'radeontop';
    const INVENTORY_UTILITY = 'lspci';
    const INVENTORY_PARAM = '| grep VGA';
    const INVENTORY_PARAMm = " -Dmm | grep VGA";
    const INVENTORY_REGEX =
        '/^(?P<busid>[0-9a-f]{2}).*\[AMD(\/ATI)?\]\s+(?P<model>.+)\s+(\[(?P<product>.+)\]|\()/imU';

    const STATISTICS_PARAM = '-d - -l 1';
    const STATISTICS_KEYMAP = [
        'gpu'   => ['util'],
        'ee'    => ['event'],
        'vgt'   => ['vertex'],
        'ta'    => ['texture'],
        'sx'    => ['shaderexp'],
        'sh'    => ['sequencer'],
        'spi'   => ['shaderinter'],
        'sc'    => ['scancon'],
        'pa'    => ['primassem'],
        'db'    => ['depthblk'],
        'cb'    => ['colorblk'],
        'vram'  => ['memutil', 'memused'],
        'gtt'   => ['gfxtrans', 'transused'],
        'mclk'  => ['memclockutil', 'memclock', 'clocks'],
        'sclk'  => ['clockutil', 'clock', 'clocks'],
        'uvd'   => ['uvd'],
        'vce0'  => ['vce'],
    ];
    const SUPPORTED_APPS = [ // Order here is important because some apps use the same binaries -- order should be more specific to less
        'plex'        => ['Plex Transcoder'],
        'jellyfin'    => ['ffmpeg','jellyfin'],
        'handbrake'   => ['/usr/bin/HandBrakeCLI'],
        'emby'        => ['ffmpeg', 'EmbyServer'],
        'tdarr'       => ['ffmpeg', 'HandbrakeCLI'],
        'unmanic'     => ['ffmpeg'],
        'dizquetv'    => ['ffmpeg'],
        'ersatztv'    => ['ffmpeg'],
        'fileflows'   => ['ffmpeg'],
        'frigate'     => ['ffmpeg'],
        'threadfin'   => ['ffmpeg','Threadfin'],
        'tunarr'      => ['ffmpeg','tunarr'],
        'codeproject' => ['python3.8'],
        'deepstack'   => ['python3'],
        'nsfminer'    => ['nsfminer'],
        'shinobipro'  => ['shinobi'],
        'foldinghome' => ['FahCore'],
        'compreface'  => ['uwsgi'],
        'ollama'      => ['ollama_llama_server'],
        'immich'      => ['immich'],
        'localai'     => ['localai'],
        'chia'        => ['chia'],
        'mmx'         => ['mmx_node'],
        'subspace'    => ['subspace'],
        'xorg'        => ['Xorg'],
        'qemu'        => ['qemu'],

    ];
    const TEMP_UTILITY = 'sensors';
    const TEMP_PARAM = '-j 2>errors';

    /**
     * AMD constructor.
     * @param array $settings
     */
    public function __construct(array $settings = [])
    {
        $settings += ['cmd' => self::CMD_UTILITY];
        parent::__construct($settings);
    }
    
        /**
     * Iterates supported applications and their respective commands to match against processes using GPU hardware
     *
     * @param array $process
     */
    private function detectApplication (array $process)
    {
        $debug_apps = is_file("/tmp/gpustatapps") ?? false;
        if ($debug_apps) file_put_contents("/tmp/gpuappsint","");
        foreach (self::SUPPORTED_APPS as $app => $commands) {
            foreach ($commands as $command) {
                if (strpos($process['name'], $command) !== false) {
                    // For Handbrake/ffmpeg: arguments tell us which application called it
                    if (in_array($command, ['ffmpeg', 'HandbrakeCLI', 'python3.8','python3'])) {
                        if (isset($process['pid'])) {
                            $pid_info = $this->getFullCommand((int) $process['pid']);
                            if ($debug_apps) file_put_contents("/tmp/gpuappsint","$command\n$pid_info\n",FILE_APPEND);
                            if (!empty($pid_info) && strlen($pid_info) > 0) {
                                if ($command === 'python3.8') {
                                    // CodeProject doesn't have any signifier in the full command output
                                    if (strpos($pid_info, '/ObjectDetectionYolo/detect_adapter.py') === false) {
                                        continue 2;
                                    }
                                } elseif ($command === 'python3') {
                                    // Deepstack doesn't have any signifier in the full command output
                                    if (strpos($pid_info, '/app/intelligencelayer/shared') === false) {
                                        continue 2;
                                    }
                                } elseif (stripos($pid_info, strtolower($app)) === false) {
                                    // Try to match the app name in the parent process
                                    $ppid_info = $this->getParentCommand((int) $process['pid']);
                                    if ($debug_apps) file_put_contents("/tmp/gpuappsint","$ppid_info\n",FILE_APPEND);
                                    if (stripos($ppid_info, $app) === false) {
                                        // We didn't match the application name in the arguments, no match
                                        if ($debug_apps) file_put_contents("/tmp/gpuappsint","not found app $app\n",FILE_APPEND);
                                        continue 2;
                                    } else if ($debug_apps) file_put_contents("/tmp/gpuappsint","\nfound app $app\n",FILE_APPEND);
                                }
                            }
                        }
                    }
                    $this->pageData[$app . 'using'] = true;
                    #$this->pageData[$app . 'mem'] += (int)$this->stripText(' MiB', $process->used_memory);
                    $this->pageData[$app . 'mem'] = 0;
                    if (isset($this->pageData[$app . 'count'])) $this->pageData[$app . 'count']++; else $this->pageData[$app . 'count'] = 1;
                    if ($debug_apps) file_put_contents("/tmp/gpuappsint","\nfound app $app $command\n",FILE_APPEND);
                    // If we match a more specific command/app to a process, continue on to the next process
                    break 2;
                }
            }
        }
    }
    /**
     * Retrieves AMD inventory using lspci and returns an array
     *
     * @return array
     */
    public function getInventory(): array
    {
        $result = [];

        if ($this->cmdexists) {
            $this->checkCommand(self::INVENTORY_UTILITY, false);
            if ($this->cmdexists) {
                $this->runCommand(self::INVENTORY_UTILITY, self::INVENTORY_PARAM, false);
                if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                    $this->parseInventory(self::INVENTORY_REGEX);
                }
                if (!empty($this->inventory)) {
                    foreach ($this->inventory AS $gpu) {
                        $result[$gpu['busid']] = [
                            'id'    => "Bus ID " . $gpu['busid'],
                            'model' => (string) ($gpu['product'] ?? $gpu['model']),
                            'guid'  => $gpu['busid'],
                        ];
                    }
                }
            }
        }

        return $result;
    }

        /**
     * Retrieves AMD inventory using lspci and returns an array
     *
     * @return array
     */
    public function getInventorym(): array
    {
        $result = [];

        $this->checkCommand(self::INVENTORY_UTILITY, false);
        if ($this->cmdexists) {
            $this->runCommand(self::INVENTORY_UTILITY, self::INVENTORY_PARAMm, false);
            if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                foreach(explode(PHP_EOL,$this->stdout) AS $vga) {
                    preg_match_all('/"([^"]*)"|(\S+)/', $vga, $matches);
                    if (!isset( $matches[0][0])) continue ;
                    $id = str_replace('"', '', $matches[0][0]) ;
                    $vendor = str_replace('"', '',$matches[0][2]) ;
                    $model = str_replace('"', '',$matches[0][3]) ;
                    if ($vendor != "Advanced Micro Devices, Inc. [AMD/ATI]") continue ;
                    $result[$id] = [
                        'id' => substr($id,5) ,
                        'model' => $model,
                        'vendor' => 'amd',
                        'guid' => substr($id,5,2)
                    ];

                    }
                }
        }

        return $result;
    }

    /**
     * Retrieves AMD APU/GPU statistics
     */
    public function getStatistics()
    {
        $driver = strtoupper($this->getKernelDriver("0000:".$this->settings['PCIID']));
        if (!$this->checkVFIO("0000:".$this->settings['PCIID']))
        {
            if ($this->cmdexists) {
                //Command invokes radeontop in STDOUT mode with an update limit of half a second @ 120 samples per second
                $command = sprintf("%0s -b %1s", self::CMD_UTILITY, $this->settings['GPUID']);
                $this->runCommand($command, self::STATISTICS_PARAM, false);
                if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                    $this->parseStatistics();
                } else {
                    $this->pageData['error'][] += Error::get(Error::VENDOR_DATA_NOT_RETURNED);
                }
                $this->pageData["vfio"] = false ;
                $this->pageData["vfiochk"] = $this->checkVFIO("0000:".$this->settings['PCIID']) ;
                $this->pageData["vfiochkid"] = "0000:".$this->settings['PCIID'] ;
                $this->pageData['vfiovm'] = false;
                $this->pageData['driver'] = $driver;
            } else {
                $this->pageData['error'][] = Error::get(Error::VENDOR_UTILITY_NOT_FOUND);
                $this->pageData["vendor"] = "AMD" ;
                $this->pageData["name"] = $this->settings['GPUID'] ;
                $this->pageData['driver'] = $driver;
            }
        } else {
            $this->pageData["vfio"] = true ;
            $this->pageData["vendor"] = "AMD" ;
            $this->pageData["vfiochk"] = $this->checkVFIO("0000:".$this->settings['PCIID']) ;
            $this->pageData["vfiochkid"] = $this->settings['PCIID'] ;
            $this->pageData['vfiovm'] = $this->get_gpu_vm($this->settings['PCIID']);
            $this->pageData['driver'] = $driver;
            $gpus = $this->getInventory() ;
            if ($gpus) {
                if (isset($gpus[$this->settings['GPUID']])) {
                    $this->pageData['name'] = $gpus[$this->settings['GPUID']]["model"] ;
                }
            }

        }
        $this->getPCIeBandwidth("0000:".$this->settings['PCIID']);
        return json_encode($this->pageData) ;  
    }

    /**
     * Retrieves AMD APU/GPU Temperature/Fan/Power/Voltage readings from lm-sensors
     * @returns array
     */
    private function getSensorData(): array
    {
        $sensors = [];

        $this->checkCommand(self::TEMP_UTILITY, false);
        if ($this->cmdexists) {
            $tempFormat = '';
            if ($this->settings['TEMPFORMAT'] == 'F') {
                $tempFormat = '-f';
            }
            $chip = sprintf('amdgpu-pci-%1s00', $this->settings['GPUID']);
            $command = sprintf('%0s %1s %2s', self::TEMP_UTILITY, $chip, $tempFormat);
            $this->runCommand($command, self::TEMP_PARAM, false);
            if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                $data = json_decode($this->stdout, true);
                if(isset($data[$chip])) {
                    $data = $data[$chip];
                    if ($this->settings['DISPTEMP']) {
                        if (isset($data['edge']['temp1_input'])) {
                            $sensors['tempunit'] = $this->settings['TEMPFORMAT'];
                            $sensors['temp'] = $this->roundFloat($data['edge']['temp1_input']) . ' °' . $sensors['tempunit'];
                            if (isset($data['edge']['temp1_crit'])) {
                                $sensors['tempmax'] = $this->roundFloat($data['edge']['temp1_crit']);
                            }
                        }
                    }
                    if ($this->settings['DISPFAN']) {
                        if (isset($data['fan1']['fan1_input'])) {
                            $sensors['fan'] = $this->roundFloat($data['fan1']['fan1_input']);
                            if (isset($data['fan1']['fan1_max'])) {
                                $sensors['fanmax'] = $this->roundFloat($data['fan1']['fan1_max']);
                            }
                        }
                    }
                    if ($this->settings['DISPPWRDRAW']) {
                        if (isset($data['power1'])) $powervalue = "power1";
                        if (isset($data['PPT'])) $powervalue = "PPT";

                        if (isset($data[$powervalue]['power1_average'])) {
                            $sensors['power'] = $this->roundFloat($data[$powervalue]['power1_average'], 1)."W";
                        }
                        if (isset($data[$powervalue]['power1_input'])) {
                            $sensors['power'] = $this->roundFloat($data[$powervalue]['power1_input'], 1)."W";
                        }
                        if (isset($data[$powervalue]['power1_cap'])) {
                            $sensors['powermax'] = $this->roundFloat($data[$powervalue]['power1_cap'], 1);
                        }
                        if (isset($data['vddgfx']['in0_input'])) {
                            $sensors['voltage'] = $this->roundFloat($data['vddgfx']['in0_input'], 2);
                            $sensors['voltageunit'] = 'V';
                        }
                    }
                }
            }
        }

        return $sensors;
    }

    /**
     * Loads radeontop STDOUT and parses into an associative array for mapping to plugin variables
     */
    private function parseStatistics()
    {
        $this->pageData += [
            'vendor'        => 'AMD',
            'name'          => 'APU/GPU',
            'event'         => 'N/A',
            'vertex'        => 'N/A',
            'texture'       => 'N/A',
            'shaderexp'     => 'N/A',
            'sequencer'     => 'N/A',
            'shaderinter'   => 'N/A',
            'scancon'       => 'N/A',
            'primassem'     => 'N/A',
            'depthblk'      => 'N/A',
            'colorblk'      => 'N/A',
        ];



        // radeontop data doesn't follow a standard object format -- need to parse CSV and then explode by spaces
        $data = explode(", ", substr($this->stdout, strpos($this->stdout, 'gpu')));
        $count = count($data);
        if ($count > 0) {
            foreach ($data AS $metric) {
                // metric util% value
                $fields = explode(" ", $metric);
                if (isset(self::STATISTICS_KEYMAP[$fields[0]])) {
                    $values = self::STATISTICS_KEYMAP[$fields[0]];
                    if (array_key_exists('DISP' . strtoupper($values[0]),$this->settings)) $check1 = $this->settings['DISP' . strtoupper($values[0])];else $check1 = false;
                    if (array_key_exists(2,$values)) $check2 = $this->settings['DISP' . strtoupper($values[2])]; else $check2 = false;
                    if ($check1 || $check2) {
                        $this->pageData[$values[0]] = $this->roundFloat($this->stripText('%', $fields[1]), 1) . '%';
                        if (isset($fields[2])) {
                            $this->pageData[$values[1]] = $this->roundFloat(
                                trim(
                                    $this->stripText(
                                        ['mb','ghz'],
                                        $fields[2]
                                    )
                                ), 2
                            );
                        }
                    } elseif ($fields[0] == 'gpu') {
                        // GPU Load doesn't have a setting, for now just pass the check
                        $this->pageData[$values[0]] = $this->roundFloat($this->stripText('%', $fields[1]), 1) . '%';
                    }
                }
            }
            unset($data, $this->stdout);
        } else {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_NOT_ENOUGH, "Count: $count");
        }
        $this->pageData = array_merge($this->pageData, $this->getSensorData());

        if ($this->settings['DISPSESSIONS']) {
            $this->pageData['appssupp'] = array_keys(self::SUPPORTED_APPS);
            $clientsPath = "/sys/kernel/debug/dri/0000:{$this->settings['PCIID']}/clients";
            $clients = [];
    
            if (file_exists($clientsPath)) {
                $lines = file($clientsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                array_shift($lines); // Remove the header row
    
                foreach ($lines as $line) {
                    $columns = preg_split('/\s+/', trim($line));
                    if (count($columns) >= 6) {
                        list($command, $tgid, $dev, $master, $a, $uid) = $columns;
                        $clients[$tgid] = [
                            "name" => $command,
                            "pid" => $tgid,
                            "gpu_instance_id" => "N/A",
                            "compute_instance_id" => "N/A",
                            "type" => "C",
                            "used_memory" => "N/A"
                        ];
                    }
                }
            }
            $this->pageData['sessions'] = 0;
            if (isset($clients) && count($clients) > 0) {
                $this->pageData['sessions'] = count($clients);
                if ($this->pageData['sessions'] > 0) {
                    foreach ($clients as $id => $process) {
                        if (isset($process["name"])) {
                            $this->detectApplication($process);
                        }
                    }
                }
            }
        }

        $gpus = $this->getInventory() ;
        if ($gpus) {
            if (isset($gpus[$this->settings['GPUID']])) {
                $this->pageData['name'] = $gpus[$this->settings['GPUID']]["model"] ;
            }
        }
    }
}
