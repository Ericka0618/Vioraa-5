<?php
/** Shared sessions survive Cloud Run restarts; MySQL locks serialize one session. */
final class VioraMysqlSessions implements SessionHandlerInterface {
    private mysqli $db;
    private ?string $lock = null;
    public function open(string $path, string $name): bool {
        $this->db = new mysqli(getenv('DB_HOST') ?: 'localhost', getenv('DB_USER'), getenv('DB_PASSWORD'), getenv('DB_NAME'), (int)(getenv('DB_PORT') ?: 3306), getenv('DB_SOCKET') ?: null);
        return true;
    }
    public function close(): bool {
        if ($this->lock !== null) {
            $q = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $q->bind_param('s', $this->lock); $q->execute();
        }
        $this->db->close(); return true;
    }
    public function read(string $id): string|false {
        $this->lock = 'viora_session_' . substr(hash('sha256', $id), 0, 48);
        $q = $this->db->prepare('SELECT GET_LOCK(?, 30)');
        $q->bind_param('s', $this->lock); $q->execute();
        if ((int)$q->get_result()->fetch_row()[0] !== 1) throw new RuntimeException('Session lock unavailable');
        $q = $this->db->prepare('SELECT payload FROM viora_runtime_sessions WHERE id=? AND expires_at > UNIX_TIMESTAMP()');
        $q->bind_param('s', $id); $q->execute();
        return $q->get_result()->fetch_row()[0] ?? '';
    }
    public function write(string $id, string $data): bool {
        $expires = time() + (int)ini_get('session.gc_maxlifetime');
        $q = $this->db->prepare('INSERT INTO viora_runtime_sessions (id,payload,expires_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),expires_at=VALUES(expires_at)');
        $q->bind_param('ssi', $id, $data, $expires); return $q->execute();
    }
    public function destroy(string $id): bool {
        $q = $this->db->prepare('DELETE FROM viora_runtime_sessions WHERE id=?');
        $q->bind_param('s', $id); return $q->execute();
    }
    public function gc(int $max_lifetime): int|false {
        $this->db->query('DELETE FROM viora_runtime_sessions WHERE expires_at <= UNIX_TIMESTAMP()');
        return $this->db->affected_rows;
    }
}
if (getenv('DB_SESSION_ENABLED') === 'true') session_set_save_handler(new VioraMysqlSessions(), true);
