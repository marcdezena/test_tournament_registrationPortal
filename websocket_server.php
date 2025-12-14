<?php
// websocket_server.php
require __DIR__ . '/vendor/autoload.php';
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class TournamentWebSocket implements MessageComponentInterface {
    protected $clients;
    protected $userConnections = [];

    public function __construct() {
        $this->clients = new \SplObjectStorage;
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        
        if (!$data || !isset($data['type'])) {
            return;
        }

        switch ($data['type']) {
            case 'authenticate':
                if (isset($data['token'])) {
                    // Verify token and get user ID
                    $userId = $this->verifyToken($data['token']);
                    if ($userId) {
                        $this->userConnections[$userId] = $from->resourceId;
                        $from->send(json_encode([
                            'type' => 'authenticated',
                            'success' => true
                        ]));
                    }
                }
                break;
                
            case 'subscribe':
                // Handle subscription to specific channels
                // e.g., tournament updates, match updates, etc.
                break;
        }
    }

    public function onClose(ConnectionInterface $conn) {
        // Remove from user connections
        if (($userId = array_search($conn->resourceId, $this->userConnections)) !== false) {
            unset($this->userConnections[$userId]);
        }
        
        $this->clients->detach($conn);
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }
    
    protected function verifyToken($token) {
        // Implement token verification
        // Return user ID if valid, false otherwise
        return false;
    }
    
    public function sendToUser($userId, $message) {
        if (isset($this->userConnections[$userId])) {
            foreach ($this->clients as $client) {
                if ($client->resourceId === $this->userConnections[$userId]) {
                    $client->send(json_encode($message));
                    break;
                }
            }
        }
    }
}
 
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new TournamentWebSocket()
        )
    ),
    8080
);

echo "WebSocket server running on port 8080\n";
$server->run();