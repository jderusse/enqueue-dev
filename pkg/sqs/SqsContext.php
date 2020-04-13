<?php

declare(strict_types=1);

namespace Enqueue\Sqs;

use Aws\Sqs\SqsClient as AwsSqsClient;
use Interop\Queue\Consumer;
use Interop\Queue\Context;
use Interop\Queue\Destination;
use Interop\Queue\Exception\InvalidDestinationException;
use Interop\Queue\Exception\SubscriptionConsumerNotSupportedException;
use Interop\Queue\Exception\TemporaryQueueNotSupportedException;
use Interop\Queue\Message;
use Interop\Queue\Producer;
use Interop\Queue\Queue;
use Interop\Queue\SubscriptionConsumer;
use Interop\Queue\Topic;

class SqsContext implements Context
{
    /**
     * @var SqsClient|SqsAsyncClient
     */
    private $client;

    /**
     * @var array
     */
    private $queueUrls;

    /**
     * @var array
     */
    private $queueArns;

    /**
     * @var array
     */
    private $config;

    /**
     * @param SqsClient|SqsAsyncClient $client
     */
    public function __construct($client, array $config)
    {
        if ($client instanceof SqsClient) {
            @trigger_error(sprintf('Using a "%s" in "%s" is deprecated since 0.10, use "%s" instead.', SqsClient::class, __CLASS__, SqsAsyncClient::class), E_USER_DEPRECATED);
        }

        $this->client = $client;
        $this->config = $config;

        $this->queueUrls = [];
        $this->queueArns = [];
    }

    /**
     * @return SqsMessage
     */
    public function createMessage(string $body = '', array $properties = [], array $headers = []): Message
    {
        return new SqsMessage($body, $properties, $headers);
    }

    /**
     * @return SqsDestination
     */
    public function createTopic(string $topicName): Topic
    {
        return new SqsDestination($topicName);
    }

    /**
     * @return SqsDestination
     */
    public function createQueue(string $queueName): Queue
    {
        return new SqsDestination($queueName);
    }

    public function createTemporaryQueue(): Queue
    {
        throw TemporaryQueueNotSupportedException::providerDoestNotSupportIt();
    }

    /**
     * @return SqsProducer
     */
    public function createProducer(): Producer
    {
        return new SqsProducer($this);
    }

    /**
     * @param SqsDestination $destination
     *
     * @return SqsConsumer
     */
    public function createConsumer(Destination $destination): Consumer
    {
        InvalidDestinationException::assertDestinationInstanceOf($destination, SqsDestination::class);

        return new SqsConsumer($this, $destination);
    }

    public function close(): void
    {
    }

    /**
     * @param SqsDestination $queue
     */
    public function purgeQueue(Queue $queue): void
    {
        InvalidDestinationException::assertDestinationInstanceOf($queue, SqsDestination::class);

        $this->client->purgeQueue([
            '@region' => $queue->getRegion(),
            'QueueUrl' => $this->getQueueUrl($queue),
        ]);
    }

    public function createSubscriptionConsumer(): SubscriptionConsumer
    {
        throw SubscriptionConsumerNotSupportedException::providerDoestNotSupportIt();
    }

    /**
     * @deprecated
     */
    public function getAwsSqsClient(): AwsSqsClient
    {
        @trigger_error('The method is deprecated since 0.10. Do not use pkg\'s internal dependencies.', E_USER_DEPRECATED);

        if (!$this->client instanceof SqsClient) {
            throw new \InvalidArgumentException(sprintf('The injected client in "%s" is a "%s", can not provide a "%s".', __CLASS__, \get_class($this->client), AwsSqsClient::class));
        }

        return $this->client->getAWSClient();
    }

    /**
     * @internal
     *
     * @return SqsAsyncClient|SqsClient
     */
    public function getSqsClient()
    {
        return $this->client;
    }

    /**
     * @deprecated use getAwsSqsClient method
     */
    public function getClient(): AwsSqsClient
    {
        @trigger_error('The method is deprecated since 0.9.2. SqsContext::getAwsSqsClient() method should be used.', E_USER_DEPRECATED);

        return $this->getAwsSqsClient();
    }

    public function getQueueUrl(SqsDestination $destination): string
    {
        if (isset($this->queueUrls[$destination->getQueueName()])) {
            return $this->queueUrls[$destination->getQueueName()];
        }

        $arguments = [
            '@region' => $destination->getRegion(),
            'QueueName' => $destination->getQueueName(),
        ];

        if ($destination->getQueueOwnerAWSAccountId()) {
            $arguments['QueueOwnerAWSAccountId'] = $destination->getQueueOwnerAWSAccountId();
        } elseif (!empty($this->config['queue_owner_aws_account_id'])) {
            $arguments['QueueOwnerAWSAccountId'] = $this->config['queue_owner_aws_account_id'];
        }

        $result = $this->client->getQueueUrl($arguments);

        if ($this->client instanceof SqsAsyncClient) {
            return $this->queueUrls[$destination->getQueueName()] = (string) $result->getQueueUrl();
        }

        // @todo in 0.11 remove below code
        if (false == $result->hasKey('QueueUrl')) {
            throw new \RuntimeException(sprintf('QueueUrl cannot be resolved. queueName: "%s"', $destination->getQueueName()));
        }

        return $this->queueUrls[$destination->getQueueName()] = (string) $result->get('QueueUrl');
    }

    public function getQueueArn(SqsDestination $destination): string
    {
        if (isset($this->queueArns[$destination->getQueueName()])) {
            return $this->queueArns[$destination->getQueueName()];
        }

        $arguments = [
            '@region' => $destination->getRegion(),
            'QueueUrl' => $this->getQueueUrl($destination),
            'AttributeNames' => ['QueueArn'],
        ];

        $result = $this->client->getQueueAttributes($arguments);

        if ($this->client instanceof SqsAsyncClient) {
            if (null === ($arn = $result->getAttributes()['QueueArn'] ?? null)) {
                throw new \RuntimeException(sprintf('QueueArn cannot be resolved. queueName: "%s"', $destination->getQueueName()));
            }

            return $this->queueArns[$destination->getQueueName()] = $arn;
        }

        // @todo in 0.11 remove below code
        if (false == $arn = $result->search('Attributes.QueueArn')) {
            throw new \RuntimeException(sprintf('QueueArn cannot be resolved. queueName: "%s"', $destination->getQueueName()));
        }

        return $this->queueArns[$destination->getQueueName()] = (string) $arn;
    }

    public function declareQueue(SqsDestination $dest): void
    {
        $result = $this->client->createQueue([
            '@region' => $dest->getRegion(),
            'Attributes' => $dest->getAttributes(),
            'QueueName' => $dest->getQueueName(),
        ]);

        if ($this->client instanceof SqsAsyncClient) {
            $this->queueUrls[$dest->getQueueName()] = $result->getQueueUrl();

            return;
        }

        // @todo in 0.11 remove below code
        if (false == $result->hasKey('QueueUrl')) {
            throw new \RuntimeException(sprintf('Cannot create queue. queueName: "%s"', $dest->getQueueName()));
        }

        $this->queueUrls[$dest->getQueueName()] = $result->get('QueueUrl');
    }

    public function deleteQueue(SqsDestination $dest): void
    {
        $this->client->deleteQueue([
            'QueueUrl' => $this->getQueueUrl($dest),
        ]);

        unset($this->queueUrls[$dest->getQueueName()]);
    }
}
