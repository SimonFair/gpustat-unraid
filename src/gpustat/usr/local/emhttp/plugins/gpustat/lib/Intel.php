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
    const STATISTICS_PARAM = '-J -s 1000 -d pci:slot="';
    const STATISTICS_WRAPPER = 'timeout -k ';
    const SUPPORTED_APPS = [ // Order here is important because some apps use the same binaries -- order should be more specific to less
        'plex'        => ['Plex Transcoder'],
        'jellyfin'    => ['ffmpeg','jellyfin'],
        'handbrake'   => ['/usr/bin/HandBrakeCLI'],
        'emby'        => ['ffmpeg', 'Emby'],
        'tdarr'       => ['ffmpeg', 'HandbrakeCLI'],
        'unmanic'     => ['ffmpeg'],
        'dizquetv'    => ['ffmpeg'],
        'ersatztv'    => ['ffmpeg'],
        'fileflows'   => ['ffmpeg'],
        'frigate'     => ['ffmpeg'],
        'Threadfin'   => ['ffmpeg'],
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

    ];
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
     * Iterates supported applications and their respective commands to match against processes using GPU hardware
     *
     * @param SimpleXMLElement $process
     */
    private function detectApplication (array $process)
    {
        foreach (self::SUPPORTED_APPS as $app => $commands) {
            foreach ($commands as $command) {
                if (strpos($process['name'], $command) !== false) {
                    // For Handbrake/ffmpeg: arguments tell us which application called it
                    if (in_array($command, ['ffmpeg', 'HandbrakeCLI', 'python3.8','python3'])) {
                        if (isset($process['pid'])) {
                            $pid_info = $this->getFullCommand((int) $process['pid']);
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
                                    if (stripos($ppid_info, $app) === false) {
                                        // We didn't match the application name in the arguments, no match
                                        continue 2;
                                    }
                                }
                            }
                        }
                    }
                    $this->pageData[$app . 'using'] = true;
                    #$this->pageData[$app . 'mem'] += (int)$this->stripText(' MiB', $process->used_memory);
                    $this->pageData[$app . 'mem'] = 0;
                    $this->pageData[$app . 'count']++;
                    // If we match a more specific command/app to a process, continue on to the next process
                    break 2;
                }
            }
        }
    }

    /**
     * Retrieves Intel inventory using lspci and returns an array
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
        }
        return $result;
    }

    /**
     * Retrieves Intel iGPU statistics
     */
    public function getStatistics()
    {
        if (!$this->checkVFIO($this->settings['GPUID']))
        {

            if ($this->cmdexists) {
                //Command invokes intel_gpu_top in JSON output mode with an update rate of 5 seconds
                if (!isset($this->settings['IGTTIMER'])) $this->settings['IGTTIMER'] = ".500 1.500";
                $command = self::STATISTICS_WRAPPER . ES . $this->settings['IGTTIMER'] . ES . self::CMD_UTILITY;
                            //Command invokes radeontop in STDOUT mode with an update limit of half a second @ 120 samples per second
                $this->runCommand($command, self::STATISTICS_PARAM. $this->settings['GPUID'].'"', false); 
                if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                    $this->parseStatistics();
                } else {
                    $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_NOT_RETURNED);
                }
                $this->pageData["vfio"] = false ;
                $this->pageData["vfiochk"] = $this->checkVFIO($this->settings['GPUID']) ;
                $this->pageData["vfiochkid"] = $this->settings['GPUID'] ;
                $this->pageData['vfiovm'] = false;
            } else {
                $this->pageData['error'][] = Error::get(Error::VENDOR_UTILITY_NOT_FOUND);
                $this->pageData["vendor"] = "Intel" ;
                $this->pageData["name"] = $this->settings['GPUID'] ;
            }
        } else {
            $this->pageData["vfio"] = true ;
            $this->pageData["vendor"] = "Intel" ;
            $this->pageData["vfiochk"] = $this->checkVFIO($this->settings['GPUID']) ;
            $this->pageData["vfiochkid"] = $this->settings['GPUID'] ;
            $this->pageData['vfiovm'] = $this->get_gpu_vm($this->settings['PCIID']);
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
        // JSON output from intel_gpu_top with multiple array indexes isn't properly formatted
        $stdout = trim($this->stdout, '[]');
        $stdout = str_replace('}{', '},{', str_replace(["\n","\t"], '', $stdout));

        try {
            // Split the string into two JSON objects
            $splitJson = preg_split('/\}\s*,\s*\{/m', $stdout);
                        
            // Format the split parts correctly for JSON decoding
            $splitJson[0] .= '}';
            $splitJson[1] = '{' . $splitJson[1];

            $data = json_decode($splitJson[1], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $data = [];
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_BAD_PARSE, $e->getMessage());
        }

        // Need to make sure we have at least two array indexes to take the second one
        $count = count($data);
        if ($count < 1) {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_NOT_ENOUGH, "Count: $count");
        }
        file_put_contents("/tmp/gpudata".$this->settings['GPUID'],json_encode($data));
        // intel_gpu_top will never show utilization counters on the first sample so we need the second position
        unset($stdout, $this->stdout);

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
   
            if (is_numeric(rtrim($this->pageData['3drender'],"%"))) $max3drenderchk = $this->pageData['3drender']; else $max3drenderchk = "0%";
            if (is_numeric(rtrim($this->pageData['blitter'],"%"))) $maxblitterchk = $this->pageData['bliter']; else $maxblitterchk = "0%";
            if (is_numeric(rtrim($this->pageData['video'],"%"))) $maxvideochk = $this->pageData['video']; else $maxvideochk = "0%";
            if (is_numeric(rtrim($this->pageData['videnh'],"%"))) $maxvidenhchk = $this->pageData['videnh']; else $maxvidenhchk = "0%";

            $maxload = (max($max3drenderchk ,$maxblitterchk, $maxvideochk, $maxvidenhchk));
            $this->pageData['util'] = $maxload;

            if ($this->settings['DISPPWRDRAW']) {
                // Older versions of intel_gpu_top in case people haven't updated
                if (isset($data['power']['value'])) {
                    $this->pageData['power'] = $this->roundFloat($data['power']['value'], 2) . $data['power']['unit'];
                // Newer version of intel_gpu_top includes GPU and package power readings, just scrape GPU for now
                } else {
                    if (isset($data['power']['Package']) && ($this->settings['DISPPWRDRWSEL'] == "MAX" || $this->settings['DISPPWRDRWSEL'] == "PACKAGE" )) $powerPackage = $this->roundFloat($data['power']['Package'], 2) ; else $powerPackage = 0 ;
                    if (isset($data['power']['GPU']) && ($this->settings['DISPPWRDRWSEL'] == "MAX" || $this->settings['DISPPWRDRWSEL'] == "GPU" )) $powerGPU = $this->roundFloat($data['power']['GPU'], 2) ;  else $powerGPU = 0 ;
                    if (isset($data['power']['unit'])) $powerunit = $data['power']['unit'] ; else $powerunit = "" ;
                    $this->pageData['power'] = max($powerGPU,$powerPackage) . $powerunit ;               
                }
            }
            // According to the sparse documentation, rc6 is a percentage of how little the GPU is requesting power
            if ($this->settings['DISPPWRSTATE']) {
                if (isset($data['rc6']['value'])) {
                    $this->pageData['powerutil'] = $this->roundFloat(100 - $data['rc6']['value'], 2) . "%";
                    #$this->pageData['powerutil'] = $this->roundFloat(100 - 50, 2) . "%";
                }
            }
            if ($this->settings['DISPCLOCKS']) {
                if (isset($data['frequency']['actual'])) {
                    $this->pageData['clock'] = (int) $this->roundFloat($data['frequency']['actual']);
                }
            }
            if ($this->settings['DISPINTERRUPT']) {
                if (isset($data['interrupts']['count'])) {
                    $this->pageData['interrupts'] = (int) $this->roundFloat($data['interrupts']['count']);
                }
            }
            if ($this->settings['DISPSESSIONS']) {
                $this->pageData['appssupp'] = array_keys(self::SUPPORTED_APPS);

                if (isset($data['clients']) && count($data['clients']) > 0) {
                    $this->pageData['sessions'] = count($data['clients']);
                    if ($this->pageData['sessions'] > 0) {
                        foreach ($data['clients'] as $id => $process) {
                            if (isset($process["name"])) {
                                $this->detectApplication($process);
                            }
                        }
                    }
                }
            }
        } else {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_BAD_PARSE);
        }
      }
}
