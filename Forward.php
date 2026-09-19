<?php

namespace App\Jobs;

use App\Managers\PostForwardManager;
use Context;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Events\MessageForwarded;
use Illuminate\Foundation\Queue\Queueable;
use App\Traits\HasBackOff;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use App\Models\Chat;
use App\Exceptions\{RateLimitException};

class Forward implements ShouldQueue
{
    use Queueable, HasBackOff;

    public int $tries = 11;
    /**
     * Backoff only for jobs waiting in queue to send messages in order
     */
    public function backoff(): array
    {
        return [
            10,
            20,
            30,
            40,
            50,
            60,
            70,
            80,
            90,
            100,
            3600,
        ];
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("forwarding:{$this->baseChat->chat_d}:{$this->baseChat->platform->name}"))
                ->releaseAfter($this->nextBackoff())
                ->expireAfter(120),
        ];
    }

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Chat $baseChat,
        public Chat $targetChat,
        public int $updateId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        PostForwardManager $forwardManager,
    ): void {
        $this->setLogContext();
        $targetPlatform = $this->targetChat->platform->name;

        try {
            $targetChatMessageId = $forwardManager
                ->driver($targetPlatform)
                ->forward(
                    $this->baseChat,
                    $this->targetChat,
                    $this->updateId,
                );

            Log::info(
                "Сообщение успешно отправлено в {$targetPlatform}. Target chat message id: "
                    . ($targetChatMessageId
                        ? (is_array($targetChatMessageId) ? implode(', ', $targetChatMessageId) : $targetChatMessageId)
                        : 'not exists')
            );

            if ($targetChatMessageId) {
                event(new MessageForwarded(
                    $this->baseChat,
                    $this->targetChat,
                    $this->updateId,
                    $targetChatMessageId
                ));
            } else {
                Log::info('failed to forward message. exit');
            }
        } catch (RateLimitException $e) {
            /**
             * Добавить для Telegram обработку rate limit
             */
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            $this->fail($e);
        }
    }

    private function setLogContext()
    {
        Context::add('context_type', $this->targetChat->platform->name);
        Context::add('context_job', basename($this::class) . ':' . $this->updateId);
    }
}
