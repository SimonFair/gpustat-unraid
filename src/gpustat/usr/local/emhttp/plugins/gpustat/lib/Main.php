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

/** @noinspection PhpIncludeInspection */
require_once('/usr/local/emhttp/plugins/dynamix/include/Wrappers.php');

/**
 * Class Main
 * @package gpustat\lib
 */
class Main
{
    const PLUGIN_NAME = 'gpustat';
    const COMMAND_EXISTS_CHECKER = 'which';
    const DOCKER_INSPECT = 'docker container inspect';
    const DOCKER_ICON_DEFAULT_PATH = '/plugins/dynamix.docker.manager/images/question.png';
    const DOCKER_ICON_PATH = '/var/local/emhttp/plugins/dynamix.docker.manager/docker.json';
        const HOST_APPS = [ 
        'xorg'              => ['/plugins/gpustat/images/xorg.png'],
        'qemu-system-x86'   => ['/plugins/gpustat/images/qemu.png'],

    ];

    /**
     * @var array
     */
    public $settings;

    /**
     * @var string
     */
    protected $stdout;

    /**
     * @var array
     */
    protected $inventory;

    /**
     * @var array
     */
    protected $pageData;

    /**
     * @var array
     */
    protected $hostapps;

    /**
     * @var bool
     */
    protected $cmdexists;

    /**
     * GPUStat constructor.
     *
     * @param array $settings
     */
    public function __construct(array $settings = [])
    {
        $this->settings = $settings;
        if (isset($this->settings['inventory'])) {
            $this->checkCommand($this->settings['cmd'], false);
        } else {
            $this->checkCommand($this->settings['cmd']);
        }

        $this->stdout = '';
        $this->inventory = [];

        $this->pageData = [
            'clock'     => 'N/A',
            'fan'       => 'N/A',
            'memclock'  => 'N/A',
            'memutil'   => 'N/A',
            'memused'   => 'N/A',
            'power'     => 'N/A',
            'powermax'  => 'N/A',
            'rxutil'    => 'N/A',
            'txutil'    => 'N/A',
            'temp'      => 'N/A',
            'tempmax'   => 'N/A',
            'util'      => 'N/A',
            'pciegen'       => 'N/A',
            'pciegenmax'    => 'N/A',
            'pciewidth'     => 'N/A',
            'pciewidthmax'  => 'N/A',
            'igpu' => "",
        ];

        $this->hostapps = Self::HOST_APPS;
        $hostappsfile = "/boot/config/plugins/gpustat/hostapps.json";
        if (file_exists($hostappsfile)) {
            $jsonData = json_decode(file_get_contents($hostappsfile), true);

            if (is_array($jsonData)) {
                // Merge arrays (recursive if you want deeper merging)
                $this->hostapps = array_merge_recursive($this->hostapps, $jsonData);
                // OR if you want later values to overwrite earlier ones:
                // $hostapps = array_replace_recursive($hostapps, $jsonData);
            } 

        }
           # file_put_contents($hostappsfile,json_encode($this->hostapps));
    }


    /**
     * Checks if vendor utility exists in the system and dies if it does not
     *
     * @param string $utility
     * @param bool $error
     */
    protected function checkCommand(string $utility, bool $error = true)
    {
        $this->cmdexists = false;
        // Check if vendor utility is available
        $this->runCommand(self::COMMAND_EXISTS_CHECKER, $utility, false);
        // When checking for existence of the command, we want the return to be NULL
        if (!empty($this->stdout)) {
            $this->cmdexists = true;
        } else {
            // Send the error but don't die because we need to continue for inventory
            if ($error) {
                $this->pageData['error'][] = Error::get(Error::VENDOR_UTILITY_NOT_FOUND);
            }
        }
    }

    /**
     * Checks if card is bound to VFIO
     *
     * @param string $pciid
     * @return bool $vfio
     */
    protected function checkVFIO(string $pciid)
    {
        $files = @scandir("/sys/bus/pci/drivers/vfio-pci/") ;
        if ($files) $vfio = in_array($pciid, $files) ; else $vfio = $files ;
        return $vfio ;
    }

