<?php
require_once __DIR__ . '/src/configuration.php';
require_once __DIR__ . '/src/query_generator.php';
require_once __DIR__ . '/src/statistics.php';
require_once __DIR__ . '/src/progress_display.php';
require_once __DIR__ . '/src/console_output.php';
require_once __DIR__ . '/src/elasticsearch_client.php';
require_once __DIR__ . '/src/elasticsearch_query_generator.php';

$config = new Configuration([]);
$config->set('load_commands', ['{"id":<increment>,"name":"ab","n":<int/1/10>}']);
$config->set('total', 1);
$config->set('batch-size', 1);
$gen = new ElasticsearchQueryGenerator($config);
$batches = $gen->generateQueries('manticore_load_test');
$body = $batches->current();
$client = new ElasticsearchClient('localhost', 9200, 0, 'elastic', 'password');
$res = $client->request('POST', '_bulk', $body, 'application/x-ndjson');
echo "Status: " . $res['status'] . "\n";
