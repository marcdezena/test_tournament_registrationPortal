<?php
require_once 'config.php';
require_once 'lib/bracket.php';

$tournament_id = intval($_GET['id'] ?? 0);
if (!$tournament_id) {
    redirect('tournaments.php');
}

// Get tournament details
$stmt = $pdo->prepare("
    SELECT t.*, u.username as creator_name
    FROM tournaments t
    JOIN users u ON t.created_by = u.id
    WHERE t.id = ?
");
$stmt->execute([$tournament_id]);
$tournament = $stmt->fetch();

if (!$tournament) {
    $_SESSION['flash'] = 'Tournament not found';
    redirect('tournaments.php');
}

// Get bracket data
$stmt = $pdo->prepare("SELECT * FROM brackets WHERE tournament_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$tournament_id]);
$bracket_data = $stmt->fetch();

$bracket = null;
$participant_names = [];

if ($bracket_data) {
    $bracket = json_decode($bracket_data['bracket_data'], true);
    
    // Get participant names
    if (isset($bracket['initial']) && is_array($bracket['initial'])) {
        $ids = array_filter($bracket['initial'], fn($id) => $id !== null);
        if (!empty($ids)) {
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT r.id, 
                   COALESCE(tm.name, u.username) as name
                FROM registrations r 
                LEFT JOIN teams tm ON r.team_id = tm.id 
                LEFT JOIN users u ON r.user_id = u.id 
                WHERE r.id IN ($placeholders)
            ");
            $stmt->execute(array_values($ids));
            while ($row = $stmt->fetch()) {
                $participant_names[$row['id']] = $row['name'];
            }
        }
    }
    
    // Get match results
    $stmt = $pdo->prepare("
        SELECT m.*, 
               r1.id as p1_reg_id,
               r2.id as p2_reg_id
        FROM matches m
        LEFT JOIN registrations r1 ON m.participant1_id = r1.id
        LEFT JOIN registrations r2 ON m.participant2_id = r2.id
        WHERE m.tournament_id = ?
        ORDER BY m.round, m.match_index
    ");
    $stmt->execute([$tournament_id]);
    $matches = [];
    while ($match = $stmt->fetch()) {
        $key = $match['round'] . '_' . $match['match_index'];
        $matches[$key] = $match;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bracket - <?php echo htmlspecialchars($tournament['name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" 
          integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg==" 
          crossorigin="anonymous" 
          referrerpolicy="no-referrer">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 2rem;
        }
        
        .print-header {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 3px solid #333;
        }
        
        .print-header h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: #333;
        }
        
        .print-header .meta {
            color: #666;
            font-size: 0.9rem;
        }
        
        .tournament-bracket {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        
        .rounds {
            display: flex;
            gap: 3rem;
            min-width: min-content;
            padding: 1rem 0;
        }
        
        .round {
            min-width: 250px;
            flex-shrink: 0;
        }
        
        .round-header {
            font-weight: bold;
            font-size: 1.1rem;
            margin-bottom: 1rem;
            text-align: center;
            padding: 0.5rem;
            background: #f0f0f0;
            border-radius: 4px;
            color: #333;
        }
        
        .match-wrapper {
            margin-bottom: 2rem;
        }
        
        .match {
            border: 2px solid #ddd;
            border-radius: 8px;
            background: white;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .match:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .match-complete {
            border-color: #4CAF50;
        }
        
        .match-participant {
            padding: 0.75rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #eee;
            transition: background 0.2s ease;
        }
        
        .match-participant:last-child {
            border-bottom: none;
        }
        
        .match-participant.winner {
            background: #e8f5e9;
            font-weight: bold;
        }
        
        .match-participant.loser {
            background: #fafafa;
            color: #999;
        }
        
        .match-participant.match-bye {
            background: #f5f5f5;
            color: #999;
            font-style: italic;
        }
        
        .participant-name {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .match-score {
            font-weight: bold;
            margin-left: 1rem;
            min-width: 30px;
            text-align: right;
            font-size: 1.1rem;
        }
        
        .no-print {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 1000;
            display: flex;
            gap: 0.5rem;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            transition: all 0.2s;
            font-family: inherit;
        }
        
        .btn-primary {
            background: #2196F3;
            color: white;
        }
        
        .btn-primary:hover {
            background: #1976D2;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(33, 150, 243, 0.4);
        }
        
        .btn-secondary {
            background: #9E9E9E;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #757575;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(158, 158, 158, 0.4);
        }
        
        .legend {
            margin-top: 2rem;
            padding: 1.5rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .legend h3 {
            margin-bottom: 1rem;
            color: #333;
        }
        
        .legend-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .legend-box {
            width: 20px;
            height: 20px;
            border-radius: 3px;
            border: 2px solid;
            flex-shrink: 0;
        }
        
        .footer {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #ddd;
            color: #666;
            font-size: 0.9rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .empty-state i {
            font-size: 3rem;
            color: #999;
            margin-bottom: 1rem;
            display: block;
        }
        
        .empty-state p {
            color: #666;
            font-size: 1.1rem;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .no-print {
                display: none !important;
            }
            
            .tournament-bracket {
                box-shadow: none;
                padding: 0;
            }
            
            .rounds {
                gap: 2rem;
            }
            
            .round {
                min-width: 200px;
            }
            
            .print-header {
                margin-bottom: 1rem;
            }
            
            .match-wrapper {
                page-break-inside: avoid;
            }
            
            .match:hover {
                box-shadow: none;
            }
            
            /* Landscape for wide brackets */
            @page {
                size: landscape;
                margin: 1cm;
            }
        }
        
        @media screen and (max-width: 768px) {
            body {
                padding: 1rem;
            }
            
            .rounds {
                gap: 2rem;
            }
            
            .round {
                min-width: 200px;
            }
            
            .no-print {
                position: static;
                margin-bottom: 1rem;
            }
            
            .print-header h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <a href="bracket.php?id=<?php echo $tournament_id; ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print"></i> Print Bracket
        </button>
    </div>

    <div class="print-header">
        <h1><?php echo htmlspecialchars($tournament['name']); ?></h1>
        <div class="meta">
            <strong><?php echo htmlspecialchars($tournament['game']); ?></strong> | 
            <?php echo date('F j, Y', strtotime($tournament['date'])); ?> | 
            Organized by <?php echo htmlspecialchars($tournament['creator_name']); ?>
        </div>
    </div>

    <?php if ($bracket && isset($bracket['rounds']) && is_array($bracket['rounds'])): ?>
        <div class="tournament-bracket">
            <div class="rounds">
                <?php 
                // Calculate appropriate round names based on number of rounds
                $total_rounds = count($bracket['rounds']);
                $roundNames = [];
                
                // Build round names from the end backwards
                if ($total_rounds >= 1) $roundNames[$total_rounds - 1] = 'Finals';
                if ($total_rounds >= 2) $roundNames[$total_rounds - 2] = 'Semi Finals';
                if ($total_rounds >= 3) $roundNames[$total_rounds - 3] = 'Quarter Finals';
                if ($total_rounds >= 4) $roundNames[$total_rounds - 4] = 'Round of 8';
                if ($total_rounds >= 5) $roundNames[$total_rounds - 5] = 'Round of 16';
                
                // Fill in remaining rounds
                for ($i = 0; $i < $total_rounds - 5; $i++) {
                    $roundNames[$i] = 'Round ' . ($i + 1);
                }
                
                foreach ($bracket['rounds'] as $round_num => $round): 
                    $roundName = $roundNames[$round_num] ?? "Round " . ($round_num + 1);
                    
                    if (!is_array($round)) continue;
                ?>
                    <div class="round">
                        <div class="round-header"><?php echo htmlspecialchars($roundName); ?></div>
                        <?php for ($i = 0; $i < count($round); $i += 2): ?>
                            <?php
                            $a = $round[$i] ?? null;
                            $b = $round[$i + 1] ?? null;
                            
                            // Get match result
                            $match_key = $round_num . '_' . floor($i/2);
                            $match = $matches[$match_key] ?? null;
                            
                            $is_complete = $match && $match['status'] === 'completed';
                            ?>
                            <?php $match_index = floor($i/2); ?>
                            <div id="match-<?php echo $round_num; ?>-<?php echo $match_index; ?>" class="match-wrapper">
                                <div class="match <?php echo $is_complete ? 'match-complete' : ''; ?>">
                                    <div class="match-participant <?php 
                                        if ($a === null) {
                                            echo 'match-bye';
                                        } elseif ($is_complete && $match['winner_id']) {
                                            echo ($match['winner_id'] == $a) ? 'winner' : 'loser';
                                        }
                                    ?>">
                                        <span class="participant-name">
                                            <?php 
                                            if ($a !== null) {
                                                echo htmlspecialchars($participant_names[$a] ?? "Participant {$a}");
                                            } else {
                                                echo 'BYE';
                                            }
                                            ?>
                                        </span>
                                        <?php if ($match && $match['score_p1'] !== null): ?>
                                            <span class="match-score"><?php echo intval($match['score_p1']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="match-participant <?php 
                                        if ($b === null) {
                                            echo 'match-bye';
                                        } elseif ($is_complete && $match['winner_id']) {
                                            echo ($match['winner_id'] == $b) ? 'winner' : 'loser';
                                        }
                                    ?>">
                                        <span class="participant-name">
                                            <?php 
                                            if ($b !== null) {
                                                echo htmlspecialchars($participant_names[$b] ?? "Participant {$b}");
                                            } else {
                                                echo 'BYE';
                                            }
                                            ?>
                                        </span>
                                        <?php if ($match && $match['score_p2'] !== null): ?>
                                            <span class="match-score"><?php echo intval($match['score_p2']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="legend">
            <h3>Legend</h3>
            <div class="legend-grid">
                <div class="legend-item">
                    <div class="legend-box" style="background: #e8f5e9; border-color: #4CAF50;"></div>
                    <span>Winner</span>
                </div>
                <div class="legend-item">
                    <div class="legend-box" style="background: #fafafa; border-color: #ddd;"></div>
                    <span>Eliminated</span>
                </div>
                <div class="legend-item">
                    <div class="legend-box" style="background: white; border-color: #ddd;"></div>
                    <span>Pending</span>
                </div>
                <div class="legend-item">
                    <div class="legend-box" style="background: #f5f5f5; border-color: #999;"></div>
                    <span>BYE (Auto-advance)</span>
                </div>
            </div>
        </div>

        <div class="footer">
            Generated on <?php echo date('F j, Y g:i A'); ?> | <?php echo htmlspecialchars(SITE_NAME); ?>
        </div>

    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-info-circle"></i>
            <p>Bracket has not been generated yet.</p>
        </div>
    <?php endif; ?>
        <script>
        (function(){
            const round = <?php echo isset($_GET['round']) ? intval($_GET['round']) : 'null'; ?>;
            const match = <?php echo isset($_GET['match']) ? intval($_GET['match']) : 'null'; ?>;
            const autoPrint = <?php echo isset($_GET['auto_print']) ? 1 : 0; ?>;
            if (round !== null && match !== null) {
                const id = 'match-' + round + '-' + match;
                const el = document.getElementById(id);
                if (el) {
                    el.style.transition = 'box-shadow 0.3s ease, border 0.3s ease';
                    el.style.boxShadow = '0 6px 24px rgba(255,165,0,0.6)';
                    el.style.border = '3px solid #ff9800';
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
            if (autoPrint) {
                // Give rendering a moment for highlight + scroll
                setTimeout(() => { window.print(); }, 450);
            }
        })();
        </script>
</body>
</html>