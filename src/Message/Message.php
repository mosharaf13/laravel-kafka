<?php

namespace Junges\Kafka\Message;

use Illuminate\Contracts\Support\Arrayable;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Pure;
use Junges\Kafka\AbstractMessage;
use Junges\Kafka\Contracts\KafkaProducerMessage;

class Message extends AbstractMessage implements Arrayable, KafkaProducerMessage
{
    /**
     * Creates a new message instance.
     *
     * @param string|null $topicName
     * @param int $partition
     * @return \Junges\Kafka\Message\Message
     */
    #[Pure]
    public static function create(?string $topicName = null, int $partition = RD_KAFKA_PARTITION_UA): KafkaProducerMessage
    {
        return new self($topicName, $partition);
    }

    /**
     * Set a key in the message array.
     *
     * @param string $key
     * @param mixed $message
     * @return \Junges\Kafka\Message\Message
     */
    public function withBodyKey(string $key, mixed $message): Message
    {
        $this->body[$key] = $message;

        return $this;
    }

    /**
     * Unset a key in the message array.
     *
     * @param string $key
     * @return \Junges\Kafka\Message\Message
     */
    public function forgetBodyKey(string $key): Message
    {
        unset($this->body[$key]);

        return $this;
    }

    /**
     * Set the message headers.
     *
     * @param array $headers
     * @return \Junges\Kafka\Message\Message
     * @return $this
     */
    public function withHeaders(array $headers = []): Message
    {
        $this->headers = $headers;

        return $this;
    }

    /**
     * Set the kafka message key.
     *
     * @param string|null $key
     * @return \Junges\Kafka\Message\Message
     */
    public function withKey(?string $key): Message
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Converts the message to array.
     *
     * @return array
     */
    #[ArrayShape(['payload' => "array", 'key' => "null|string", 'headers' => "array"])]
    public function toArray(): array
    {
        return [
            'payload' => $this->body,
            'key' => $this->key,
            'headers' => $this->headers,
        ];
    }

    /**
     * Set the message body.
     *
     * @param mixed $body
     * @return KafkaProducerMessage
     */
    public function withBody(mixed $body): KafkaProducerMessage
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Set the given message header key with the given value.
     *
     * @param string $key
     * @param mixed $value
     * @return KafkaProducerMessage
     */
    public function withHeader(string $key, mixed $value): KafkaProducerMessage
    {
        $this->headers[$key] = $value;

        return $this;
    }
}
