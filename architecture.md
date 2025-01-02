# Manticore Load Tool Architecture

## Files Structure

### Main Files
- `manticore-load` - Main executable script
- `APP_VERSION` - Contains current version number
- `architecture.md` - Architecture documentation
- `LICENSE` - License information

### Source Files (src/)
- `configuration.php` - Configuration handling
- `console_output.php` - Thread-safe console output
- `progress_display.php` - Progress monitoring and display
- `query_generator.php` - Query generation and caching
- `statistics.php` - Performance statistics collection

## Classes and Components

### Configuration (configuration.php)
- **Class: Configuration**
  - Handles command-line arguments and process configurations
  - Implements ArrayAccess interface
  - Key methods:
    - `parseCommandLine()` - Parses command line arguments
    - `validate()` - Validates configuration parameters
    - `getProcesses()` - Returns array of process configurations
    - `isInsertQuery()` - Determines if query is INSERT/REPLACE

### Console Output (console_output.php)
- **Class: ConsoleOutput**
  - Provides thread-safe console output functionality
  - Key methods:
    - `write()` - Thread-safe writing to STDOUT
    - `writeLine()` - Writes line with newline
    - `init()` - Initializes output semaphore

### Progress Display (progress_display.php)
- **Class: ProgressDisplay**
  - Handles real-time display of loading progress
  - Uses ColorTrait for terminal coloring
  - Key methods:
    - `update()` - Updates progress display
    - `monitorProgressFiles()` - Monitors multiple process progress
    - `formatBytes()` - Formats file sizes
    - `getCpuUsage()` - Gets current CPU usage

### Query Generator (query_generator.php)
- **Class: QueryGenerator**
  - Generates SQL queries based on patterns
  - Handles query caching
  - Key methods:
    - `generateQueries()` - Main query generation method
    - `parsePattern()` - Parses query pattern
    - `generateValue()` - Generates values based on pattern

### Statistics (statistics.php)
- **Class: Statistics**
  - Collects and analyzes performance metrics
  - Key methods:
    - `addOperations()` - Records completed operations
    - `addQps()` - Records queries per second
    - `addLatency()` - Records latency measurements
    - `printReport()` - Outputs performance statistics

- **Class: LatencyHistogram**
  - Memory-efficient latency tracking
  - Key methods:
    - `add()` - Adds latency measurement
    - `getPercentile()` - Calculates percentiles
    - `getAverage()` - Calculates average latency

- **Class: MonitoringStats**
  - Tracks system metrics
  - Key methods:
    - `getStats()` - Retrieves current system metrics
    - `close()` - Closes monitoring connection

## Component Interactions

### Process Flow
1. `manticore-load` script:
   - Parses command line arguments via Configuration
   - Forks worker processes
   - Monitors progress via ProgressDisplay

2. Worker processes:
   - Initialize Manticore connections
   - Generate queries via QueryGenerator
   - Execute queries and collect statistics
   - Report progress via temporary files

3. Progress monitoring:
   - Parent process monitors all worker progress files
   - Combines statistics from all processes
   - Updates display in real-time