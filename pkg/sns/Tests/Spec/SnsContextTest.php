<?php

namespace Enqueue\Sns\Tests\Spec;

use Enqueue\Sns\SnsAsyncClient;
use Enqueue\Sns\SnsContext;
use Interop\Queue\Spec\ContextSpec;

class SnsContextTest extends ContextSpec
{
    public function testShouldCreateConsumerOnCreateConsumerMethodCall()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SNS transport does not support consumption. You should consider using SQS instead.');

        parent::testShouldCreateConsumerOnCreateConsumerMethodCall();
    }

    protected function createContext()
    {
        $client = $this->createMock(SnsAsyncClient::class);

        return new SnsContext($client, []);
    }
}