    /**
     * Checks get kernel driver.
     *
     * @param string $pciid
     * @return string $driver
     */
    protected function getKernelDriver2(string $pciid) {
        $driver = '';
        if (is_link('/sys/bus/pci/devices/'.$pciid.'/driver')) {
            $strLink = @readlink('/sys/bus/pci/devices/'.$pciid.'/driver');
            if (!empty($strLink)) {
                $driver = basename($strLink);
            }
        }
        return $driver;
    }
    protected function getKernelDriver(string $pciid): string {
        $command = "udevadm info --query=property --path=/sys/bus/pci/devices/$pciid | grep 'DRIVER='";
        $output = shell_exec($command);
        return $output ? trim(str_replace('DRIVER=', '', $output)) : '';
    }

    /**
     * Checks get PCIe bandwidth.
     *
     * @param string $pciid
     * 
     */
    protected function getPCIeBandwidth(string $pciid) {
        $sysfs_path = "/sys/bus/pci/devices/$pciid";
        
        if (file_exists("$sysfs_path/max_link_speed") && file_exists("$sysfs_path/max_link_width")) {
            $pciegen = trim(file_get_contents("$sysfs_path/max_link_speed"));
            $this->pageData['pciegen'] = $this->get_pcie_gen($pciegen);
            $pciegenmax = file_exists("$sysfs_path/current_link_speed") ? trim(file_get_contents("$sysfs_path/current_link_speed")) : "N/A";
            $this->pageData['pciegenmax'] = $this->get_pcie_gen($pciegenmax);
            $this->pageData['pciewidthmax'] = trim(file_get_contents("$sysfs_path/max_link_width"));
            $this->pageData['pciewidth'] = file_exists("$sysfs_path/current_link_width") ? trim(file_get_contents("$sysfs_path/current_link_width")) : "N/A";  
            $this->pageData['igpu'] = (strpos($pciid, "0000:00:") === 0) ? "1" : "0";
        }  
    }

    /**
    * Checks get PCIe gen.
    *
    * @param string $speed
    * @return int gen.
    */
    protected function get_pcie_gen($speed) {
        $speed=trim($speed);
        $speed_map = [
            "2.5 GT/s PCIe" => 1,
            "5.0 GT/s PCIe" => 2,
            "8.0 GT/s PCIe" => 3,
            "16.0 GT/s PCIe" => 4,
            "32.0 GT/s PCIe" => 5,
            "64.0 GT/s PCIe" => 6
        ];
        return $speed_map[$speed] ?? $speed;
    }

    /**
     * Runs a command in shell and stores STDOUT in class variable
     *
     * @param string $command
     * @param string $argument
     * @param bool $escape
     */
    protected function runCommand(string $command, string $argument = '', bool $escape = true)
    {
        if ($escape) {
            $this->stdout = shell_exec(sprintf("%s %s", $command, escapeshellarg($argument)));
        } else {
            $this->stdout = shell_exec(sprintf("%s %s", $command, $argument));
        }
    }

