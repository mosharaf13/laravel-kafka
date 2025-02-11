<?php

namespace Junges\Kafka\Tests\Consumers;

use Closure;
use InvalidArgumentException;
use Junges\Kafka\Commit\Contracts\Committer;
use Junges\Kafka\Commit\Contracts\CommitterFactory;
use Junges\Kafka\Commit\VoidCommitter;
use Junges\Kafka\Config\Config;
use Junges\Kafka\Config\Sasl;
use Junges\Kafka\Consumers\Consumer;
use Junges\Kafka\Consumers\ConsumerBuilder;
use Junges\Kafka\Exceptions\KafkaConsumerException;
use Junges\Kafka\Message\Deserializers\JsonDeserializer;
use Junges\Kafka\Tests\Fakes\FakeConsumer;
use Junges\Kafka\Tests\LaravelKafkaTestCase;
use RdKafka\KafkaConsumer;
use RdKafka\Message;

class ConsumerBuilderTest extends LaravelKafkaTestCase
{
    public function testItReturnsAConsumerInstance()
    {
        $consumer = ConsumerBuilder::create('broker')->build();

        $this->assertInstanceOf(Consumer::class, $consumer);
    }

    public function testItCanSubscribeToATopic()
    {
        $consumer = ConsumerBuilder::create('broker');

        $consumer->subscribe('foo');

        $topics = $this->getPropertyWithReflection('topics', $consumer);

        $this->assertEquals(['foo'], $topics);
    }

    public function testItCanUnsubscribeFromATopic()
    {
        $consumer = ConsumerBuilder::create('broker');

        $consumer->subscribe('foo', 'bar');

        $this->assertEquals(['foo', 'bar'], $this->getPropertyWithReflection('topics', $consumer));

        $consumer->unsubscribe('bar');

        $this->assertEquals(['foo'], $this->getPropertyWithReflection('topics', $consumer));
    }

    public function testItDoesNotSubscribeToATopicTwice()
    {
        $consumer = ConsumerBuilder::create('broker');

        $consumer->subscribe('foo', 'foo');

        $topics = $this->getPropertyWithReflection('topics', $consumer);

        $this->assertEquals(['foo'], $topics);
    }

    public function testICanChangeDeserializersOnTheFly()
    {
        $consumer = ConsumerBuilder::create('broker');

        $consumer->usingDeserializer(new JsonDeserializer());

        $deserializer = $this->getPropertyWithReflection('deserializer', $consumer);

        $this->assertInstanceOf(JsonDeserializer::class, $deserializer);
    }

    public function testItCanSubscribeToMoreThanOneTopicsAtOnce()
    {
        $consumer = ConsumerBuilder::create('broker');

        $consumer->subscribe('foo', 'bar');

        $topics = $this->getPropertyWithReflection('topics', $consumer);

        $this->assertEquals(['foo', 'bar'], $topics);

        $consumer = ConsumerBuilder::create('broker');

        $consumer->subscribe(['foo', 'bar']);

        $topics = $this->getPropertyWithReflection('topics', $consumer);

        $this->assertEquals(['foo', 'bar'], $topics);
    }

    public function testItCanSetConsumerGroupId()
    {
        $consumer = ConsumerBuilder::create('broker')->withConsumerGroupId('foo');

        $groupId = $this->getPropertyWithReflection('groupId', $consumer);

        $this->assertEquals('foo', $groupId);
    }

    public function testItThrowsInvalidArgumentExceptionIfCreatingWithInvalidTopic()
    {
        $this->expectException(InvalidArgumentException::class);

        ConsumerBuilder::create('broker', [1234], 'group');
    }

    public function testItCanSaveTheCommitBatchSize()
    {
        $consumer = ConsumerBuilder::create('broker')
            ->withCommitBatchSize(1);

        $commitValue = $this->getPropertyWithReflection('commit', $consumer);

        $this->assertEquals(1, $commitValue);
    }

    public function testItUsesTheCorrectHandler()
    {
        $consumer = ConsumerBuilder::create('broker')->withHandler(new FakeConsumer());

        $this->assertInstanceOf(Consumer::class, $consumer->build());

        $handler = $this->getPropertyWithReflection('handler', $consumer);

        $this->assertInstanceOf(Closure::class, $handler);
    }

