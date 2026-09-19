<?php

namespace Tests\Feature\ForwardPost;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Storage};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use App\Models\{Chat};
use Tests\Traits\{AccountChats};
use Tests\Feature\ForwardPost\DataProvider as ForwardDataProvider;

/**
 * @method void fakeHttp(bool $isAdmin = false, bool $replyMsgExists = true)
 * @method void assertContent(bool $withText)
 * @method void assertReply(array $withReply)
 * @method void setChat()
 * @method void prepareBindedMessages(Chat $chat, bool $isOriginalChatMessage)
 * @property Chat $chat
 * @property array{original: int, remote: int} $expectedNewMessagePair
 */
abstract class BaseForward extends TestCase
{
    use RefreshDatabase;
    use AccountChats,
        ForwardDataProvider;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('temp');

        $this->setChat();
    }

    #[DataProvider('dataProvider')]
    public function testForwardMessage(
        array $data,
        array $withReply = [],
        int $expectedMessageCount = 2,
        bool $isOriginalChatMessage = false,
        bool $isAdmin = false,
        bool $hasText = true
    ): void {
        $this->fakeHttp(isAdmin: $isAdmin);

        if ($withReply) {
            $replyMessageExists = $withReply['existing_message'] ?? false;

            if ($replyMessageExists) {
                $this->prepareBindedMessages(
                    $this->chat,
                    $isOriginalChatMessage,
                );
            }
        }

        $response = $this->post('api/webhook', $data)->assertOk();

        if ($withReply) {
            $this->assertReply($withReply);
        }

        $this->assertContent($hasText);
        $this->assertDatabaseCount('messages', $expectedMessageCount);
        $this->assertNewMessagePairCreated(
            $this->chat,
            ...$this->expectedNewMessagePair,
        );
    }

    /**
     * Messages to reply to should be created
     */
    protected function assertNewMessagePairCreated(
        Chat $chat,
        int $original,
        int $remote
    ): void {
        $this->assertDatabaseHas('messages', [
            'message_id' => $original,
            'parent_id' => null
        ]);

        $this->assertDatabaseHas('messages', [
            'message_id' => $remote,
            'parent_id' => $chat->messages()
                ->where('message_id', $original)
                ->first()
                ->id
        ]);
    }
}
