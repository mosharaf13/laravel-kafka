<?php

namespace Junges\Kafka\Consumers;

use Closure;
use Illuminate\Support\Collection;
use JetBrains\PhpStorm\Pure;
use Junges\Kafka\Commit\Contracts\Committer;
use Junges\Kafka\Commit\Contracts\CommitterFactory;
use Junges\Kafka\Commit\DefaultCommitterFactory;
use Junges\Kafka\Commit\NativeSleeper;
use Junges\Kafka\Config\Config;
use Junges\Kafka\Contracts\CanConsumeMessages;
use Junges\Kafka\Contracts\KafkaConsumerMessage;
use Junges\Kafka\Contracts\MessageDeserializer;
use Junges\Kafka\Exceptions\KafkaConsumerException;
use Junges\Kafka\Logger;
use Junges\Kafka\Message\ConsumedMessage;
use Junges\Kafka\Message\MessageCounter;
use Junges\Kafka\Retryable;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\Message;
use RdKafka\Producer as KafkaProducer;
use Throwable;

class Consumer implements CanConsumeMessages
{
    private const IGNORABLE_CONSUMER_ERRORS = [
        RD_KAFKA_RESP_ERR__PARTITION_EOF,
        RD_KAFKA_RESP_ERR__TRANSPORT,
        RD_KAFKA_RESP_ERR_REQUEST_TIMED_OUT,
        RD_KAFKA_RESP_ERR__TIMED_OUT,
    ];

    private const TIMEOUT_ERRORS = [
        RD_KAFKA_RESP_ERR_REQUEST_TIMED_OUT,
    ];

    private const IGNORABLE_COMMIT_ERRORS = [
        RD_KAFKA_RESP_ERR__NO_OFFSET,
    ];

    private Logger $logger;
    private KafkaConsumer $consumer;
    private KafkaProducer $producer;
    private MessageCounter $messageCounter;
    private Committer $committer;
    private Retryable $retryable;
    private CommitterFactory $committerFactory;
    private MessageDeserializer $deserializer;
    private bool $stopRequested = false;
    private ?Closure $onStopConsume = null;

    /**
     * @param \Junges\Kafka\Config\Config $config
     * @param \Junges\Kafka\Contracts\MessageDeserializer $deserializer
     * @param \Junges\Kafka\Commit\Contracts\CommitterFactory|null $committerFactory
     */
    public function __construct(private Config $config, MessageDeserializer $deserializer, CommitterFactory $committerFactory = null)
    {
        $this->logger = app(Logger::class);
        $this->messageCounter = new MessageCounter($config->getMaxMessages());
        $this->retryable = new Retryable(new NativeSleeper(), 6, self::TIMEOUT_ERRORS);
        $this->deserializer = $deserializer;

        $this->committerFactory = $committerFactory ?? new DefaultCommitterFactory($this->messageCounter);
    }

    /**
     * Consume messages from a kafka topic in loop.
     */
    public function consume(): void
    {
        $this->cancelStopConsume();
        $this->consumer = app(KafkaConsumer::class, [
            'conf' => $this->setConf($this->config->getConsumerOptions()),
        ]);
        $this->producer = app(KafkaProducer::class, [
            'conf' => $this->setConf($this->config->getProducerOptions()),
        ]);

        $this->committer = $this->committerFactory->make($this->consumer, $this->config);

        $this->consumer->subscribe($this->config->getTopics());

        $batchConfig = $this->config->getBatchConfig();
        if ($batchConfig->isBatchingEnabled()) {
            $batchConfig->getTimer()->start($batchConfig->getBatchReleaseInterval());
        }

        do {
            $this->retryable->retry(fn () => $this->doConsume());
        } while (! $this->maxMessagesLimitReached() && ! $this->stopRequested);

        if ($this->onStopConsume) {
            Closure::fromCallable($this->onStopConsume)();
        }
    }

    /**
     * Requests the consumer to stop after it's finished processing any messages to allow graceful exit
     *
     * @param \Closure|null $onStop
     */
    public function stopConsume(?Closure $onStop = null): void
    {
        $this->stopRequested = true;
        $this->onStopConsume = $onStop;
    }

    /**
     * Will cancel the stopConsume request initiated by calling the stopConsume method
     */
    public function cancelStopConsume(): void
    {
        $this->stopRequested = false;
        $this->onStopConsume = null;
    }

    /**
     * Count the number of messages consumed by this consumer
     *
     * @return int
     */
    #[Pure]
    public function consumedMessagesCount(): int
    {
        return $this->messageCounter->messagesCounted();
    }

    /**
     * Execute the consume method on RdKafka consumer.
     */
    private function doConsume(): void
    {
        $message = $this->consumer->consume(2000);
        $this->handleMessage($message);
    }

    /**
     * Set the consumer configuration.
     *
     * @param array $options
     * @return \RdKafka\Conf
     */
    private function setConf(array $options): Conf
    {
        $conf = new Conf();

        foreach ($options as $key => $value) {
            $conf->set($key, $value);
        }

        return $conf;
    }

    /**
     * Tries to handle the message received.
     */
    private function executeMessage(Message $message): void
    {
        try {
            $consumedMessage = $this->getConsumerMessage($message);

            $this->config->getHandler()->handle($this->deserializer->deserialize($consumedMessage));

            $success = true;
        } catch (Throwable $throwable) {
            $this->logger->error($message, $throwable);
            $success = $this->handleException($throwable, $message);
        }

        $this->commit($message, $success);
    }