    public function testItCanSetMaxMessages()
    {
        $consumer = ConsumerBuilder::create('broker')->withMaxMessages(2);

        $this->assertInstanceOf(Consumer::class, $consumer->build());

        $maxMessages = $this->getPropertyWithReflection('maxMessages', $consumer);

        $this->assertEquals(2, $maxMessages);
    }

    public function testItCanSetMaxCommitRetries()
    {
        $consumer = ConsumerBuilder::create('broker')->withMaxCommitRetries(2);

        $this->assertInstanceOf(Consumer::class, $consumer->build());

        $maxCommitRetries = $this->getPropertyWithReflection('maxCommitRetries', $consumer);

        $this->assertEquals(2, $maxCommitRetries);
    }

    public function testItCanSetTheDeadLetterQueue()
    {
        $consumer = ConsumerBuilder::create('broker')->subscribe('test')->withDlq('test-topic-dlq');

        $this->assertInstanceOf(Consumer::class, $consumer->build());

        $dlq = $this->getPropertyWithReflection('dlq', $consumer);

        $this->assertEquals('test-topic-dlq', $dlq);
    }

    public function testItUsesDlqSuffixIfDlqIsNull()
    {
        $consumer = ConsumerBuilder::create('broker', ['foo'])->withDlq();

        $this->assertInstanceOf(Consumer::class, $consumer->build());

        $dlq = $this->getPropertyWithReflection('dlq', $consumer);

        $this->assertEquals('foo-dlq', $dlq);
    }

    public function testItCanSetSasl()
    {
        $consumer = ConsumerBuilder::create('broker')
            ->withSasl(
                'username',
                'password',
                'mechanisms'
            );

        $this->assertInstanceOf(Consumer::class, $consumer->build());

        $saslConfig = $this->getPropertyWithReflection('saslConfig', $consumer);

        $this->assertEquals(new Sasl(
            'username',
            'password',
            'mechanisms'
        ), $saslConfig);
    }

    public function testItCanAddMiddlewaresToTheHandler()
    {
        $consumer = ConsumerBuilder::create('broker', ['foo'], 'group')
            ->withMiddleware(function ($message, callable $next) {
                $next($message);
            });

        $this->assertInstanceOf(Consumer::class, $consumer->build());

        $middlewares = $this->getPropertyWithReflection('middlewares', $consumer);

        $this->assertIsArray($middlewares);

        $this->assertIsCallable($middlewares[0]);
    }

    public function testItCanAddInvokableClassesAsMiddleware()
    {
        $consumer = ConsumerBuilder::create('broker', ['foo'], 'group')
            ->withMiddleware(new TestMiddleware());

        $this->assertInstanceOf(Consumer::class, $consumer->build());

        $middlewares = $this->getPropertyWithReflection('middlewares', $consumer);

        $this->assertIsArray($middlewares);

        $this->assertIsCallable($middlewares[0]);
    }

    public function testItCanSetSecurityProtocol()
    {
        $consumer = ConsumerBuilder::create('broker', ['foo'], 'group')
            ->withSecurityProtocol('security');

        $this->assertInstanceOf(Consumer::class, $consumer->build());

        $securityProtocol = $this->getPropertyWithReflection('securityProtocol', $consumer);

        $this->assertEquals('security', $securityProtocol);
    }

    public function testItCanSetSecurityProtocolViaSaslConfig()
    {
        $consumer = ConsumerBuilder::create('broker', ['foo'], 'group')
            ->withSasl(
                'username',
                'password',
                'mechanisms',
                'protocol'
            );

        $consummerBuilt = $consumer->build();
        $this->assertInstanceOf(Consumer::class, $consummerBuilt);

        $consumerConfig = $this->getPropertyWithReflection('config', $consummerBuilt);
        $securityProtocol = $this->getPropertyWithReflection('securityProtocol', $consumerConfig);

        $this->assertEquals('protocol', $securityProtocol);
    }

    public function testItCanSetAutoCommit()
    {
        $consumer = ConsumerBuilder::create('broker')->withAutoCommit();

        $this->assertInstanceOf(Consumer::class, $consumer->build());

        $autoCommit = $this->getPropertyWithReflection('autoCommit', $consumer);

        $this->assertTrue($autoCommit);

        $consumer = ConsumerBuilder::create('broker')->withAutoCommit(false);

        $this->assertInstanceOf(Consumer::class, $consumer->build());

        $autoCommit = $this->getPropertyWithReflection('autoCommit', $consumer);

        $this->assertFalse($autoCommit);
    }

