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

const ES = ' ';

include 'lib/Main.php';
include 'lib/Nvidia.php';
include 'lib/Intel.php';
include 'lib/AMD.php';

use gpustat\lib\AMD;
use gpustat\lib\Main;
use gpustat\lib\Nvidia;
use gpustat\lib\Intel;

if (!isset($gpustat_cfg)) {
    $gpustat_cfg = Main::getSettings();
}

// $gpustat_inventory should be set if called from settings page code
if (isset($gpustat_inventory) && $gpustat_inventory) {
    $gpustat_cfg['inventory'] = true;

    // Settings page looks for $gpustat_data specifically -- inventory all supported GPU types
    $gpustat_data = array_merge((new Nvidia($gpustat_cfg))->getInventorym(), (new Intel($gpustat_cfg))->getInventory(), (new AMD($gpustat_cfg))->getInventorym());

    // Test data
    // $gpustat_data = array_merge($gpustat_data, json_decode('{"0000:00:02.0": {"id": "00:02.0","model": "AlderLake-S GT1","vendor": "intel","guid": "GPU-94118c02-132c-45bb-a114-df6f24902d5d"},"0000:09:00.0": {"id": "09:00.0","model": "DG2 [Arc A770]","vendor": "intel","guid": "GPU-b13f472d-a4a7-4914-a59e-9b73c4856259"},"0000:08:00.0": {"id": "08:00.0","model": "Quadro K4000","vendor": "nvidia","guid": "GPU-ef6c0299-f1bc-7b5c-5291-7cd1a012f8bd"},"0000:0c:00.0": {"id": "0c:00.0","model": "Radeon RX 6400\/6500 XT\/6500M","vendor": "amd","guid": "GPU-639cd727-f368-4fe0-aff3-947542489448"}}', true));

    file_put_contents("/tmp/gpuinv",json_encode($gpustat_data));
}