    /**
     * Handles batch of consumed messages by checking two conditions:
     * 1) if current batch size is greater than or equals to batch size limit
     * 2) if batch release interval is timed out and current batch size greater than zero
     *
     * @return void
     * @throws Throwable
     */
    private function handleBatch(): void
    {
        $batchConfig = $this->config->getBatchConfig();

        if ($batchConfig->getBatchRepository()->getBatchSize() >= $batchConfig->getBatchSizeLimit()) {
            $this->executeBatch($batchConfig->getBatchRepository()->getBatch());
            $batchConfig->getBatchRepository()->reset();

            return;
        }

        if ($batchConfig->getTimer()->isTimedOut() && $batchConfig->getBatchRepository()->getBatchSize() > 0) {
            $this->executeBatch($batchConfig->getBatchRepository()->getBatch());
            $batchConfig->getBatchRepository()->reset();
        }

        if ($batchConfig->getTimer()->isTimedOut()) {
            $batchConfig->getTimer()->start($batchConfig->getBatchReleaseInterval());
        }
    }

    /**
     * Tries to handle received batch of messages
     *
     * @param Collection $collection
     * @return void
     * @throws Throwable
     */
    private function executeBatch(Collection $collection): void
    {
        try {
            $consumedMessages = $collection
                ->map(
                    fn (Message $message) =>
                        $this->deserializer->deserialize($this->getConsumerMessage($message))
                );

            $this->config->getBatchConfig()->getConsumer()->handle($consumedMessages);

            $collection->each(fn (Message $message) => $this->commit($message, true));
        } catch (Throwable $throwable) {
            $collection->each(function (Message $message) use ($throwable) {
                $this->logger->error($message, $throwable);

                $this->commit($message, $this->handleException($throwable, $message));
            });
        }
    }

    /**
     * Handle exceptions while consuming messages.
     *
     * @param \Throwable $exception
     * @param \RdKafka\Message|ConsumedMessage $message
     * @return bool
     */
    private function handleException(Throwable $exception, Message|KafkaConsumerMessage $message): bool
    {
        try {
            $this->config->getHandler()->failed(
                $message->payload,
                $this->config->getTopics()[0],
                $exception
            );

            return true;
        } catch (Throwable $throwable) {
            if ($exception !== $throwable) {
                $this->logger->error($message, $throwable, 'HANDLER_EXCEPTION');
            }

            return false;
        }
    }

    /**
     * Send a message to the Dead Letter Queue.
     *
     * @param \RdKafka\Message $message
     */
    private function sendToDlq(Message $message): void
    {
        $topic = $this->producer->newTopic($this->config->getDlq());

        if (method_exists($topic, 'producev')) {
            $topic->producev(
                partition: RD_KAFKA_PARTITION_UA,
                msgflags: 0,
                payload: $message->payload,
                key: $this->config->getHandler()->producerKey($message->payload),
                headers: $message->headers
            );
        } else {
            $topic->produce(
                partition: RD_KAFKA_PARTITION_UA,
                msgflags: 0,
                payload: $message->payload,
                key: $this->config->getHandler()->producerKey($message->payload)
            );
        }

        if (method_exists($this->producer, 'flush')) {
            $this->producer->flush(12000);
        }
    }

    /**
     * @param \RdKafka\Message $message
     * @param bool $success
     * @throws \Throwable
     */
    private function commit(Message $message, bool $success): void
    {
        try {
            if (! $success && ! is_null($this->config->getDlq())) {
                $this->sendToDlq($message);
                $this->committer->commitDlq($message);

                return;
            }

            $this->committer->commitMessage($message, $success);
        } catch (Throwable $throwable) {
            if (! in_array($throwable->getCode(), self::IGNORABLE_COMMIT_ERRORS)) {
                $this->logger->error($message, $throwable, 'MESSAGE_COMMIT');

                throw $throwable;
            }
        }
    }

    /**
     * Determine if the max message limit is reached.
     *
     * @return bool
     */
    private function maxMessagesLimitReached(): bool
    {
        return $this->messageCounter->maxMessagesLimitReached();
    }

    /**
     * Handle the message.
     *
     * @throws \Junges\Kafka\Exceptions\KafkaConsumerException
     * @throws \Throwable
     */
    private function handleMessage(Message $message): void
    {
        $batchConfig = $this->config->getBatchConfig();

        if (RD_KAFKA_RESP_ERR_NO_ERROR === $message->err) {
            $this->messageCounter->add();

            if ($batchConfig->isBatchingEnabled()) {
                $batchConfig->getBatchRepository()->push($message);

                $this->handleBatch();

                return;
            }

            $this->executeMessage($message);

            return;
        }

        if ($batchConfig->isBatchingEnabled()) {
            $this->handleBatch();
        }

        if ($this->config->shouldStopAfterLastMessage() && RD_KAFKA_RESP_ERR__PARTITION_EOF === $message->err) {
            $this->stopConsume();
        }

        if (! in_array($message->err, self::IGNORABLE_CONSUMER_ERRORS)) {
            $this->logger->error($message, null, 'CONSUMER');

            throw new KafkaConsumerException($message->errstr(), $message->err);
        }
    }

    private function getConsumerMessage(Message $message): KafkaConsumerMessage
    {
        return app(KafkaConsumerMessage::class, [
            'topicName' => $message->topic_name,
            'partition' => $message->partition,
            'headers' => $message->headers ?? [],
            'body' => $message->payload,
            'key' => $message->key,
            'offset' => $message->offset,
            'timestamp' => $message->timestamp,
        ]);
    }
}
