<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Services\AiReplyService;
use App\Models\Contact;

class AiChatController extends Controller
{
    protected AiReplyService $aiService;

    public function __construct(AiReplyService $aiService)
    {
        $this->aiService = $aiService;
    }

    public function testChat(Request $request)
    {
        $request->validate([
            'phone' => 'required',
            'message' => 'required'
        ]);

        $name = $request->input('sender_name', 'Unknown');
        $fbProfileUrl = $request->input('fb_profile_url');

        $result = $this->aiService->handle(
            $request->phone, 
            $name, 
            $request->message, 
            $fbProfileUrl,
            $request->input('context', [])
        );

        return response()->json([
            'success' => true,
            'user_message' => $request->message,
            'ai_reply' => $result['ai_reply'],
            'contact' => $result['contact']->name,
            'relation' => $result['contact']->relation_type
        ]);
    }

    /**
     * Baru: Mendapatkan prompt yang sudah dirakit dari Laravel.
     */
    public function getPrompt(Request $request)
    {
        $request->validate([
            'phone' => 'required',
            'message' => 'required'
        ]);

        $name = $request->input('sender_name', 'Unknown');
        $fbProfileUrl = $request->input('fb_profile_url');

        $data = $this->aiService->getPromptForBot(
            $request->phone, 
            $name, 
            $request->message, 
            $fbProfileUrl,
            $request->input('context', [])
        );

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Baru: Menyimpan balasan AI yang dieksekusi di Bot kembali ke DB Laravel.
     */
    public function saveReply(Request $request)
    {
        $request->validate([
            'conversation_id' => 'required',
            'reply' => 'required',
            'model' => 'required'
        ]);

        $this->aiService->saveBotReply(
            $request->conversation_id,
            $request->reply,
            $request->model
        );

        return response()->json(['success' => true]);
    }
}
