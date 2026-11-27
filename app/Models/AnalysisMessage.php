<?php
declare(strict_types=1);

namespace AnaBot\Models;

use AnaBot\Core\Database;

/**
 * AnaBot Analysis Message Model
 * 
 * Manages user and AI assistant messages within analysis sessions.
 */
class AnalysisMessage
{
    /**
     * Create a new message in a session.
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $now = date('c');

        $db->execute(
            "INSERT INTO analysis_messages 
             (session_id, sender_type, message_content, context_summary, tokens_input, tokens_output, created_at)
             VALUES (:sid, :sender, :content, :context, :ti, :to, :created)",
            [
                ':sid' => $data['session_id'],
                ':sender' => $data['sender_type'] ?? 'user',
                ':content' => $data['message_content'],
                ':context' => $data['context_summary'] ?? null,
                ':ti' => (int) ($data['tokens_input'] ?? 0),
                ':to' => (int) ($data['tokens_output'] ?? 0),
                ':created' => $now,
            ]
        );

        $id = (int) $db->lastInsertId();

        // Update session's updated_at
        AnalysisSession::touch((int) $data['session_id']);

        return $id;
    }

    /**
     * Get all messages for a session.
     */
    public static function getBySession(int $sessionId, int $limit = 100, string $order = 'ASC'): array
    {
        $db = Database::getInstance();
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

        return $db->query(
            "SELECT * FROM analysis_messages 
             WHERE session_id = :sid 
             ORDER BY id {$order} 
             LIMIT :limit",
            [':sid' => $sessionId, ':limit' => $limit]
        );
    }

    /**
     * Get recent conversation history formatted for LLM multi-turn chat.
     * Excludes system messages or internal notes, keeping user and assistant turns.
     */
    public static function getRecentConversationHistory(int $sessionId, int $limit = 10): array
    {
        $db = Database::getInstance();
        
        // Fetch last $limit messages in chronological order
        $messages = $db->query(
            "SELECT sender_type, message_content 
             FROM (
                 SELECT id, sender_type, message_content, created_at
                 FROM analysis_messages
                 WHERE session_id = :sid AND sender_type IN ('user', 'assistant')
                 ORDER BY id DESC
                 LIMIT :limit
             ) sub
             ORDER BY id ASC",
            [':sid' => $sessionId, ':limit' => $limit]
        );

        $history = [];
        foreach ($messages as $msg) {
            $role = ($msg['sender_type'] === 'user') ? 'user' : 'assistant';
            $history[] = [
                'role' => $role,
                'content' => $msg['message_content'],
            ];
        }

        return $history;
    }

    /**
     * Delete a single message.
     */
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        $affected = $db->execute("DELETE FROM analysis_messages WHERE id = :id", [':id' => $id]);
        return $affected > 0;
    }

    /**
     * Clear all messages in a session.
     */
    public static function clearSession(int $sessionId): bool
    {
        $db = Database::getInstance();
        $affected = $db->execute("DELETE FROM analysis_messages WHERE session_id = :sid", [':sid' => $sessionId]);
        return $affected > 0;
    }
}
