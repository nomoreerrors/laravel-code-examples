<?php

namespace App\Services;

use App\Managers\PostBuilderManager;
use App\Classes\MetaAggregator;
use App\Services\{VkBotApi};
use Illuminate\Support\Facades\Log;
use App\Models\{Chat};
use App\Exceptions\ForwardDisabledException;
use App\Exceptions\ReplyMessageNotFoundException;
use App\Events\VkForwardDisabled;
use App\Services\BaseVkTransfer;

class VkForwarder extends BaseVkTransfer
{
    public function __construct(
        private MetaAggregator $metaAggregator,
        private PostBuilderManager $postBuilderManager,
        private VkBotApi $bot,
    ) {
        parent::__construct($metaAggregator, $postBuilderManager, $bot);
    }

    public function forward(
        Chat $baseChat,
        Chat $targetChat,
        int $updateId,
    ): ?int {
        $attachments = $this->metaAggregator->get(
            $targetChat->platform->name,
            $updateId,
            $targetChat->chat_id,
        );

        $config = $this->prepare(
            $baseChat,
            $targetChat,
            $updateId,
            $attachments,
            false
        );

        try {
            $response = $this->bot->send(...$config);
        } catch (ForwardDisabledException $e) {
            event(new VkForwardDisabled(
                $targetChat->socialAccount->platform_user_id,
                $targetChat->socialAccount->registration_platform_id
            ));
            return null;
        }

        $cmid = $response->json('response.0.conversation_message_id');

        if (!$cmid) {
            logger()->error('- не удалось получить cmid для создания связи постов', [
                'response' => $response->json(),
            ]);
        }

        return $cmid;
    }
}
