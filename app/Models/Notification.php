<?php
declare(strict_types=1);

namespace AnaBot\Models;

use AnaBot\Core\Database;

/**
 * AnaBot Notification Model
 * 
 * Manages user in-app notifications and alerts.
 */
class Notification
{
    /**
     * Create a new notification for a specific user.
     */
    public static function create(
        int $userId,
        string $title,
        string $message,
        string $type = 'info',
        ?string $actionUrl = null
    ): int {
        $db = Database::getInstance();
        $now = date('c');

        $db->execute(
            "INSERT INTO notifications (user_id, title, message, type, is_read, action_url, created_at)
             VALUES (:uid, :title, :msg, :type, 0, :url, :now)",
            [
                ':uid'   => $userId,
                ':title' => $title,
                ':msg'   => $message,
                ':type'  => strtolower($type),
                ':url'   => $actionUrl,
                ':now'   => $now,
            ]
        );

        return (int) $db->lastInsertId();
    }

    /**
     * Get paginated notifications for a user.
     */
    public static function getByUser(
        int $userId,
        int $limit = 20,
        int $offset = 0,
        ?bool $onlyUnread = null
    ): array {
        $db = Database::getInstance();
        $params = [':uid' => $userId, ':limit' => $limit, ':offset' => $offset];
        $whereUnread = '';

        if ($onlyUnread === true) {
            $whereUnread = ' AND is_read = 0';
        } elseif ($onlyUnread === false) {
            $whereUnread = ' AND is_read = 1';
        }

        return $db->queryAll(
            "SELECT * FROM notifications 
             WHERE user_id = :uid {$whereUnread}
             ORDER BY id DESC 
             LIMIT :limit OFFSET :offset",
            $params
        );
    }

    /**
     * Count unread notifications for a user.
     */
    public static function countUnread(int $userId): int
    {
        $db = Database::getInstance();
        $row = $db->queryOne(
            "SELECT COUNT(*) as cnt FROM notifications WHERE user_id = :uid AND is_read = 0",
            [':uid' => $userId]
        );

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Mark a single notification as read.
     */
    public static function markAsRead(int $id, int $userId): bool
    {
        $db = Database::getInstance();
        $affected = $db->execute(
            "UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid",
            [':id' => $id, ':uid' => $userId]
        );

        return $affected > 0;
    }

    /**
     * Mark all notifications as read for a user.
     */
    public static function markAllAsRead(int $userId): int
    {
        $db = Database::getInstance();
        return $db->execute(
            "UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0",
            [':uid' => $userId]
        );
    }

    /**
     * Delete a notification.
     */
    public static function delete(int $id, int $userId): bool
    {
        $db = Database::getInstance();
        $affected = $db->execute(
            "DELETE FROM notifications WHERE id = :id AND user_id = :uid",
            [':id' => $id, ':uid' => $userId]
        );

        return $affected > 0;
    }

    /**
     * Broadcast an alert or notification to all active users.
     */
    public static function broadcastToAllUsers(
        string $title,
        string $message,
        string $type = 'info',
        ?string $actionUrl = null
    ): int {
        $db = Database::getInstance();
        $users = $db->queryAll("SELECT id FROM users WHERE status = 'active'");
        $count = 0;

        foreach ($users as $user) {
            self::create((int) $user['id'], $title, $message, $type, $actionUrl);
            $count++;
        }

        return $count;
    }
}
