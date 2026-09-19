<?php

namespace Tests\Feature\ForwardPost\VkToTelegram;

use Tests\Traits\{VkDataGenerator, VkToTelegramFaker};
use App\Models\{Chat, Message};
use Tests\Feature\ForwardPost\BaseForward;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Traits\ChatMessages;

/**
 * @property string $forwardMethod
 * @method string getText()
 */
abstract class Base extends BaseForward
{
    use VkDataGenerator,
        ChatMessages,
        VkToTelegramFaker;

    protected string $baseChatId = self::DEFAULT_VK_CHAT_ID;
    protected int $basePlatform = self::VK_PLATFORM;

    protected int $targetChatId = self::DEFAULT_TELEGRAM_CHAT_ID;
    protected array $content = [];
    protected array $expectedNewMessagePair = [
        'original' => self::DEFAULT_VK_CMID,
        'remote' => self::DEFAULT_TELEGRAM_SEND_MESSAGE_ID_FROM_RESPONSE
    ];

    protected function assertMediaAttached(
        array $data,
        string $type
    ): void {
        $this->assertSame(
            "test_{$type}_file_id",
            $data[$type],
            "Некорректный {$type}"
        );
    }

    protected function assertBaseContentSent(array $data, bool $hasText): void
    {
        try {
            $this->assertChatIdIsSet($data);
            $this->assertUserLinkSent($data['text'] ?? $data['caption']);
            $this->assertParseModeIsSet($data);

            if ($hasText) {
                $this->assertBaseTextSent();
            }
        } catch (\Throwable $e) {
            dump('ОШИБКА ОТПРАВКИ ' . strtoupper($this->type) . ' В ТЕЛЕГРАМ');
            throw $e;
        }
    }

    protected function assertUserLinkSent(string $text): void
    {
        $this->assertStringContainsString(
            '<a href="vk.ru/' . self::DEFAULT_VK_USER_DOMAIN . '">'
                . self::DEFAULT_VK_USER_FIRSTNAME
                . ' ' . self::DEFAULT_VK_USER_LASTNAME
                . '</a>',
            $text,
            'Caption не содержит ссылку на пользователя VK'
        );
    }

    protected function assertParseModeIsSet(array $data): void
    {
        $this->assertSame(
            'HTML',
            $data['parse_mode'],
            'Некорректный parse_mode'
        );
    }

    protected function assertChatIdIsSet(array $data): void
    {
        $this->assertSame(
            self::DEFAULT_TELEGRAM_CHAT_ID,
            $data['chat_id'],
            'Некорректный chat_id'
        );
    }

    protected function assertContentUploaded(array $data, string $type): void
    {
        try {
            $this->assertSame(
                'chat_id',
                $data[0]['name'],
                'Первым параметром должен быть chat_id'
            );

            $this->assertSame(
                (int) config('services.telegram.upload_chat_id'),
                $data[0]['contents'],
                'Некорректный chat_id'
            );

            $this->assertSame(
                'caption',
                $data[1]['name'],
                'Вторым параметром должен быть caption'
            );

            $this->assertSame(
                '',
                $data[1]['contents'],
                'caption должен быть пустым при загрузке в чат для предварительной загрузки файлов'
            );

            $this->assertSame(
                $type,
                $data[3]['name'],
                "Четвёртым параметром должен быть {$type}"
            );

            $this->assertSame(
                'fake-binary-content',
                $data[3]['contents'],
                "Содержимое {$type} отличается от ожидаемого"
            );
        } catch (\Throwable $e) {
            dump('ОШИБКА ЗАГРУЗКИ ' . strtoupper($type) . ' В ТЕЛЕГРАМ');
            throw $e;
        }
    }

    protected function getSendRequestData(int $iteration, string $mediaType): array
    {
        $count = 0;
        $apiMethod = 'send' . ucfirst($mediaType);

        $request = Http::recorded(function ($request) use (
            &$count,
            $apiMethod,
            $iteration
        ) {

            if (! Str::is("*{$apiMethod}*", $request->url())) {
                return false;
            }

            $count++;

            return $count === $iteration;
        })->first();

        $this->assertNotEmpty(
            $request,
            "Запрос $apiMethod не был отправлен"
        );

        return $request[0]->data();
    }

    protected function assertQuoteSent(string $text): void
    {
        $this->assertStringContainsString('[Цитата:', $text);
        $this->assertStringContainsString(self::DEFAULT_VK_REPLY_MESSAGE, $text);
        $this->assertStringContainsString(self::DEFAULT_VK_USER_FIRSTNAME . ' ' . self::DEFAULT_VK_USER_LASTNAME, $text);
    }

    protected function assertBaseTextSent(): void
    {
        $this->assertStringContainsString(
            self::DEFAULT_VK_TEXT,
            $this->getText(),
        );
    }

    protected function assertReplyParamsSet(): void
    {
        Http::assertSent(function ($request) {
            $data = $request->data();

            $isSend = Str::is("*{$this->forwardMethod}*", $request->url());

            if (!$isSend) {
                return false;
            }

            $replyParameters = $this->getReplyParams($data);

            if (empty($replyParameters)) {
                return false;
            }

            return $replyParameters['message_id'] === self::DEFAULT_TELEGRAM_MESSAGE_ID;
        });
    }


    protected function assertReply(array $withReply): void
    {
        if ($withReply['existing_message'] ?? false) {
            $this->assertReplyParamsSet();
            return;
        }

        $text = $this->getText();
        $this->assertQuoteSent($text);
    }

    protected function getReplyParams(array $data): array
    {
        $replyParameters = json_decode(data_get($data, 'reply_parameters', ''), true);

        return $replyParameters ?? [];
    }
}