    protected function get_gpu_vm($vmpciid){
        global $lstpci;
        $libvirtd_running = is_file('/var/run/libvirt/libvirtd.pid') ;
        if (!$libvirtd_running) return false;
        if (!isset($lstpci)) {
          $lspci_lines = explode("\n", trim(shell_exec("lspci -n")));
          $lspci = array();
            foreach ($lspci_lines as $line) {
              // Strip content inside parentheses using preg_replace
              $cleaned_line = preg_replace('/\s*\(.*?\)\s*/', '', $line);
              // Split the cleaned line into parts
              list($device, $info) = explode(' ', $cleaned_line, 2);
              // Extract the key part for array key (both parts like c0a9:5407)
              $info_parts = explode(' ', $info);
              $key = $info_parts[1]; // This extracts the full key like "c0a9:5407"
              // Add to the array using the extracted part as the key
              $lspci[$key] = [
                'type' => $info_parts[0],
                'pciid' => $device
              ];
            }
          }

        $vmpcilist = array();
        $doms = explode("\n",shell_exec("virsh list --name"));      
        for ($i = 0; $i < sizeof($doms); $i++) {
            if ($doms[$i] == "") continue;
            $name = $doms[$i];
            $output = explode("\n",shell_exec('virsh qemu-monitor-command "'.$name.'" --hmp info pci | grep VGA'));
            foreach($output as $string) {
              // Check if the output contains the PCI device ID
              if (preg_match('/PCI device (\S+)/', $string, $matches)) {
                  // Extract the PCI device ID
                  $pciDeviceID = $matches[1];
                  if ($pciDeviceID == "1b36:0100") continue;
                  if (isset($lspci[$pciDeviceID]["pciid"])) {
                    $pciid = $lspci[$pciDeviceID]["pciid"];
                    $vmpcilist[$pciid] = $name;
                  }
              }
            }
        }

        #GetIcon
        global $docroot;
        if (array_key_exists($vmpciid,$vmpcilist)) {
        $strIcon = '/plugins/dynamix.vm.manager/templates/images/default.png';
        $strIconGet = shell_exec("virsh dumpxml '".$vmpcilist[$vmpciid]."' --xpath \"//domain/metadata/*[local-name()='vmtemplate']/@icon\"");
        preg_match('/icon="([^"]+)"/', $strIconGet, $matches);
        $strIcon = $matches[1] ?? $strIcon;  // This will contain the icon value
        if (is_file($strIcon)) {
            $strIcon = $strIcon;
        } elseif (is_file("$docroot/plugins/dynamix.vm.manager/templates/images/" . $strIcon)) {
            $strIcon = '/plugins/dynamix.vm.manager/templates/images/' . $strIcon;
        } elseif (is_file("$docroot/boot/config/plugins/dynamix.vm.manager/templates/images/" . $strIcon)) {
            $strIcon = '/boot/config/plugins/dynamix.vm.manager/templates/images/' . $strIcon;
        }
    }
        return isset($vmpcilist[$vmpciid]) ? $vmpcilist[$vmpciid].','.$strIcon : false;
    }

    /**
     * Retrieves the full command with arguments for a given process ID
     *
     * @param int $pid
     * @return string
     */
    protected function getFullCommand(int $pid): string
    {
        $command = '';
        $file = sprintf('/proc/%0d/cmdline', $pid);

        if (file_exists($file)) {
            $command = trim(@file_get_contents($file), "\0");
        }

        return $command;
    }
    /*
    * Retrieves the full command of a parent process with arguments for a given process ID
    *
    * @param int $pid
    * @return string
    */
    protected function getParentCommand(int $pid): string
    {
        $command = '';
        $pid_command = sprintf('ps j %0d | awk \'{ \$1=\$1 };NR>1\' | cut -d \' \' -f 1', $pid);

        $ppid = (int)trim(shell_exec($pid_command) ?? 0);
        if ($ppid > 0) {
            $command = $this->getFullCommand($ppid);
        }

        return $command;
    }
    
    /**
    * Retrieves sysfs files or defaults if no file
    *
    * @return 
    */
    protected function get_value($path, $default = "N/A") {
        return file_exists($path) ? trim(file_get_contents($path)) : $default;
    }

    /**
    * Retrieves hwmon path.
    *
    * @return mixed
    */
    protected function find_hwmon_path($pci_id) {
        $hwmon_base = "/sys/class/hwmon/";
        foreach (glob("$hwmon_base/hwmon*") as $hwmon) {
            if (file_exists("$hwmon/device")) {
                $device_real_path = realpath("$hwmon/device");
                if (strpos($device_real_path, $pci_id) !== false) {
                    return $hwmon;
                }
            }
        }
        return null;
    }

    /**
     * Retrieves the control group for a given process ID
     *
     * @param int $pid
     * @return string
     */
    protected function getControlGroup(int $pid): string
    {
        $cgroup = '';
        $file = sprintf('/proc/%0d/cgroup', $pid);

        if (file_exists($file)) {
            $cgroup = trim(@file_get_contents($file), "\0");
        }

        return $cgroup;
    }

