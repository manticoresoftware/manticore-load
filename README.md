# manticore-load

Manticore Load Emulator is a powerful tool for testing and benchmarking Manticore Search by simulating various workloads. It supports high concurrency and custom query generation, enabling users to assess performance and optimize configurations.

[![asciicast](https://asciinema.org/a/oKBkXMLGSUHEKCm99IE9LHNhX.svg)](https://asciinema.org/a/oKBkXMLGSUHEKCm99IE9LHNhX)

---

## Features

- **High Concurrency:** Simulates high-load scenarios using multiple threads and processes.
- **Custom Query Generation:** Allows creation of dynamic queries with configurable patterns.
- **Helpful Patterns:** Provides helpful patterns for generating random data.
- **Batch Loading:** Efficiently handles large data insertions or replacements in batches.
- **Progress Monitoring:** Displays real-time progress and detailed statistics.
- **Flexible Configuration:** Configurable via command-line arguments for convenience.
- **Latency and QPS Tracking:** Tracks latency percentiles and queries-per-second (QPS) for performance insights.
- **Multi-Process Support:** Runs different workloads simultaneously for comprehensive testing.
- **Histogram-Based Latency Tracking:** Uses histogram-based latency tracking for memory-efficient but approximate percentiles.
- **Precise Latency Tracking:** Uses precise latency tracking for exact percentiles.
- **Graceful Shutdown:** Handles CTRL-C interruption gracefully, ensuring clean termination of all processes and threads.
- **Hyperparameter Testing:** Supports testing multiple thread counts and batch sizes in a single run using comma-separated values (e.g., `--threads=1,2,4,8 --batch-size=1000,10000`).
- **Spreadsheet-Friendly Output:** In quiet mode, produces semicolon-separated output that can be easily copied to spreadsheet applications for charting and analysis.

---

## Requirements

- `manticore-extra` dev package or release version higher than 6.3.8 installed
- Linux or MacOS

## Installation

Just install `manticore-extra` dev package or release version higher than 6.3.8. More details here:
- Release: https://manticoresearch.com/install
- Dev: https://mnt.cr/dev/nightly

### Installation from source
1. Clone the repository:
   ```bash
   git clone https://github.com/manticoresoftware/manticore-load.git
   ```
2. Run:
   ```
   cd manticore-load
   ./manticore-load --help
   ```

---

## Usage

Run `manticore-load` with a variety of options to simulate workloads:

### Basic Examples

#### Write Example

Insert 1,000,000 documents in batches of 1,000:

```bash
manticore-load \
  --batch-size=1000 \
  --threads=4 \
  --total=1000000 \
  --init="CREATE TABLE test(id bigint, name text, type int)" \
  --load="INSERT INTO test(id,name,type) VALUES(<increment>,'<text/10/100>',<int/1/100>)"
```

#### SELECT Example

Run 1,000 search queries:

```bash
manticore-load \
  --threads=4 \
  --total=1000 \
  --load="SELECT * FROM test WHERE MATCH('<text/1/2>')"
```

### Advanced Examples

#### Mixed Workload Example

Run a single process with multiple load statements using a weighted distribution:

```bash
manticore-load \
  --threads=4 \
  --total=10000 \
  --load="SELECT * FROM test WHERE MATCH('<text/1/2>')" \
  --load="SELECT * FROM test WHERE MATCH('<text/10/20>')" \
  --load-distribution=0.3,0.7
```

This runs a single workload that has multiple `--load` templates. For each of the `--total` iterations, `manticore-load` picks one of the templates using `--load-distribution` (defaults to an even split if you omit it), so roughly 30% of the queries come from the first template and 70% from the second. If your templates use `<increment>`, each template keeps its own counter.

If you use `--batch-size > 1` with INSERT/REPLACE templates, `--total` still controls how many rows are generated, but the tool buffers rows per template and only emits a batched statement once a template has accumulated `--batch-size` rows. This means the distribution applies to generated rows (not necessarily to the number of SQL statements), and you may see a smaller “final” batch per template at the end of the run.

Example with batching and mixed templates (INSERT + UPDATE):

```bash
manticore-load \
  --drop \
  --init="create table t(id int, a int)" \
  --total=10 \
  --batch-size=2 \
  --load="insert into t values(<increment>, <int/1/10>)" \
  --load="update t set a = <int/100/200> where a = <int/1/5>"
```

One possible set of prepared queries (showing how INSERT rows are batched and UPDATEs are interleaved):

```sql
update t set a = 102 where a = 5;
update t set a = 112 where a = 1;
insert into t values(1, 8),(2, 4);
insert into t values(3, 5),(4, 5);
update t set a = 143 where a = 3;
update t set a = 106 where a = 5;
update t set a = 152 where a = 2;
insert into t values(5, 8)
```

#### Multi-Process Example

Run multiple workloads simultaneously:

```bash
manticore-load \
  --host=127.0.0.1 \
  --port=9306 \
  --batch-size=10000 \
  --threads=5 \
  --total=1000000 \
  --drop \
  --init="CREATE TABLE t(f text, age int, color string, j json)" \
  --load="INSERT INTO t(id, f, age, color, j) VALUES(0, '<text/10/100>', <int/5/90>, '<string/3/10>', '{\"a\": <int/1/100>}')" \
  \
  --together \
  --threads=10 \
  --total=10000 \
  --load="SELECT * FROM t WHERE MATCH('<text/1/2>')" \
  --together \
  \
  --threads=10 \
  --total=10000 \
  --load="SELECT * FROM t WHERE MATCH('<text/10/20>')"
```

#### Testing Different Threads

Test different thread counts in a single run:

```bash
manticore-load \
  --threads=1,2,4,8 \
  --total=100000 \
  --drop \
  --init="CREATE TABLE test(name text)" \
  --load="SELECT * FROM test WHERE MATCH('<text/1/2>')"
```

#### Comparing Batch Sizes

Compare different batch sizes for inserts:

```bash
manticore-load \
  --batch-size=100,1000,10000 \
  --threads=4 \
  --total=1000000 \
  --drop \
  --init="CREATE TABLE test(name text)" \
  --load="INSERT INTO test(id,name) VALUES(0,'<text/10/100>')"
```

#### Combined Parameters with Quiet Mode

Combine different threads and batch sizes in a single run and run in --quiet mode to get only the final report in compact format:

```bash
manticore-load \
  --threads=1,2,4,8 \
  --batch-size=100,1000,10000 \
  --total=1000000 \
  --drop \
  --init="CREATE TABLE test(name text)" \
  --load="INSERT INTO test(id,name) VALUES(0,'<text/10/100>')" \
  --quiet
```

#### Quiet Mode with Custom Column

Run load test with a custom column showing batch size:

```bash
manticore-load \
  --quiet \
  --column=custom/abc \
  --batch-size=1000 \
  --threads=4 \
  --total=1000000 \
  --drop \
  --init="CREATE TABLE test(name text)" \
  --load="INSERT INTO test(id,name) VALUES(<increment>,'<text/10/100>')"
```

This will add a "batch" column with value "1000" at the beginning of the output table:

```
custom      ; Threads ; Batch     ; Time        ; Total Docs  ; Docs/Sec    ; Avg QPS     ; p99 QPS     ; p95 QPS     ; p5 QPS      ; p1 QPS      ; Lat Avg     ; Lat p50     ; Lat p95     ; Lat p99     ;
abc         ; 4       ; 1000      ; 00:15       ; 1000000     ; 64305       ; 66          ; 171         ; 171         ; 39          ; 39          ; 76.7        ; 77.5        ; 135.0       ; 245.0       ;
```

#### Query with Delay

Run queries with a 1s delay between them:

```bash
manticore-load \
  --threads=4 \
  --total=1000 \
  --delay=1 \
  --load="SELECT * FROM test WHERE MATCH('<text/1/2>')"
```

#### Quiet Mode with JSON Output

Run load test with JSON output format:

```bash
manticore-load \
  --quiet \
  --json \
  --batch-size=1000 \
  --threads=4 \
  --total=1000000 \
  --drop \
  --init="CREATE TABLE test(name text)" \
  --load="INSERT INTO test(id,name) VALUES(<increment>,'<text/10/100>')"
```

This will output statistics in JSON format:

```json
{
    "threads": 4,
    "batch_size": 1000,
    "time": "00:15",
    "total_operations": 1000000,
    "operations_per_second": 64305,
    "qps": {
        "avg": 66,
        "p99": 171,
        "p95": 171,
        "p5": 39,
        "p1": 39
    },
    "latency": {
        "avg": 76.7,
        "p50": 77.5,
        "p95": 135.0,
        "p99": 245.0
    }
}
```

Note: The `--json` option can only be used with `--quiet`. If used without `--quiet`, an error will be shown.

---

## Configuration Options

### Global Options

| Option        | Description                             |
|---------------|-----------------------------------------|
| `--host`      | Manticore host (default: `127.0.0.1`)   |
| `--port`      | Manticore port (default: `9306`)        |
| `--quiet`     | Suppress output                        |
| `--verbose`   | Show detailed progress and queries     |
| `--no-color`  | Disable terminal colorization          |
| `--column`    | Add custom column in quiet mode output (format: name/value) |
| `--json`      | Output statistics in JSON format (requires --quiet) |

### Workload-Specific Options

| Option          | Description                                         |
|------------------|-----------------------------------------------------|
| `--threads`      | Number of concurrent threads (single value or comma-separated list, e.g., "1,2,4,8") |
| `--total`        | Total queries or documents to process               |
| `--batch-size`   | Batch size for inserts or replacements (single value or comma-separated list, e.g., "100,1000,10000") |
| `--iterations`   | Number of times to repeat data generation           |
| `--load`         | SQL command template for the workload (repeatable)  |
| `--load-distribution` | Comma-separated weights for multiple `--load` values (default: even split) |
| `--init`         | Initial SQL commands (e.g., `CREATE TABLE`)         |
| `--drop`         | Drop the table before starting (applies to target table only) |
| `--delay`        | Delay in seconds between queries (default: 0)  |

### Advanced Patterns

| Pattern                    | Description                                      |
|---------------------------|--------------------------------------------------|
| `<increment/N>`           | Auto-incrementing value starting from N (default: 1) |
| `<string/N/M>`            | Random string with length between N and M         |
| `<text/N/M>`              | Random text with N to M words                    |
| `<text/{path}/MIN/MAX>`   | Random text using words from file, MIN to MAX words |
| `<int/N/M>`               | Random integer between N and M                   |
| `<array/size_min/size_max/val_min/val_max>` | Array of random integers, size between size_min and size_max, values between val_min and val_max |
| `<array_float/size_min/size_max/val_min/val_max>` | Array of random floats, size between size_min and size_max, values between val_min and val_max |

---

## Monitoring and Statistics

The tool provides real-time feedback, including:

- **Time:** Elapsed time since the workload started.
- **Progress:** Percentage of completed queries or documents.
- **QPS:** Queries per second.
- **Latency:** Percentiles and average latencies.
- **System Metrics:** Threads, CPU usage, and more.

---

## License

This project is licensed under the MIT License. See the [LICENSE](./LICENSE) file for more information.

---

## Contributing

Contributions are welcome! Please open issues or submit pull requests on GitHub.
