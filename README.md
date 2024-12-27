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

### Write Example

Insert 1,000,000 documents in batches of 1,000:

```bash
manticore-load \
  --batch-size=1000 \
  --threads=4 \
  --total=1000000 \
  --init="CREATE TABLE test(id bigint, name text, type int)" \
  --load="INSERT INTO test(id,name,type) VALUES(<increment>,'<text/10/100>',<int/1/100>)"
```

### SELECT Example

Run 1,000 search queries:

```bash
manticore-load \
  --threads=4 \
  --total=1000 \
  --load="SELECT * FROM test WHERE MATCH('<text/1/2>')"
```

### Multi-Process Example

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

### Quiet Mode with Custom Column

Run load test with a custom column showing batch size:

```bash
manticore-load \
  --quiet \
  --column=batch/1000 \
  --batch-size=1000 \
  --threads=4 \
  --total=1000000 \
  --load="INSERT INTO test(id,name) VALUES(<increment>,'<text/10/100>')"
```

This will add a "batch" column with value "1000" at the beginning of the output table:

```
batch         Time          Total Docs    Docs/Sec     Avg QPS      p99 QPS      p95 QPS      p5 QPS       p1 QPS       Lat Avg      Lat p50      Lat p95      Lat p99
1000          00:05        50000         10000        95           120          110          80           75           10.5         9.8          15.2         18.4
```

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

### Workload-Specific Options

| Option          | Description                                         |
|------------------|-----------------------------------------------------|
| `--threads`      | Number of concurrent threads                        |
| `--total`        | Total queries or documents to process               |
| `--batch-size`   | Batch size for inserts or replacements              |
| `--iterations`   | Number of times to repeat data generation           |
| `--load`         | SQL command template for the workload               |
| `--init`         | Initial SQL commands (e.g., `CREATE TABLE`)         |
| `--drop`         | Drop the table before starting (applies to target table only) |

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
