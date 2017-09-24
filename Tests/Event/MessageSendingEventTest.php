<?php
declare(strict_types=1);
namespace Viserio\Component\Mail\Tests\Event;

use Narrowspark\TestingHelper\Phpunit\MockeryTestCase;
use Swift_Mime_SimpleMessage;
use Viserio\Component\Contract\Mail\Mailer as MailerContract;
use Viserio\Component\Mail\Event\MessageSendingEvent;

class MessageSendingEventTest extends MockeryTestCase
{
    /**
     * @var \Viserio\Component\Mail\Event\MessageSendingEvent
     */
    private $event;

    public function setUp(): void
    {
        $this->event = new MessageSendingEvent(
            $this->mock(MailerContract::class),
            $this->mock(Swift_Mime_SimpleMessage::class)
        );
    }

    public function testGetMessage(): void
    {
        self::assertInstanceOf(Swift_Mime_SimpleMessage::class, $this->event->getMessage());
    }
}