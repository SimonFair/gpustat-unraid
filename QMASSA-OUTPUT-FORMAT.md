# qmassa Actual Output Format Reference

Based on real testing with Intel Arc A770 (XE driver)

## Command Used
```bash
qmassa -wx -m 400 -n 1 -t /tmp/qmassajson -d 0000:04:00.0
```

**Flags:**
- `-w` = Show all temperature/fan sensors
- `-x` = No TUI (headless/batch mode)
- `-m 400` = 400ms interval between samples
- `-n 1` = Single iteration
- `-t <file>` = Output to JSON file
- `-d <pci>` = Specific device by PCI address

## Output Format: JSONL (JSON Lines)

qmassa outputs **3 lines** (not standard JSON):

### Line 1: Version String
```json
"2.0"
```

### Line 2: Configuration Object
```json
{
  "dev_slots": "0000:04:00.0",
  "pid": null,
  "ms_interval": 400,
  "nr_iterations": 1,
  "all_clients": false,
  "group_by_pid": false,
  "show_pciid": false,
  "all_sensors": true,
  "to_json": null,
  "log_file": null,
  "no_tui": false,
  "drv_options": null,
  "command": null
}
```

### Line 3: Actual Data (THIS IS WHAT WE NEED)
```json
{
  "timestamps": [1],
  "devs_state": [
    {
      "pci_dev": "0000:04:00.0",
      "pci_id": "8086:E212",
      "vdr_dev": "Intel Corporation E212",
      "revision": "00",
      "dev_type": {"Discrete": "NoVirt"},
      "drv_name": "xe",
      "dev_nodes": "card0, renderD128",
      "eng_names": [],
      "freq_limits": [
        {"name": "gt0", "minimum": 400, "efficient": 400, "maximum": 2600},
        {"name": "gt1", "minimum": 400, "efficient": 400, "maximum": 1500}
      ],
      "dev_stats": {
        "mem_info": [{
          "smem_total": 66776141824,
          "smem_used": 49152,
          "vram_total": 17095983104,
          "vram_used": 32772096
        }],
        "eng_usage": {},
        "freqs": [[
          {
            "min_freq": 1200,
            "cur_freq": 1200,
            "act_freq": 1200,
            "max_freq": 2600,
            "throttle_reasons": {
              "pl1": false,
              "pl2": false,
              "pl4": false,
              "prochot": false,
              "ratl": false,
              "thermal": false,
              "vr_tdc": false,
              "vr_thermalert": false,
              "status": false
            }
          },
          {
            "min_freq": 1200,
            "cur_freq": 1200,
            "act_freq": 1200,
            "max_freq": 1500,
            "throttle_reasons": {...}
          }
        ]],
        "power": [{"gpu_cur_power": 0.0, "pkg_cur_power": 0.0}],
        "temps": [[
          {"name": "pkg", "temp": 53.0},
          {"name": "vram", "temp": 52.0}
        ]],
        "fans": [[{"name": "1", "speed": 1451}]]
      },
      "clis_stats": []
    }
  ]
}
```

## Data Structure Breakdown

### Navigation Path
```
Line 3 (JSON) → devs_state[0] → dev_stats → {metrics}
```

### Key Metrics Locations

#### Device Info (devs_state[0])
```json
{
  "pci_dev": "0000:04:00.0",       // PCI address
  "pci_id": "8086:E212",            // Vendor:Device ID
  "vdr_dev": "Intel Corporation E212", // Full name
  "drv_name": "xe",                 // Driver name
  "dev_type": {"Discrete": "NoVirt"} // Device type
}
```

#### Frequencies (dev_stats.freqs)
**Structure:** Array of arrays `[[gt0_data, gt1_data]]`

```json
"freqs": [[
  {
    "min_freq": 1200,      // Minimum frequency (MHz)
    "cur_freq": 1200,      // Current/requested frequency (MHz)
    "act_freq": 1200,      // Actual frequency (MHz)
    "max_freq": 2600,      // Maximum frequency (MHz)
    "throttle_reasons": {  // Throttle status flags
      "pl1": false,        // Power limit 1
      "pl2": false,        // Power limit 2
      "thermal": false,    // Thermal throttling
      "status": false      // Overall throttle status
    }
  },
  {...}  // Additional GTs (graphics tiles)
]]
```

**Mapping to intel_gpu_top:**
- `cur_freq` → `frequency.requested`
- `act_freq` → `frequency.actual`

#### Power (dev_stats.power)
**Structure:** Array `[{power_data}]`

```json
"power": [{
  "gpu_cur_power": 0.0,   // GPU power in watts
  "pkg_cur_power": 0.0    // Package power in watts
}]
```

**Mapping to intel_gpu_top:**
- `gpu_cur_power` → `power.GPU`
- `pkg_cur_power` → `power.Package`

#### Temperature (dev_stats.temps)
**Structure:** Array of arrays `[[{sensor1}, {sensor2}]]`

```json
"temps": [[
  {"name": "pkg", "temp": 53.0},   // Package temp (°C)
  {"name": "vram", "temp": 52.0}   // VRAM temp (°C)
]]
```

#### Fan Speed (dev_stats.fans)
**Structure:** Array of arrays `[[{fan1}]]`

```json
"fans": [[
  {"name": "1", "speed": 1451}  // RPM
]]
```

#### Memory (dev_stats.mem_info)
**Structure:** Array `[{mem_data}]`

