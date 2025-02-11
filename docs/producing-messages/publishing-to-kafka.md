---
title: Publishing to kafka
weight: 5
---

After configuring all your message options, you must use the `send` method, to send the message to kafka.

```php
use Junges\Kafka\Facades\Kafka;

/** @var \Junges\Kafka\Producers\ProducerBuilder $producer */
$producer = Kafka::publishOn('cluster')
    ->onTopic('topic')
    ->withConfigOptions(['key' => 'value'])
    ->withKafkaKey('your-kafka-key')
    ->withKafkaKey('kafka-key')
    ->withHeaders(['header-key' => 'header-value']);

$producer->send();
```