<?php

namespace Webkul\AiAgent\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\AiAgent\Chat\MessageDisplay;
use Webkul\AiAgent\Chat\ResultRendererRegistry;

/**
 * Handles persistent conversation storage for the AI chat widget.
 */
class ConversationController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(bouncer()->hasPermission('ai-agent.general'), 403, trans('ai-agent::app.common.unauthorized'));

            return $next($request);
        });
    }

    /**
     * List conversations for the current user.
     */
    public function index(): JsonResponse
    {
        $userId = auth()->guard('admin')->id();

        $conversations = DB::table('ai_agent_conversations')
            ->where('user_id', $userId)
            ->latest('updated_at')
            ->limit(50)
            ->get(['id', 'client_id', 'title', 'metadata', 'created_at', 'updated_at']);

        return new JsonResponse(['conversations' => $conversations]);
    }

    /**
     * Load messages for a specific conversation.
     */
    public function show(int $id): JsonResponse
    {
        $userId = auth()->guard('admin')->id();

        $conversation = DB::table('ai_agent_conversations')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (! $conversation) {
            return new JsonResponse(['error' => 'Conversation not found'], 404);
        }

        $messages = DB::table('ai_agent_messages')
            ->where('conversation_id', $id)->orderBy('id')
            ->get(['role', 'content', 'tool_calls', 'tokens_used', 'created_at', 'message_id', 'request_id', 'presentation', 'display_data'])
            ->map(function ($message) {
                $message->presentation = app(ResultRendererRegistry::class)->descriptor(json_decode($message->presentation ?? 'null', true));

                foreach (json_decode($message->display_data ?? '{}', true) ?: [] as $key => $value) {
                    $message->{$key} = $value;
                }
                unset($message->display_data);

                return $message;
            });

        return new JsonResponse([
            'conversation' => $conversation,
            'messages'     => $messages,
        ]);
    }

    /**
     * Save/sync a conversation from the widget.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'conversation_id'              => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'metadata'                     => ['nullable', 'array', 'max:100'],
            'messages.*.tool_calls'        => ['nullable', 'array', 'max:100'],
            'messages.*.tokens_used'       => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'title'                        => ['nullable', 'string', 'max:255'],
            'messages'                     => ['required', 'array', 'max:100'],
            'messages.*.request_id'        => ['nullable', 'uuid'],
            'messages.*.message_id'        => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'messages.*.presentation'      => ['nullable', 'array:type,id'],
            'messages.*.presentation.type' => ['required_with:messages.*.presentation', 'string', 'max:64', 'regex:/^[a-z][a-z0-9.-]*$/'],
            'messages.*.presentation.id'   => ['required_with:messages.*.presentation', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'messages.*.role'              => ['required', 'in:user,assistant,system'],
            'messages.*.content'           => ['present', 'string', 'max:50000'],
        ]);

        $userId = auth()->guard('admin')->id();

        abort_if(strlen(json_encode($request->input('metadata', []))) > 65536, 422);
        foreach ($request->input('messages') as $message) {
            app(MessageDisplay::class)->normalize($message);
            abort_if(strlen(json_encode($message['tool_calls'] ?? [])) > 65536, 422);
        }
        $conversationId = DB::transaction(function () use ($request, $userId) {
            $clientId = $request->input('conversation_id') ?: (string) Str::uuid();
            // The unique (user, client ID) pair handles simultaneous first saves.
            // Lock that row before merging so later saves cannot race the inserts.
            DB::table('ai_agent_conversations')->upsert([
                'user_id'  => $userId, 'client_id' => $clientId,
                'title'    => $request->input('title') ?: 'Chat '.now()->format('M d, H:i'),
                'metadata' => json_encode($request->input('metadata') ?? new \stdClass), 'created_at' => now(), 'updated_at' => now(),
            ], ['user_id', 'client_id'], ['updated_at']);
            $conversation = DB::table('ai_agent_conversations')->where('user_id', $userId)->where('client_id', $clientId)->lockForUpdate()->first();
            $id = $conversation->id;
            // Additive metadata and immutable message identities prevent a delayed snapshot
            // from erasing newer data. Explicit conversation deletion remains a separate route.
            $metadata = json_decode($conversation->metadata ?? '{}', true) ?: [];
            $metadata += $request->input('metadata') ?? [];
            DB::table('ai_agent_conversations')->where('id', $id)->update(['metadata' => json_encode($metadata ?: new \stdClass)]);
            foreach ($request->input('messages') as $index => $m) {
                // Legacy snapshots have no message IDs. Position/role/content gives
                // repeat saves a stable identity; editing a message needs a new ID.
                $messageId = $m['message_id'] ?? hash('sha256', json_encode([$index, $m['role'], $m['content']]));
                $existing = DB::table('ai_agent_messages')->where('conversation_id', $id)->where('message_id', $messageId)->first();
                $display = app(MessageDisplay::class)->normalize($m);
                if ($existing) {
                    if ($existing->role === $m['role'] && $existing->content === $m['content']) {
                        $saved = json_decode($existing->display_data ?? '{}', true) ?: [];
                        // Upgrade old records using locally retained fields; stale saves cannot
                        // replace saved results or turn a handled confirmation back into a prompt.
                        $merged = $saved + $display;
                        if (($saved['_confirmed'] ?? false) || ($display['_confirmed'] ?? false)) {
                            $merged['_confirmed'] = true;
                        }
                        if ($merged !== $saved) {
                            DB::table('ai_agent_messages')->where('id', $existing->id)->update(['display_data' => json_encode($merged)]);
                        }
                    }

                    continue;
                }
                DB::table('ai_agent_messages')->insert([
                    'conversation_id' => $id, 'role' => $m['role'], 'content' => $m['content'],
                    'message_id'      => $messageId, 'request_id' => $m['request_id'] ?? null,
                    'tool_calls'      => isset($m['tool_calls']) ? json_encode($m['tool_calls']) : null,
                    'tokens_used'     => $m['tokens_used'] ?? 0,
                    'presentation'    => json_encode(app(ResultRendererRegistry::class)->descriptor($m['presentation'] ?? null)),
                    'display_data'    => json_encode($display),
                    'created_at'      => now(),
                ]);
            }

            return $id;
        });

        return new JsonResponse([
            'id'    => $conversationId,
            'saved' => true,
        ]);
    }

    /**
     * Delete a conversation.
     */
    public function destroy(int $id): JsonResponse
    {
        $userId = auth()->guard('admin')->id();

        $deleted = DB::table('ai_agent_conversations')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->delete();

        return new JsonResponse(['deleted' => (bool) $deleted]);
    }
}