```json
"mem_info": [{
  "smem_total": 66776141824,   // System memory total (bytes)
  "smem_used": 49152,          // System memory used (bytes)
  "vram_total": 17095983104,   // VRAM total (bytes) - discrete only
  "vram_used": 32772096        // VRAM used (bytes) - discrete only
}]
```

#### Engine Usage (dev_stats.eng_usage)
**Structure:** Object with engine names as keys

```json
"eng_usage": {
  "RCS0": {"busy": 45.2},      // Render/3D engine
  "BCS0": {"busy": 0.0},       // Blitter engine
  "VCS0": {"busy": 12.5},      // Video decode
  "VECS0": {"busy": 0.0},      // Video enhance
  "CCS0": {"busy": 0.0}        // Compute engine
}
```

**Note:** In the test output, `eng_usage` was empty `{}` (GPU idle)

**Engine Name Mapping:**
| qmassa | intel_gpu_top | Description |
|--------|---------------|-------------|
| RCS* | Render/3D | 3D rendering |
| BCS* | Blitter | Copy/blit operations |
| VCS* | Video | Video decode |
| VECS* | VideoEnhance | Video encode/enhance |
| CCS* | Compute | Compute shaders |

#### DRM Clients (clis_stats)
**Structure:** Array of client objects

```json
"clis_stats": [
  {
    "pid": 1234,
    "comm": "glxgears",
    "smem_rss": 1048576,     // System memory (bytes)
    "vram_rss": 2097152,     // VRAM (bytes)
    "eng_usage": {
      "RCS0": {"busy": 99.5},
      "VCS0": {"busy": 0.0}
    }
  }
]
```

**Note:** In test output, `clis_stats` was empty `[]` (no active clients)

## Important Observations

### 1. Arrays Are Nested
Most data is in **arrays of arrays**, not simple arrays:
- ❌ `freqs[0].act_freq`
- ✅ `freqs[0][0].act_freq`

### 2. Empty When Idle
- `eng_usage` = `{}` when GPU idle
- `clis_stats` = `[]` when no processes using GPU

### 3. Multiple Graphics Tiles (GT)
Arc A770 has 2 GTs:
- `freqs[0][0]` = gt0 (primary, up to 2600 MHz)
- `freqs[0][1]` = gt1 (secondary, up to 1500 MHz)

Use first GT (gt0) for frequency reporting.

### 4. Field Names Differ from Assumption
| Field | Actual | Initial Assumption |
|-------|--------|-------------------|
| Requested freq | `cur_freq` | `requested` |
| Actual freq | `act_freq` | `actual` |
| GPU power | `gpu_cur_power` | `GPU` |
| Package power | `pkg_cur_power` | `Package` |
| Client command | `comm` | `command` |
| Client memory | `smem_rss` | `smem_resident` |

### 5. No RC6 Data Available
qmassa doesn't directly expose RC6 residency percentage. Can potentially calculate from:
- Throttle status over time
- Power state transitions
- For now: leave as 0 or calculate approximate

## PHP Parsing Strategy

```php
// Read JSONL file (3 lines)
$lines = file($jsonFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

// Parse line 3 (actual data)
$data = json_decode($lines[2], true);

// Navigate to device
$device = $data['devs_state'][0];  // First device
$stats = $device['dev_stats'];

// Extract metrics
$freq_actual = $stats['freqs'][0][0]['act_freq'] ?? null;  // gt0 actual
$freq_request = $stats['freqs'][0][0]['cur_freq'] ?? null; // gt0 requested
$power_gpu = $stats['power'][0]['gpu_cur_power'] ?? null;
$power_pkg = $stats['power'][0]['pkg_cur_power'] ?? null;
$temp_pkg = $stats['temps'][0][0]['temp'] ?? null;         // First temp sensor
$fan_speed = $stats['fans'][0][0]['speed'] ?? null;        // First fan

// Engine usage
foreach ($stats['eng_usage'] as $engine => $data) {
    $busy = $data['busy'] ?? 0.0;
    // Map to intel_gpu_top format...
}

// Clients
foreach ($device['clis_stats'] as $client) {
    $pid = $client['pid'];
    $name = $client['comm'];
    // Process client data...
}
```

## Testing Notes

### Test Environment
- **GPU:** Intel Arc A770 (discrete)
- **PCI:** 0000:04:00.0
- **Driver:** xe
- **Kernel:** Likely 6.8+

### Observations
- GPU was idle during test (eng_usage empty)
- Power readings were 0.0 W (idle state)
- Temperatures: 53°C package, 52°C VRAM
- Fan: 1451 RPM
- No active DRM clients

### To Test Next
- GPU under load (encoding, gaming, compute)
- Multiple clients using GPU
- Engine utilization metrics populated
- Power draw under load
- Throttling scenarios

## Updated Implementation Status

### ✅ Completed
- JSONL parsing (read line 3)
- Navigate devs_state[0].dev_stats
- Frequency mapping (cur_freq, act_freq)
- Power mapping (gpu_cur_power, pkg_cur_power)
- Engine name mapping (RCS/BCS/VCS/VECS/CCS)
- Client parsing (clis_stats)

### ⚠️ Needs Testing
- Engine usage with active GPU load
- Client detection with active processes
- Temperature/fan data extraction (available but not used yet)
- Memory usage reporting
- Throttle status interpretation

### 📝 Future Enhancements
- Use temperature data from qmassa (temps array)
- Use fan data from qmassa (fans array)
- Parse throttle_reasons for power state info
- Support multi-GT frequency reporting
- VRAM usage for discrete GPUs

---

**Document Created:** 2026-05-07  
**qmassa Version:** 2.0  
**Last Updated:** 2026-05-07
