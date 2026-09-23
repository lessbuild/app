<?php

namespace App\Modules\Monitor\Services;

use Illuminate\Support\Str;

final class MetricCollectorProfiles
{
    /** @return array<string, array<string, mixed>> */
    public function all(string $metricsEndpoint): array
    {
        $token = '${env:BEACON_INGEST_TOKEN}';
        $exporter = <<<YAML
exporters:
  otlp_http:
    metrics_endpoint: {$metricsEndpoint}
    headers:
      Authorization: Bearer {$token}
    encoding: json
YAML;

        return [
            'host' => [
                'label' => 'Linux, macOS or Windows host',
                'receiver' => 'host_metrics',
                'stability' => 'Beta metrics',
                'requirements' => 'Run the contrib, core or Kubernetes Collector distribution on the host.',
                'yaml' => $this->configuration(<<<'YAML'
receivers:
  host_metrics:
    collection_interval: 10s
    scrapers:
      cpu: {}
      disk: {}
      filesystem: {}
      load: {}
      memory: {}
      network: {}
      paging: {}
      processes: {}
      process: {}
      system: {}
YAML, $exporter, 'host_metrics'),
            ],
            'prometheus' => [
                'label' => 'Prometheus-compatible endpoint',
                'receiver' => 'prometheus',
                'stability' => 'Beta metrics',
                'requirements' => 'Use the core, contrib or Kubernetes Collector. Expose the metrics endpoint on a private network, set PROMETHEUS_TARGET to one host:port, and choose a stable PROMETHEUS_JOB_NAME for the service. The profile defaults to HTTPS and keeps certificate verification enabled. This receiver is work in progress; identical Collector replicas will duplicate scrapes unless you manually shard targets.',
                'yaml' => $this->configuration(<<<'YAML'
receivers:
  prometheus:
    config:
      scrape_configs:
        - job_name: ${env:PROMETHEUS_JOB_NAME:-monitor-target}
          scrape_interval: 30s
          scheme: ${env:PROMETHEUS_SCHEME:-https}
          metrics_path: ${env:PROMETHEUS_METRICS_PATH:-/metrics}
          static_configs:
            - targets:
                - ${env:PROMETHEUS_TARGET}
          tls_config:
            insecure_skip_verify: false
YAML, $exporter, 'prometheus'),
            ],
            'docker' => [
                'label' => 'Docker containers',
                'receiver' => 'docker_stats',
                'stability' => 'Alpha metrics',
                'requirements' => 'Mount the Docker socket into the Collector. Docker Stats requires Docker API 1.25 or newer.',
                'yaml' => $this->configuration(<<<'YAML'
receivers:
  docker_stats:
    endpoint: unix:///var/run/docker.sock
    collection_interval: 10s
YAML, $exporter, 'docker_stats'),
            ],
            'kubernetes' => [
                'label' => 'Kubernetes cluster',
                'receiver' => 'k8s_cluster',
                'stability' => 'Beta metrics',
                'requirements' => 'Run one Collector instance per cluster with a service account and the least RBAC permissions required for the resources you choose to observe.',
                'yaml' => $this->configuration(<<<'YAML'
receivers:
  k8s_cluster:
    auth_type: serviceAccount
    collection_interval: 10s
    node_conditions_to_report: [Ready, MemoryPressure]
    allocatable_types_to_report: [cpu, memory, pods]
YAML, $exporter, 'k8s_cluster'),
            ],
            'postgresql' => [
                'label' => 'PostgreSQL',
                'receiver' => 'postgresql',
                'stability' => 'Beta metrics',
                'requirements' => 'Create a least-privilege monitoring user with SELECT on pg_stat_database. Keep credentials in the Collector environment.',
                'yaml' => $this->configuration(<<<'YAML'
receivers:
  postgresql:
    endpoint: ${env:POSTGRESQL_ENDPOINT}
    username: ${env:POSTGRESQL_USERNAME}
    password: ${env:POSTGRESQL_PASSWORD}
    collection_interval: 10s
    databases:
      - ${env:POSTGRESQL_DATABASE}
YAML, $exporter, 'postgresql'),
            ],
            'mysql' => [
                'label' => 'MySQL / MariaDB',
                'receiver' => 'mysql',
                'stability' => 'Beta metrics',
                'requirements' => 'Use the contrib Collector distribution. Supported: MySQL 5.7, 8.0, 8.4 and 9.x; MariaDB 10.5–10.11 and 11.x. The user must be able to run SHOW GLOBAL STATUS. Optional query-sample logs need performance_schema privileges and are not enabled here.',
                'yaml' => $this->configuration(<<<'YAML'
receivers:
  mysql:
    endpoint: ${env:MYSQL_ENDPOINT}
    username: ${env:MYSQL_USERNAME}
    password: ${env:MYSQL_PASSWORD}
    database: ${env:MYSQL_DATABASE}
    collection_interval: 10s
    tls:
      insecure: false
YAML, $exporter, 'mysql'),
            ],
            'mongodb' => [
                'label' => 'MongoDB',
                'receiver' => 'mongodb',
                'stability' => 'Beta metrics',
                'requirements' => 'Use the contrib Collector and a least-privilege account with the clusterMonitor role. Supported versions include MongoDB 4.4, 5.0, 6.0 and 7.0. For Atlas, set MONGODB_SCHEME to mongodb+srv. Query-sample logs and explain plans are not enabled by this metrics-only profile.',
                'yaml' => $this->configuration(<<<'YAML'
receivers:
  mongodb:
    hosts:
      - endpoint: ${env:MONGODB_ENDPOINT}
    scheme: ${env:MONGODB_SCHEME:-mongodb}
    username: ${env:MONGODB_USERNAME}
    password: ${env:MONGODB_PASSWORD}
    auth_source: admin
    collection_interval: 60s
    initial_delay: 1s
    tls:
      insecure: false
YAML, $exporter, 'mongodb'),
            ],
            'elasticsearch' => [
                'label' => 'Elasticsearch',
                'receiver' => 'elasticsearch',
                'stability' => 'Beta metrics',
                'requirements' => 'Use the contrib Collector and Elasticsearch 7.9 or newer. Set ELASTICSEARCH_ENDPOINT to an HTTPS URL on a private network with a valid certificate; keep service credentials in Collector environment variables. When security is enabled, give a dedicated service account the least-privilege cluster monitor privilege (the receiver also accepts the broader manage privilege). This profile collects node and cluster metrics every 30 seconds. Run one receiver per cluster unless you shard node stats and configure master-only cluster scraping to avoid duplicate metrics. Index-level metrics are disabled with indices: [] and can be enabled for a filtered index list if needed. Lengthen the interval for large clusters.',
                'yaml' => $this->configuration(<<<'YAML'
receivers:
  elasticsearch:
    endpoint: ${env:ELASTICSEARCH_ENDPOINT}
    username: ${env:ELASTICSEARCH_USERNAME}
    password: ${env:ELASTICSEARCH_PASSWORD}
    collection_interval: 30s
    initial_delay: 1s
    nodes: ["_all"]
    indices: []
    tls:
      insecure_skip_verify: false
YAML, $exporter, 'elasticsearch'),
            ],
            'kafka' => [
                'label' => 'Apache Kafka',
                'receiver' => 'kafka_metrics',
                'stability' => 'Beta metrics',
                'requirements' => 'Use the contrib Collector and provide a bootstrap broker plus the Kafka ACLs required by the selected scrapers. The sample uses TLS and SASL/SCRAM-SHA-512; adjust authentication to match your cluster. Some individual metrics have development stability.',
                'yaml' => $this->configuration(<<<'YAML'
receivers:
  kafka_metrics:
    brokers:
      - ${env:KAFKA_BOOTSTRAP_SERVER}
    cluster_alias: ${env:KAFKA_CLUSTER_ALIAS:-primary}
    collection_interval: 60s
    initial_delay: 1s
    scrapers:
      - brokers
      - topics
      - consumers
    tls:
      insecure: false
    auth:
      sasl:
        username: ${env:KAFKA_USERNAME}
        password: ${env:KAFKA_PASSWORD}
        mechanism: ${env:KAFKA_SASL_MECHANISM:-SCRAM-SHA-512}
YAML, $exporter, 'kafka_metrics'),
            ],
            'nginx' => [
                'label' => 'NGINX',
                'receiver' => 'nginx',
                'stability' => 'Beta metrics',
                'requirements' => 'Use the contrib Collector, enable ngx_http_stub_status_module, and expose its status endpoint only on a private network reachable by the Collector.',
                'yaml' => $this->configuration(<<<'YAML'
receivers:
  nginx:
    endpoint: ${env:NGINX_STATUS_ENDPOINT}
    collection_interval: 10s
    initial_delay: 1s
YAML, $exporter, 'nginx'),
            ],
            'apache' => [
                'label' => 'Apache HTTP Server',
                'receiver' => 'apache',
                'stability' => 'Beta metrics',
                'requirements' => 'Use the contrib Collector and Apache HTTP Server 2.4.13 or newer. Enable mod_status and expose /server-status?auto only on a private network reachable by the Collector. Metric and attribute names are being migrated behind Collector feature gates; keep dashboards aligned with the names emitted by your Collector version.',
                'yaml' => $this->configuration(<<<'YAML'
receivers:
  apache:
    endpoint: ${env:APACHE_STATUS_ENDPOINT}
    collection_interval: 10s
    initial_delay: 1s
YAML, $exporter, 'apache'),
            ],
            'rabbitmq' => [
                'label' => 'RabbitMQ',
                'receiver' => 'rabbitmq',
                'stability' => 'Beta metrics',
                'requirements' => 'Use the contrib Collector and enable the RabbitMQ Management plugin. Provide a dedicated user with at least monitoring permissions. Keep the Management API on a private network and set RABBITMQ_MANAGEMENT_ENDPOINT to its HTTPS URL. Queue metrics are enabled by default; node and exchange metrics here are opt-in. Current receiver docs list RabbitMQ 3.8 and 3.9 as supported; verify compatibility for newer servers.',
                'yaml' => $this->configuration(<<<'YAML'
receivers:
  rabbitmq:
    endpoint: ${env:RABBITMQ_MANAGEMENT_ENDPOINT}
    username: ${env:RABBITMQ_USERNAME}
    password: ${env:RABBITMQ_PASSWORD}
    collection_interval: 10s
    metrics:
      rabbitmq.node.disk_free:
        enabled: true
      rabbitmq.node.disk_free_limit:
        enabled: true
      rabbitmq.node.disk_free_alarm:
        enabled: true
      rabbitmq.node.mem_used:
        enabled: true
      rabbitmq.node.mem_limit:
        enabled: true
      rabbitmq.node.mem_alarm:
        enabled: true
      rabbitmq.node.fd_used:
        enabled: true
      rabbitmq.node.fd_total:
        enabled: true
      rabbitmq.node.sockets_used:
        enabled: true
      rabbitmq.node.sockets_total:
        enabled: true
      rabbitmq.node.proc_used:
        enabled: true
      rabbitmq.node.proc_total:
        enabled: true
      rabbitmq.exchange.messages.published_in:
        enabled: true
      rabbitmq.exchange.messages.published_out:
        enabled: true
    tls:
      insecure: false
YAML, $exporter, 'rabbitmq'),
            ],
            'sqlserver' => [
                'label' => 'Microsoft SQL Server',
                'receiver' => 'sqlserver',
                'stability' => 'Beta metrics',
                'requirements' => 'Use the contrib Collector and a dedicated SQL login. Set SQLSERVER_DATASOURCE to sqlserver://USER:PASSWORD@HOST:1433?encrypt=true&TrustServerCertificate=false; URL-encode credential characters and keep the datasource secret. Grant VIEW ANY DATABASE and VIEW SERVER STATE (before SQL Server 2022) or VIEW SERVER PERFORMANCE STATE (SQL Server 2022+). The receiver also documents CREATE DATABASE or ALTER ANY DATABASE as alternatives to VIEW ANY DATABASE; choose the least-privilege grant allowed by your policy. All per-index metrics are explicitly disabled because they require CONNECT ANY DATABASE and VIEW ANY DEFINITION.',
                'yaml' => $this->configuration(<<<'YAML'
receivers:
  sqlserver:
    collection_interval: 10s
    metrics:
      sqlserver.index.fragmentation:
        enabled: false
      sqlserver.index.page.count:
        enabled: false
      sqlserver.index.page.utilization:
        enabled: false
      sqlserver.index.record.count:
        enabled: false
      sqlserver.index.search.rate:
        enabled: false
      sqlserver.index.size:
        enabled: false
    datasource: ${env:SQLSERVER_DATASOURCE}
YAML, $exporter, 'sqlserver'),
            ],
            'oracledb' => [
                'label' => 'Oracle Database',
                'receiver' => 'oracledb',
                'stability' => 'Alpha metrics',
                'requirements' => 'Use the contrib Collector. Set ORACLE_DATASOURCE to an oracle://USER:PASSWORD@HOST:1521/SERVICE?SSL=true&SSL%20VERIFY=true datasource; URL-encode credential characters and keep the DSN secret. SSL enables TLS and SSL VERIFY keeps certificate verification on; use a trusted certificate or configure the required Oracle wallet. Keep the database on a private network. Grant the dedicated account SELECT on V_$SESSION, V_$SYSSTAT, V_$RESOURCE_LIMIT, V_$OSSTAT, DBA_TABLESPACES, DBA_DATA_FILES, DBA_TABLESPACE_USAGE_METRICS and V_$SGAINFO. SELECT on V_$INSTANCE and V_$DATABASE adds optional version, role and open-mode resource attributes. Per-PDB metrics and query-sample, top-query and session-wait events are not enabled; they need extra privileges, and query events can expose SQL text. Metrics are alpha. The profile collects every 60 seconds with a 15-second query timeout; increase the timeout for slower databases.',
                'yaml' => $this->configuration(<<<'YAML'
receivers:
  oracledb:
    datasource: ${env:ORACLE_DATASOURCE}
    collection_interval: 60s
    initial_delay: 1s
    timeout: 15s
YAML, $exporter, 'oracledb'),
            ],
            'awscloudwatch' => [
                'label' => 'AWS CloudWatch',
                'receiver' => 'awscloudwatch',
                'stability' => 'Alpha metrics',
                'requirements' => 'Use the contrib Collector and let the AWS SDK use a workload role or credentials file; never put long-lived access keys in this YAML. Set AWS_REGION and AWS_EC2_INSTANCE_ID. This metrics-only profile queries CPUUtilization, NetworkIn and NetworkOut for one EC2 instance every five minutes with 300-second periods and a ten-minute publication delay. It fetches only Average for CPU and Sum for network, limiting GetMetricData to three statistic queries per scrape. Grant cloudwatch:GetMetricData only; explicit queries avoid ListMetrics and CloudWatch Logs APIs. The receiver calls STS GetCallerIdentity to add cloud.account.id when reachable; AWS requires no separate IAM permission for that call.',
                'yaml' => $this->configuration(<<<'YAML'
receivers:
  awscloudwatch:
    region: ${env:AWS_REGION}
    metrics:
      collection_interval: 5m
      period: 300s
      delay: 10m
      queries:
        - namespace: AWS/EC2
          metric_name: CPUUtilization
          dimensions:
            InstanceId: ${env:AWS_EC2_INSTANCE_ID}
          stats:
            - Average
        - namespace: AWS/EC2
          metric_name: NetworkIn
          dimensions:
            InstanceId: ${env:AWS_EC2_INSTANCE_ID}
          stats:
            - Sum
        - namespace: AWS/EC2
          metric_name: NetworkOut
          dimensions:
            InstanceId: ${env:AWS_EC2_INSTANCE_ID}
          stats:
            - Sum
YAML, $exporter, 'awscloudwatch'),
            ],
            'awslambda' => [
                'label' => 'AWS Lambda (CloudWatch)',
                'receiver' => 'awscloudwatch',
                'stability' => 'Alpha metrics',
                'requirements' => 'Use the contrib Collector with an AWS SDK workload role or credentials file; never put long-lived access keys in this YAML. Set AWS_REGION and AWS_LAMBDA_FUNCTION_NAME for one function. Metrics use one-minute resolution and an adjustable 20-minute default delay; set AWS_LAMBDA_METRIC_DELAY longer than the function runtime plus CloudWatch publication latency (use longer for functions near AWS Lambda\'s 15-minute maximum). Grant cloudwatch:GetMetricData only. Explicit queries avoid ListMetrics and CloudWatch Logs APIs. The receiver calls STS GetCallerIdentity to add cloud.account.id when reachable; AWS requires no separate IAM permission for that call.',
                'yaml' => $this->configuration(<<<'YAML'
receivers:
  awscloudwatch:
    region: ${env:AWS_REGION}
    metrics:
      collection_interval: 1m
      period: 60s
      delay: ${env:AWS_LAMBDA_METRIC_DELAY:-20m}
      queries:
        - namespace: AWS/Lambda
          metric_name: Invocations
          dimensions:
            FunctionName: ${env:AWS_LAMBDA_FUNCTION_NAME}
          stats:
            - Sum
        - namespace: AWS/Lambda
          metric_name: Errors
          dimensions:
            FunctionName: ${env:AWS_LAMBDA_FUNCTION_NAME}
          stats:
            - Sum
        - namespace: AWS/Lambda
          metric_name: Throttles
          dimensions:
            FunctionName: ${env:AWS_LAMBDA_FUNCTION_NAME}
          stats:
            - Sum
        - namespace: AWS/Lambda
          metric_name: Duration
          dimensions:
            FunctionName: ${env:AWS_LAMBDA_FUNCTION_NAME}
          stats:
            - p95
        - namespace: AWS/Lambda
          metric_name: ConcurrentExecutions
          dimensions:
            FunctionName: ${env:AWS_LAMBDA_FUNCTION_NAME}
          stats:
            - Maximum
YAML, $exporter, 'awscloudwatch'),
            ],
            'awsrds' => [
                'label' => 'AWS RDS (CloudWatch)',
                'receiver' => 'awscloudwatch',
                'stability' => 'Alpha metrics',
                'requirements' => 'Use the contrib Collector and an AWS SDK workload role or credentials file; never put long-lived access keys in this YAML. Set AWS_REGION and AWS_RDS_DB_INSTANCE_IDENTIFIER for one RDS DB instance. This profile covers instance-level metrics, including Aurora DB instances; it does not query Aurora cluster-level metrics, Enhanced Monitoring, Performance Insights or RDS APIs. It makes nine explicit metric-statistic queries every five minutes using 300-second periods and the standard ten-minute publication delay. Grant cloudwatch:GetMetricData only; explicit queries avoid ListMetrics and CloudWatch Logs APIs. STS GetCallerIdentity may add cloud.account.id when reachable and needs no separate IAM permission.',
                'yaml' => $this->configuration(<<<'YAML'
receivers:
  awscloudwatch:
    region: ${env:AWS_REGION}
    metrics:
      collection_interval: 5m
      period: 300s
      delay: 10m
      queries:
        - namespace: AWS/RDS
          metric_name: CPUUtilization
          dimensions:
            DBInstanceIdentifier: ${env:AWS_RDS_DB_INSTANCE_IDENTIFIER}
          stats:
            - Average
        - namespace: AWS/RDS
          metric_name: DatabaseConnections
          dimensions:
            DBInstanceIdentifier: ${env:AWS_RDS_DB_INSTANCE_IDENTIFIER}
          stats:
            - Average
        - namespace: AWS/RDS
          metric_name: FreeableMemory
          dimensions:
            DBInstanceIdentifier: ${env:AWS_RDS_DB_INSTANCE_IDENTIFIER}
          stats:
            - Average
        - namespace: AWS/RDS
          metric_name: FreeStorageSpace
          dimensions:
            DBInstanceIdentifier: ${env:AWS_RDS_DB_INSTANCE_IDENTIFIER}
          stats:
            - Minimum
        - namespace: AWS/RDS
          metric_name: DiskQueueDepth
          dimensions:
            DBInstanceIdentifier: ${env:AWS_RDS_DB_INSTANCE_IDENTIFIER}
          stats:
            - Maximum
        - namespace: AWS/RDS
          metric_name: ReadIOPS
          dimensions:
            DBInstanceIdentifier: ${env:AWS_RDS_DB_INSTANCE_IDENTIFIER}
          stats:
            - Average
        - namespace: AWS/RDS
          metric_name: ReadLatency
          dimensions:
            DBInstanceIdentifier: ${env:AWS_RDS_DB_INSTANCE_IDENTIFIER}
          stats:
            - p90
        - namespace: AWS/RDS
          metric_name: WriteIOPS
          dimensions:
            DBInstanceIdentifier: ${env:AWS_RDS_DB_INSTANCE_IDENTIFIER}
          stats:
            - Average
        - namespace: AWS/RDS
          metric_name: WriteLatency
          dimensions:
            DBInstanceIdentifier: ${env:AWS_RDS_DB_INSTANCE_IDENTIFIER}
          stats:
            - p90
YAML, $exporter, 'awscloudwatch'),
            ],
            'awssqs' => [
                'label' => 'AWS SQS (CloudWatch)',
                'receiver' => 'awscloudwatch',
                'stability' => 'Alpha metrics',
                'requirements' => 'Use the contrib Collector and an AWS SDK workload role or credentials file; never put long-lived access keys in this YAML. Set AWS_REGION and AWS_SQS_QUEUE_NAME for one standard or FIFO queue. This profile queries seven explicit metrics every five minutes with 60-second periods and a 20-minute delay. CloudWatch values are approximate and may be missing after a queue has been inactive for six hours; SQS can take up to 15 minutes after activation to resume publishing. ApproximateAgeOfOldestMessage can exclude poison-pill messages from standard queues and resets when messages move to a DLQ. Message counters can include retries or duplicates, and NumberOfMessagesSent excludes automatic DLQ redrive. Grant cloudwatch:GetMetricData only; explicit queries avoid ListMetrics, SQS APIs and CloudWatch Logs. STS GetCallerIdentity may add cloud.account.id when reachable and needs no separate IAM permission.',
                'yaml' => $this->configuration(<<<'YAML'
receivers:
  awscloudwatch:
    region: ${env:AWS_REGION}
    metrics:
      collection_interval: 5m
      period: 60s
      delay: 20m
      queries:
        - namespace: AWS/SQS
          metric_name: ApproximateNumberOfMessagesVisible
          dimensions:
            QueueName: ${env:AWS_SQS_QUEUE_NAME}
          stats:
            - Average
        - namespace: AWS/SQS
          metric_name: ApproximateNumberOfMessagesNotVisible
          dimensions:
            QueueName: ${env:AWS_SQS_QUEUE_NAME}
          stats:
            - Average
        - namespace: AWS/SQS
          metric_name: ApproximateNumberOfMessagesDelayed
          dimensions:
            QueueName: ${env:AWS_SQS_QUEUE_NAME}
          stats:
            - Average
        - namespace: AWS/SQS
          metric_name: ApproximateAgeOfOldestMessage
          dimensions:
            QueueName: ${env:AWS_SQS_QUEUE_NAME}
          stats:
            - Maximum
        - namespace: AWS/SQS
          metric_name: NumberOfMessagesSent
          dimensions:
            QueueName: ${env:AWS_SQS_QUEUE_NAME}
          stats:
            - Sum
        - namespace: AWS/SQS
          metric_name: NumberOfMessagesReceived
          dimensions:
            QueueName: ${env:AWS_SQS_QUEUE_NAME}
          stats:
            - Sum
        - namespace: AWS/SQS
          metric_name: NumberOfMessagesDeleted
          dimensions:
            QueueName: ${env:AWS_SQS_QUEUE_NAME}
          stats:
            - Sum
YAML, $exporter, 'awscloudwatch'),
            ],
            'redis' => [
                'label' => 'Redis',
                'receiver' => 'redis',
                'stability' => 'Beta metrics',
                'requirements' => 'Provide the Redis endpoint and credentials through Collector environment variables; enable TLS for private production traffic.',
                'yaml' => $this->configuration(<<<'YAML'
receivers:
  redis:
    endpoint: ${env:REDIS_ENDPOINT}
    username: ${env:REDIS_USERNAME}
    password: ${env:REDIS_PASSWORD}
    collection_interval: 10s
    tls:
      insecure: false
YAML, $exporter, 'redis'),
            ],
        ];
    }

    private function configuration(string $receiver, string $exporter, string $receiverName): string
    {
        return Str::of($receiver."\n".$exporter."\n".<<<'YAML'
service:
  pipelines:
    metrics:
      receivers: [RECEIVER_NAME]
      exporters: [otlp_http]
YAML)->replace('RECEIVER_NAME', $receiverName)->trim()->toString()."\n";
    }
}