    public function testItCanSetStopAfterLastMessage()
    {
        $consumer = ConsumerBuilder::create('broker')->stopAfterLastMessage();

        $this->assertInstanceOf(Consumer::class, $consumer->build());

        $autoCommit = $this->getPropertyWithReflection('stopAfterLastMessage', $consumer);

        $this->assertTrue($autoCommit);

        $consumer = ConsumerBuilder::create('broker')->stopAfterLastMessage(false);

        $this->assertInstanceOf(Consumer::class, $consumer->build());

        $autoCommit = $this->getPropertyWithReflection('stopAfterLastMessage', $consumer);

        $this->assertFalse($autoCommit);
    }

    public function testItCanSetConsumerOptions()
    {
        $consumer = ConsumerBuilder::create('broker')
            ->withOptions([
                'auto.offset.reset' => 'latest',
                'enable.auto.commit' => 'false',
            ]);

        $this->assertInstanceOf(Consumer::class, $consumer->build());

        $options = $this->getPropertyWithReflection('options', $consumer);

        $this->assertIsArray($options);
        $this->assertArrayHasKey('auto.offset.reset', $options);
        $this->assertArrayHasKey('enable.auto.commit', $options);
        $this->assertEquals('latest', $options['auto.offset.reset']);
        $this->assertEquals('false', $options['enable.auto.commit']);
    }

    public function testItCanSpecifyBrokersUsingWithBrokers()
    {
        $consumer = ConsumerBuilder::create('broker')->withBrokers('my-test-broker');

        $this->assertInstanceOf(Consumer::class, $consumer->build());

        $brokers = $this->getPropertyWithReflection('brokers', $consumer);

        $this->assertEquals('my-test-broker', $brokers);
    }

    public function testItCanBuildWithCustomCommitter(): void
    {
        $adhocCommitterFactory = new class implements CommitterFactory {
            public function make(KafkaConsumer $kafkaConsumer, Config $config): Committer
            {
                return new VoidCommitter();
            }
        };
        $consumer = ConsumerBuilder::create('broker')
            ->usingCommitterFactory($adhocCommitterFactory)
            ->build();

        $committerFactory = $this->getPropertyWithReflection('committerFactory', $consumer);
        $this->assertInstanceOf($adhocCommitterFactory::class, $committerFactory);
    }

    public function testItCanBuilderFromConsumerConfig()
    {
        $consumer = ConsumerBuilder::createFromConsumerConfig(config('kafka.consumers.default'));

        $this->assertEquals(['topic1', 'topic2'], $this->getPropertyWithReflection('topics', $consumer));
        $this->assertEquals('topic_dlq', $this->getPropertyWithReflection('dlq', $consumer));
        $this->assertEquals('localhost:9092', $this->getPropertyWithReflection('brokers', $consumer));
        $this->assertEquals('default', $this->getPropertyWithReflection('groupId', $consumer));
        $this->assertEquals('default', $this->getPropertyWithReflection('groupId', $consumer));
        $this->assertEquals('latest', $this->getPropertyWithReflection('options', $consumer)['auto.offset.reset']);
        $this->assertEquals(10, $this->getPropertyWithReflection('maxCommitRetries', $consumer));
        $this->assertNull($this->getPropertyWithReflection('commit', $consumer));
        $this->assertEquals(2, $this->getPropertyWithReflection('maxMessages', $consumer));
        $this->assertEquals('plaintext', $this->getPropertyWithReflection('securityProtocol', $consumer));

        config()->set('kafka.consumers.default.dlq_topic', null);

        $consumer = ConsumerBuilder::createFromConsumerConfig(config('kafka.consumers.default'));

        $this->assertNull($this->getPropertyWithReflection('dlq', $consumer));
    }

    public function testItCantCreateAConsumerWithDlqWithoutSubscribingToAnyTopics()
    {
        $this->expectException(KafkaConsumerException::class);

        ConsumerBuilder::create('broker')->withDlq();
    }
}

class TestMiddleware
{
    public function __invoke(Message $message, callable $next)
    {
        return $next($message);
    }
}
