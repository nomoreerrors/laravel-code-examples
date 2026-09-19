<?php

namespace App\Http\Middleware;

use Closure;
use App\Events\MessagePassedFilters;
use App\Exceptions\ChatSettingsNotSet;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Exception;
use App\Exceptions\{ContextException, ChatRules\RestrictedContentException};
use App\Models\Chat;
use Illuminate\Support\Facades\Log;
use App\Managers\{ChatRulesManager, ChatAdminsManager};


class ChatRulesMiddleware
{
    public function __construct(
        private ChatRulesManager $chatRulesManager,
    ) {}
    /**
     * Handle an incoming request.
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Log::channel('daily')->debug(__CLASS__);

        $dto = $request->attributes->get('dto');
        $chat = $request->attributes->get('chat');

        $this->chatRulesManager->driver($chat->platform->name)->ensureChatRulesSetting($chat);

        $service = $this->chatRulesManager->driver($dto->platform);

        if ($service->shouldApplyChatRules($chat, $dto->fromId)) {
            try {
                $service->process($dto, $chat->settings);
            } catch (RestrictedContentException $e) {
                return response($e->getMessage(), 200);
            } catch (\Throwable $e) {
                Log::error(
                    "Неожиданная ошибка: {$e->getMessage()} Пропускаем правила чата.",
                    [
                        $dto->data,
                        __METHOD__,
                    ]
                );
            }
        }

        event(new MessagePassedFilters($dto, $chat));

        return $next($request);
    }
}
