<?php

namespace App\Services;

use Exception;
use Psr\Log\LogLevel;
use Modules\ChatsRelations\Managers\ChatsRelationsManager;
use Illuminate\Support\Facades\{DB, Log};
use App\Models\{Chat, PostTransfer, Message, ChatTransferTarget};
use Illuminate\Support\Collection;
use App\Enums\PostTransferStatus;
use App\Repositories\ChatRepository;
use App\Repositories\PostRepository;

class TransferService
{
    public function __construct(
        public ChatRepository $chatRepository,
        public PostRepository $postRepository
    ) {}

    /**
     * Create new transfer in DB
     */
    public function createNewTransfer(
        Chat $chat,
        int $updateId,
        int $messageId,
        array $data
    ): void {
        $message = $chat->messages()->create([
            'chat_id' => $chat->id,
            'message_id' => $messageId
        ]);

        $post = $chat->posts()->create(
            [
                'chat_id' => $chat->id,
                'update_id' => $updateId,
                'message_id' => $message->id,
                'data' => $data
            ]
        );

        $chat->targets()->each(function ($target) use ($post) {
            PostTransfer::create([
                'post_id' => $post->id,
                'chat_transfer_target_id' => $target->pivot->id,
                'status' => PostTransferStatus::PENDING
            ]);
        });
    }

    /**
     * Create target chat message and bind it to parent message
     */
    public function bindTargetChatMessage(
        Chat $baseChat,
        Chat $targetChat,
        int $updateId,
        int|array $targetChatMessageId
    ): void {
        $parentMessageId = $baseChat
            ->posts()
            ->where('update_id', $updateId)
            ->first()
            ->message_id;

        $targetChatMessageIds = (array) $targetChatMessageId;

        $count = count($targetChatMessageIds);

        $data = [
            'message_id' => $targetChatMessageIds[0],
            'parent_id' => $parentMessageId,
        ];

        if ($count > 1) {
            $data['meta'] = [
                'attachment_count' => $count
            ];
        }

        if ($parentMessageId) {
            $targetChat->messages()->create($data);
            Log::info(
                "Создана связь между сообщениями чата {$baseChat->title}: {$parentMessageId} "
                    . "и {$targetChat->title}: {$targetChatMessageIds[0]}"
            );
        }
    }

    public function getBindedMessageId(
        Chat $baseChat,
        Chat $targetChat,
        int $replyMessageId
    ): ?int
    // public function getBindedMessageId(int|string $chatId, string $platform, int $replyMessageId): ?int
    {
        logger()->channel('daily')->info(__CLASS__);

        $basePlatform = $baseChat->platform->name;
        $targetPlatform = $targetChat->platform->name;
        $bindedMessageId = null;

        $parentMessage = $baseChat->messages()
            ->where('message_id', $replyMessageId)->first();

        if ($parentMessage) {
            if ($parentMessage->parent_id) {
                // Message was forwarded from remote target chat to current chat previously and 
                // new created mesasge id in target chat was saved and original message id was set as parent_id
                $bindedMessageId = Message::query()
                    ->where('id', $parentMessage->parent_id)
                    ->value('message_id');
            } else {
                // Original message from the same chat was forwarded to target chat
                // so parent_id is null
                $bindedMessageId = Message::query()
                    ->where('parent_id', $parentMessage->id)
                    ->value('message_id');
            }
        }

        $logLevel = $bindedMessageId ? LogLevel::INFO : LogLevel::ERROR;

        Log::log(
            $logLevel,
            "Результат поиcка связанного сообщения на платформе {$targetPlatform}: " . ($bindedMessageId ?? 'not found'),
            [
                'base_chat_replied_msg_id' => $replyMessageId,
                'base_platform' => $basePlatform,
                'target_platform' => $targetPlatform,
                'base_chat_title' => $baseChat->title,
                'target_chat_title' => $targetChat->title,
                'base_chat_id' => $baseChat->id,
                'target_chat_id' => $targetChat->id,
            ]
        );
        return $bindedMessageId;
    }
}
