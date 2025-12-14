<?php
require_once __DIR__ . '/functions.php';

function generate_single_elim_bracket(array $participants): array {
    $cnt = count($participants);
    if ($cnt < 2) throw new InvalidArgumentException("Need ≥2 participants");

    $slots = 1;
    while ($slots < $cnt) $slots *= 2;

    $initial = array_fill(0, $slots, null);
    foreach (array_slice($participants, 0, $slots) as $i => $p) {
        $initial[$i] = $p;
    }
    shuffle($initial); // randomize

    $rounds = [$initial];
    $current = $initial;

    while (count($current) > 1) {
        $next = [];
        for ($i = 0; $i < count($current); $i += 2) {
            $a = $current[$i];
            $b = $current[$i + 1] ?? null;
            if ($a === null && $b !== null) $next[] = $b;
            elseif ($b === null && $a !== null) $next[] = $a;
            else $next[] = null;
        }
        $rounds[] = $next;
        $current = $next;
    }

    return [
        'type' => 'single',
        'slots' => $slots,
        'rounds' => $rounds,
        'initial' => $initial,
    ];
}

function persist_bracket(PDO $pdo, int $tournament_id, array $bracket): int {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO brackets (tournament_id, type, bracket_data) VALUES (?, ?, ?)");
        $stmt->execute([$tournament_id, $bracket['type'], json_encode($bracket)]);
        $bracket_id = $pdo->lastInsertId();

        $initial = $bracket['initial'];
        for ($i = 0; $i < count($initial); $i += 2) {
            $p1 = $initial[$i];
            $p2 = $initial[$i + 1] ?? null;
            if ($p1 === null && $p2 === null) continue;

            $status = ($p1 === null || $p2 === null) ? 'completed' : 'pending';
            $winner = null;
            if ($p1 === null) $winner = $p2;
            if ($p2 === null) $winner = $p1;

            $stmt = $pdo->prepare("INSERT INTO matches (tournament_id, bracket_id, round, match_index, participant1_id, participant2_id, winner_id, status) VALUES (?, ?, 0, ?, ?, ?, ?, ?)");
            $stmt->execute([$tournament_id, $bracket_id, $i / 2, $p1, $p2, $winner, $status]);
        }
        $pdo->commit();
        return $bracket_id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}