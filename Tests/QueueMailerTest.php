<?php
declare(strict_types=1);
namespace Viserio\Component\Mail\Tests;

use Mockery;
use Narrowspark\TestingHelper\ArrayContainer;
use Narrowspark\TestingHelper\Phpunit\MockeryTestCase;
use Psr\Container\ContainerInterface;
use Swift_Mailer;
use Swift_Message;
use Swift_Mime_SimpleMessage;
use Swift_Transport;
use Viserio\Component\Contract\Mail\Message as MessageContract;
use Viserio\Component\Contract\Queue\QueueConnector as QueueContract;
use Viserio\Component\Contract\View\Factory as ViewFactoryContract;
use Viserio\Component\Contract\View\View as ViewContract;
use Viserio\Component\Mail\QueueMailer;
use Viserio\Component\Mail\Tests\Fixture\FailingSwiftMailerStub;

class QueueMailerTest extends MockeryTestCase
{
    /**
     * @var \Viserio\Component\Contract\View\Factory|\Mockery\MockInterface
     */
    private $viewFactory;

    /**
     * {@inheritdoc}
     */
    public function setUp()
    {
        parent::setUp();

        $this->viewFactory = $this->mock(ViewFactoryContract::class);
    }

    /**
     * {@inheritdoc}
     */
    protected function assertPreConditions()
    {
        parent::assertPreConditions();

        $this->allowMockingNonExistentMethods(true);
    }

    public function testMailerCanResolveMailerClasses(): void
    {
        $message = $this->mock(MessageContract::class);

        $mockMailer = new class($message) {
            private $message;

            public function __construct($message)
            {
                $this->message = $message;
            }

            public function mail()
            {
                return $this->message;
            }
        };

        $container = $this->mock(ContainerInterface::class);
        $container->shouldReceive('get')
            ->once()
            ->with('FooMailer')
            ->andReturn(function () use ($mockMailer) {
                return $mockMailer;
            });

        $mailer = $this->getMockBuilder(QueueMailer::class)
            ->setConstructorArgs($this->getMocks())
            ->setMethods(['createMessage'])
            ->getMock();
        $mailer->setViewFactory($this->mock(ViewFactoryContract::class));
        $mailer->setContainer($container);

        $mailer->expects($this->once())
            ->method('createMessage')
            ->will($this->returnValue($message));

        $view = $this->mock(ViewContract::class);

        $this->viewFactory->shouldReceive('create')
            ->once()
            ->with('foo', ['data', 'message' => $message])
            ->andReturn($view);

        $mailer->setViewFactory($this->viewFactory);

        $view->shouldReceive('render')
            ->once()
            ->andReturn('rendered.view');

        $message->shouldReceive('setBody')
            ->once()
            ->with('rendered.view', 'text/html');
        $message->shouldReceive('setFrom')
            ->never();

        $this->setSwiftMailer($mailer);

        $mimeMessage = $this->mock(Swift_Mime_SimpleMessage::class);

        $message->shouldReceive('getSwiftMessage')
            ->once()
            ->andReturn($mimeMessage);

        $mailer->getSwiftMailer()
            ->shouldReceive('send')
            ->once()
            ->with($mimeMessage, [])
            ->andReturn(1);
        $mailer->send('foo', ['data'], 'FooMailer');
    }

    public function testGlobalFromIsRespectedOnAllMessages(): void
    {
        unset($_SERVER['__mailer.test']);

        $mailer = $this->getMailer();

        $view = $this->mock(ViewContract::class);

        $this->viewFactory->shouldReceive('create')
            ->once()
            ->andReturn($view);

        $mailer->setViewFactory($this->viewFactory);

        $view->shouldReceive('render')
            ->once()
            ->andReturn('rendered.view');

        $me = $this;

        $this->setSwiftMailer($mailer);

        $mailer->alwaysFrom('info@narrowspark.de', 'Daniel Bannert');
        $mailer->getSwiftMailer()
            ->shouldReceive('send')
            ->once()
            ->with(Mockery::type('Swift_Message'), [])
            ->andReturnUsing(function ($message) use ($me) {
                $me->assertEquals(['info@narrowspark.de' => 'Daniel Bannert'], $message->getFrom());

                return 1;
            });
        $mailer->send('foo', ['data'], function ($mail): void {
        });
    }

    public function testFailedRecipientsAreAppendedAndCanBeRetrieved(): void
    {
        unset($_SERVER['__mailer.test']);

        $mailer = $this->getMailer();
        $mailer->getSwiftMailer()
            ->shouldReceive('getTransport')
            ->andReturn($transport = $this->mock(Swift_Transport::class));

        $transport->shouldReceive('stop');

        $view = $this->mock(ViewContract::class);

        $this->viewFactory->shouldReceive('create')
            ->once()
            ->andReturn($view);

        $mailer->setViewFactory($this->viewFactory);

        $view->shouldReceive('render')
            ->once()
            ->andReturn('rendered.view');

        $swift = new FailingSwiftMailerStub($this->mock(Swift_Transport::class));

        $mailer->setSwiftMailer($swift);
        $mailer->send('foo', ['data'], function ($m): void {
        });

        self::assertEquals(['info@narrowspark.de'], $mailer->failures());
    }

    protected function setSwiftMailer($mailer)
    {
        $transport = $this->mock(Swift_Transport::class);
        $transport->shouldReceive('stop');

        $swift = $this->mock(Swift_Mailer::class);
        $swift->shouldReceive('getTransport')
            ->once()
            ->andReturn($transport);
        $swift->shouldReceive('createMessage')
            ->andReturn(new Swift_Message());

        $mailer->setSwiftMailer($swift);

        return $mailer;
    }

    protected function getMailer()
    {
        $swift = $this->mock(Swift_Mailer::class);
        $swift->shouldReceive('createMessage')
            ->andReturn(new Swift_Message());

        $mailer = new QueueMailer(
            $swift,
            $this->mock(QueueContract::class),
            new ArrayContainer([
                'config' => ['viserio' => ['mail' => []]],
            ])
        );

        return $mailer;
    }

    protected function getMocks(): array
    {
        return [
            $this->mock(Swift_Mailer::class),
            $this->mock(QueueContract::class),
            new ArrayContainer([
                'config' => ['viserio' => ['mail' => []]],
            ]),
        ];
    }
}
