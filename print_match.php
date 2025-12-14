<?php
require_once 'config.php';
require_once 'lib/bracket.php';

$tournament_id = intval($_GET['id'] ?? 0);
$round = isset($_GET['round']) ? intval($_GET['round']) : null;
$match_index = isset($_GET['match']) ? intval($_GET['match']) : null;
$auto_print = isset($_GET['auto_print']) ? 1 : 0;

if (!$tournament_id || $round === null || $match_index === null) {
    http_response_code(400);
    echo "Missing parameters";
    exit;
}

// Load tournament
$stmt = $pdo->prepare("SELECT t.*, u.username as creator_name FROM tournaments t JOIN users u ON t.created_by = u.id WHERE t.id = ?");
$stmt->execute([$tournament_id]);
$tournament = $stmt->fetch();
if (!$tournament) {
    http_response_code(404);
    echo "Tournament not found";
    exit;
}

// Load bracket data (latest)
$stmt = $pdo->prepare("SELECT * FROM brackets WHERE tournament_id = ? ORDER BY COALESCE(created_at, id) DESC LIMIT 1");
$stmt->execute([$tournament_id]);
$bracket_data = $stmt->fetch();
if (!$bracket_data) {
    http_response_code(404);
    echo "Bracket not found";
    exit;
}
$bracket = json_decode($bracket_data['bracket_data'], true);

// get participant ids for this round
$roundArr = $bracket['rounds'][$round] ?? null;
if (!is_array($roundArr)) {
    http_response_code(404);
    echo "Round not found";
    exit;
}

$a = $roundArr[$match_index * 2] ?? null;
$b = $roundArr[$match_index * 2 + 1] ?? null;

// Fetch participant names
$participant_names = [];
$ids = array_filter([$a, $b], fn($v) => $v !== null);
if (!empty($ids)) {
    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT r.id, COALESCE(tm.name, u.username) as name FROM registrations r LEFT JOIN teams tm ON r.team_id = tm.id LEFT JOIN users u ON r.user_id = u.id WHERE r.id IN ($placeholders)");
    $stmt->execute(array_values($ids));
    while ($row = $stmt->fetch()) {
        $participant_names[$row['id']] = $row['name'];
    }
}

// Fetch match result if exists
$stmt = $pdo->prepare("SELECT * FROM matches WHERE bracket_id = ? AND round = ? AND match_index = ? LIMIT 1");
$stmt->execute([$bracket_data['id'], $round, $match_index]);
$match = $stmt->fetch();

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Print Match - <?php echo htmlspecialchars($tournament['name']); ?></title>
<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #fff; color: #222; }
    .container { max-width: 800px; margin: 0 auto; }
    .header { text-align: center; margin-bottom: 1rem; }
    .meta { color: #666; font-size: 0.95rem; }
    .card { border: 1px solid #ddd; padding: 18px; border-radius: 6px; margin-top: 1rem; }
    .participant { display:flex; justify-content:space-between; padding: 10px 0; border-bottom:1px solid #f0f0f0; }
    .participant:last-child { border-bottom:none; }
    .winner { background:#e8f5e9; font-weight:700; }
    .actions { text-align:center; margin-top: 1rem; }
    .btn { padding: 10px 16px; border-radius:4px; border:none; cursor:pointer; }
    .btn-print { background:#1976d2; color:white; }
    @media print { .no-print { display:none !important; } }
</style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?php echo htmlspecialchars($tournament['name']); ?></h1>
            <div class="meta"><?php echo htmlspecialchars($tournament['game']); ?> | <?php echo date('F j, Y', strtotime($tournament['date'])); ?></div>
            <div class="meta">Match: Round <?php echo $round + 1; ?> — #<?php echo $match_index + 1; ?></div>
        </div>

        <div class="card">
            <div class="participant <?php echo ($match && $match['winner_id'] && $match['winner_id'] == ($a ?? '') ) ? 'winner' : ''; ?>">
                <div class="name"><?php echo $a !== null ? htmlspecialchars($participant_names[$a] ?? "Participant {$a}") : 'BYE'; ?></div>
                <div class="score"><?php echo $match ? intval($match['score_p1']) : ''; ?></div>
            </div>
            <div class="participant <?php echo ($match && $match['winner_id'] && $match['winner_id'] == ($b ?? '') ) ? 'winner' : ''; ?>">
                <div class="name"><?php echo $b !== null ? htmlspecialchars($participant_names[$b] ?? "Participant {$b}") : 'BYE'; ?></div>
                <div class="score"><?php echo $match ? intval($match['score_p2']) : ''; ?></div>
            </div>

            <?php if ($match && !empty($match['winner_id'])): ?>
                <div style="margin-top:12px;color:#555;">Winner: <?php echo htmlspecialchars($participant_names[$match['winner_id']] ?? $match['winner_id']); ?></div>
            <?php endif; ?>

            <div class="actions no-print">
                <button class="btn btn-print" onclick="window.print()">Print</button>
                <a class="btn" href="bracket.php?id=<?php echo $tournament_id; ?>">Back</a>
            </div>
        </div>
    </div>

    <script>
    (function(){
        var auto = <?php echo $auto_print ? 1 : 0; ?>;
        if (auto) setTimeout(function(){ window.print(); }, 400);
    })();
    </script>
</body>
</html>