    /**
     * Retrieves docker container info for a given docker ID
     *
     * @param string $id
     * @return array
     */
    protected function getDockerContainerInspect(string $id): array
    {
        $this->runCommand(self::DOCKER_INSPECT, $id);

        $json = json_decode($this->stdout);
        if (!$json || !isset($json[0]->Config->Labels)) {
            return [];
        }

        $docker_name = $json[0]->Name;
        $docker_name = preg_replace('/^\//', '', $docker_name);

        return [
            'name' => $docker_name,
            'title' => $json[0]->Config->Labels->{"org.opencontainers.image.title"} ?? $docker_name,
            'icon' => $this->getDockerContainerIcon($docker_name),
        ];
    }
    /**  Iterates supported applications and their respective commands to match against processes using GPU hardware
     *
     * @param array $process
     */
    protected function detectApplication (array $process)
    {
        $dockerInfo = null;
        $controlGroup = $this->getControlGroup((int) $process['pid']);
        $usedMemory = (int) $this->stripText(' MiB', $process['memory']);

        if ($controlGroup && preg_match('/docker\/([a-z0-9]+)$/', $controlGroup, $matches)) {
            $dockerInfo = $this->getDockerContainerInspect($matches[1]);
        }

        if (!$controlGroup || !$dockerInfo) {
            file_put_contents("/tmp/hostapps",json_encode($this->hostapps));
            if (isset($this->hostapps[$process['name']])) $icon = $this->hostapps[$process['name']]; else $icon=Self::DOCKER_ICON_DEFAULT_PATH;
            $active_app = [
                'name' => (string) $process['name'],
                'title' => (string) $process['name'],
                'icon' => $icon,
                'mem' => $usedMemory,
                'count' => 1,
            ];
        } else {
            $active_app = [
                'name' => $dockerInfo['name'],
                'title' => $dockerInfo['title'],
                'icon' => $dockerInfo['icon'],
                'mem' => $usedMemory,
                'count' => 1,
            ];
        }

        $index = array_search($active_app['name'], array_column($this->pageData['active_apps'], 'name'));

        if ($index === false) {
            $this->pageData['active_apps'][] = $active_app;
        } else {
            $this->pageData['active_apps'][$index]['mem'] += $usedMemory;
            $this->pageData['active_apps'][$index]['count']++;
        }
    }

    /**
     * Retrieves docker container icon for a given docker NAME
     *
     * @param string $name
     * @return string
     */
    protected function getDockerContainerIcon(string $name): string
    {
        if (!file_exists(self::DOCKER_ICON_PATH)) {
            return self::DOCKER_ICON_DEFAULT_PATH;
        }

        $json = json_decode(file_get_contents(self::DOCKER_ICON_PATH));

        return $json->$name->icon ?: self::DOCKER_ICON_DEFAULT_PATH;
    }

    /**
     * Retrieves plugin settings and returns them or defaults if no file
     *
     * @return mixed
     */
    public static function getSettings()
    {
        /** @noinspection PhpUndefinedFunctionInspection */
        return parse_plugin_cfg(self::PLUGIN_NAME);
    }

    /**
     * Triggers regex match all against class variable stdout and places matches in class variable inventory
     *
     * @param string $regex
     */
    protected function parseInventory(string $regex = '')
    {
        preg_match_all($regex, $this->stdout, $this->inventory, PREG_SET_ORDER);
    }


    /**
     * Strips all spaces from a provided string
     *
     * @param string $text
     * @return string
     */
    protected static function stripSpaces(string $text = ''): string
    {
        return str_replace(' ', '', $text);
    }

    /**
     * Converts Celsius to Fahrenheit
     *
     * @param int $temp
     * @return float
     */
    protected static function convertCelsius(int $temp = 0): float
    {
        $fahrenheit = $temp*(9/5)+32;
        
        return round($fahrenheit, -1);
    }

    /**
     * Rounds a float to a whole number
     *
     * @param float $number
     * @param int $precision
     * @return float
     */
    protected static function roundFloat(float $number, int $precision = 0): float
    {
        if ($precision > 0) {
            $result = number_format(round($number, $precision), $precision, '.','');
        } else {
            $result = round($number, $precision);
        }

        return $result;
    }

    /**
     * Replaces a string within a string with an empty string
     *
     * @param string|string[] $strip
     * @param string $string
     * @return string|string[]
     */
    protected static function stripText($strip, string $string)
    {
        return str_replace($strip, '', $string);
    }
}
