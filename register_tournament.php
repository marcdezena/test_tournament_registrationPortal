// register_tournament.php
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/functions.php';

if (!isLoggedIn()) {
    redirect('login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
}

$tournament_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$tournament_id) {
    setFlash('Invalid tournament ID', 'error');
    redirect('tournaments.php');
}

// Get tournament details
$tournament = $pdo->prepare("
    SELECT t.*, 
           COUNT(r.id) as registered_count,
           u.team_id as user_team_id
    FROM tournaments t
    LEFT JOIN registrations r ON r.tournament_id = t.id AND r.status = 'approved'
    LEFT JOIN users u ON u.id = ?
    WHERE t.id = ?
    GROUP BY t.id
")->execute([$_SESSION['user_id'], $tournament_id])->fetch();

if (!$tournament) {
    setFlash('Tournament not found', 'error');
    redirect('tournaments.php');
}

// Check if registration is open
$registration_open = true;
$now = new DateTime();
$deadline = new DateTime($tournament['registration_deadline']);

if ($now > $deadline) {
    $registration_open = false;
    setFlash('Registration for this tournament has closed', 'error');
    redirect('tournament_details.php?id=' . $tournament_id);
}

// Check if already registered
$is_registered = $pdo->prepare("
    SELECT 1 FROM registrations 
    WHERE tournament_id = ? AND (user_id = ? OR team_id = ?)
    LIMIT 1
")->execute([$tournament_id, $_SESSION['user_id'], $tournament['user_team_id']])->fetch();

if ($is_registered) {
    setFlash('You are already registered for this tournament', 'error');
    redirect('tournament_details.php?id=' . $tournament_id);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('Invalid security token', 'error');
        redirect('register_tournament.php?id=' . $tournament_id);
    }

    try {
        $pdo->beginTransaction();

        // Check if team registration is required
        if ($tournament['team_size'] > 1 && empty($tournament['user_team_id'])) {
            // Handle team creation/joining
            $team_action = $_POST['team_action'] ?? '';
            
            if ($team_action === 'create') {
                // Create new team
                $team_name = trim($_POST['team_name'] ?? '');
                $team_tag = trim($_POST['team_tag'] ?? '');
                
                if (empty($team_name)) {
                    throw new Exception('Team name is required');
                }
                
                // Create team
                $stmt = $pdo->prepare("
                    INSERT INTO teams (name, tag, created_by, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([$team_name, $team_tag, $_SESSION['user_id']]);
                $team_id = $pdo->lastInsertId();
                
                // Add user to team as captain
                $pdo->prepare("
                    INSERT INTO team_members (team_id, user_id, role, joined_at)
                    VALUES (?, ?, 'captain', NOW())
                ")->execute([$team_id, $_SESSION['user_id']]);
                
                // Update user's team
                $pdo->prepare("UPDATE users SET team_id = ? WHERE id = ?")
                    ->execute([$team_id, $_SESSION['user_id']]);
                
                $_SESSION['team_id'] = $team_id;
                
            } elseif ($team_action === 'join' && !empty($_POST['team_id'])) {
                // Join existing team
                $team_id = (int)$_POST['team_id'];
                
                // Verify team exists and has space
                $team = $pdo->prepare("
                    SELECT t.*, 
                           COUNT(tm.id) as member_count
                    FROM teams t
                    LEFT JOIN team_members tm ON tm.team_id = t.id
                    WHERE t.id = ?
                    GROUP BY t.id
                ")->execute([$team_id])->fetch();
                
                if (!$team) {
                    throw new Exception('Team not found');
                }
                
                if ($team['member_count'] >= $tournament['max_team_size']) {
                    throw new Exception('This team is already full');
                }
                
                // Add user to team
                $pdo->prepare("
                    INSERT INTO team_members (team_id, user_id, role, joined_at)
                    VALUES (?, ?, 'member', NOW())
                ")->execute([$team_id, $_SESSION['user_id']]);
                
                // Update user's team
                $pdo->prepare("UPDATE users SET team_id = ? WHERE id = ?")
                    ->execute([$team_id, $_SESSION['user_id']]);
                
                $_SESSION['team_id'] = $team_id;
                
            } else {
                throw new Exception('Please select a team action');
            }
        } else {
            $team_id = $tournament['user_team_id'] ?? null;
        }
        
        // Register for tournament
        $pdo->prepare("
            INSERT INTO registrations (
                tournament_id, 
                user_id, 
                team_id, 
                status, 
                registered_at
            ) VALUES (?, ?, ?, 'pending', NOW())
        ")->execute([
            $tournament_id,
            $_SESSION['user_id'],
            $team_id ?? null
        ]);
        
        // Process payment if there's an entry fee
        if ($tournament['entry_fee'] > 0) {
            // Integrate with payment gateway here
            $payment_success = processPayment([
                'user_id' => $_SESSION['user_id'],
                'amount' => $tournament['entry_fee'],
                'description' => "Tournament Entry: " . $tournament['name'],
                'tournament_id' => $tournament_id
            ]);
            
            if (!$payment_success) {
                throw new Exception('Payment processing failed');
            }
        }
        
        $pdo->commit();
        
        // Send confirmation email
        sendTournamentRegistrationEmail($_SESSION['user_id'], $tournament_id);
        
        setFlash('Successfully registered for the tournament!', 'success');
        redirect('tournament_details.php?id=' . $tournament_id);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash('Registration failed: ' . $e->getMessage(), 'error');
    }
}

// Include header
include 'includes/header.php';
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h2 class="mb-0">Register for <?= htmlspecialchars($tournament['name']) ?></h2>
                </div>
                <div class="card-body">
                    <?php if ($tournament['team_size'] > 1): ?>
                        <h4>Team Registration</h4>
                        <p>This is a team tournament. You can either create a new team or join an existing one.</p>
                        
                        <ul class="nav nav-tabs" id="teamTabs" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" id="create-tab" data-toggle="tab" href="#create-team" role="tab">
                                    Create New Team
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="join-tab" data-toggle="tab" href="#join-team" role="tab">
                                    Join Existing Team
                                </a>
                            </li>
                        </ul>
                        
                        <form method="post" class="mt-4">
                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                            
                            <div class="tab-content" id="teamTabsContent">
                                <!-- Create Team Tab -->
                                <div class="tab-pane fade show active" id="create-team" role="tabpanel">
                                    <input type="hidden" name="team_action" value="create">
                                    
                                    <div class="form-group">
                                        <label for="team_name">Team Name *</label>
                                        <input type="text" class="form-control" id="team_name" name="team_name" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="team_tag">Team Tag (Optional)</label>
                                        <input type="text" class="form-control" id="team_tag" name="team_tag" maxlength="5">
                                        <small class="form-text text-muted">Short tag to represent your team (max 5 characters)</small>
                                    </div>
                                </div>
                                
                                <!-- Join Team Tab -->
                                <div class="tab-pane fade" id="join-team" role="tabpanel">
                                    <input type="hidden" name="team_action" value="join">
                                    
                                    <div class="form-group">
                                        <label for="team_id">Select Team</label>
                                        <select class="form-control" id="team_id" name="team_id" required>
                                            <option value="">-- Select a team to join --</option>
                                            <?php
                                            $teams = $pdo->prepare("
                                                SELECT t.*, 
                                                       COUNT(tm.id) as member_count
                                                FROM teams t
                                                LEFT JOIN team_members tm ON tm.team_id = t.id
                                                WHERE t.id NOT IN (
                                                    SELECT team_id FROM registrations 
                                                    WHERE tournament_id = ? AND team_id IS NOT NULL
                                                )
                                                GROUP BY t.id
                                                HAVING member_count < ?
                                            ")->execute([$tournament_id, $tournament['max_team_size']])->fetchAll();
                                            
                                            foreach ($teams as $team) {
                                                echo sprintf(
                                                    '<option value="%d">%s (%d/%d members)</option>',
                                                    $team['id'],
                                                    htmlspecialchars($team['name']),
                                                    $team['member_count'],
                                                    $tournament['max_team_size']
                                                );
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    
                                    <?php if (empty($teams)): ?>
                                        <div class="alert alert-info">
                                            No teams are currently accepting members. You can create your own team instead.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <?php if ($tournament['entry_fee'] > 0): ?>
                                <div class="payment-summary mt-4 p-3 bg-light rounded">
                                    <h5>Payment Summary</h5>
                                    <table class="table table-borderless mb-0">
                                        <tr>
                                            <td>Tournament Entry Fee:</td>
                                            <td class="text-right">$<?= number_format($tournament['entry_fee'], 2) ?></td>
                                        </tr>
                                        <tr class="font-weight-bold">
                                            <td>Total:</td>
                                            <td class="text-right">$<?= number_format($tournament['entry_fee'], 2) ?></td>
                                        </tr>
                                    </table>
                                </div>
                                
                                <div class="payment-methods mt-3">
                                    <h5>Payment Method</h5>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="payment_method" id="creditCard" value="credit_card" checked>
                                        <label class="form-check-label" for="creditCard">
                                            Credit/Debit Card
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="payment_method" id="paypal" value="paypal">
                                        <label class="form-check-label" for="paypal">
                                            PayPal
                                        </label>
                                    </div>
                                </div>
                                
                                <div id="creditCardFields" class="mt-3">
                                    <!-- Credit card form would go here -->
                                    <div class="alert alert-info">
                                        Payment processing will be handled by the selected payment gateway.
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="form-group form-check mt-3">
                                <input type="checkbox" class="form-check-input" id="terms" required>
                                <label class="form-check-label" for="terms">
                                    I agree to the <a href="terms.php" target="_blank">Terms and Conditions</a> and 
                                    <a href="privacy.php" target="_blank">Privacy Policy</a>
                                </label>
                            </div>
                            
                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <?= $tournament['entry_fee'] > 0 ? 'Pay & Complete Registration' : 'Complete Registration' ?>
                                </button>
                                <a href="tournament_details.php?id=<?= $tournament_id ?>" class="btn btn-outline-secondary">
                                    Cancel
                                </a>
                            </div>
                        </form>
                        
                    <?php else: ?>
                        <!-- Individual registration form -->
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                            
                            <div class="alert alert-info">
                                You are registering as an individual player for this tournament.
                            </div>
                            
                            <?php if ($tournament['entry_fee'] > 0): ?>
                                <div class="payment-summary p-3 bg-light rounded mb-4">
                                    <h5>Payment Summary</h5>
                                    <table class="table table-borderless mb-0">
                                        <tr>
                                            <td>Tournament Entry Fee:</td>
                                            <td class="text-right">$<?= number_format($tournament['entry_fee'], 2) ?></td>
                                        </tr>
                                        <tr class="font-weight-bold">
                                            <td>Total:</td>
                                            <td class="text-right">$<?= number_format($tournament['entry_fee'], 2) ?></td>
                                        </tr>
                                    </table>
                                </div>
                                
                                <div class="payment-methods mb-4">
                                    <h5>Payment Method</h5>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="payment_method" id="creditCardInd" value="credit_card" checked>
                                        <label class="form-check-label" for="creditCardInd">
                                            Credit/Debit Card
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="payment_method" id="paypalInd" value="paypal">
                                        <label class="form-check-label" for="paypalInd">
                                            PayPal
                                        </label>
                                    </div>
                                </div>
                                
                                <div id="creditCardFieldsInd" class="mb-4">
                                    <!-- Credit card form would go here -->
                                    <div class="alert alert-info">
                                        Payment processing will be handled by the selected payment gateway.
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="form-group form-check">
                                <input type="checkbox" class="form-check-input" id="termsInd" required>
                                <label class="form-check-label" for="termsInd">
                                    I agree to the <a href="terms.php" target="_blank">Terms and Conditions</a> and 
                                    <a href="privacy.php" target="_blank">Privacy Policy</a>
                                </label>
                            </div>
                            
                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <?= $tournament['entry_fee'] > 0 ? 'Pay & Complete Registration' : 'Complete Registration' ?>
                                </button>
                                <a href="tournament_details.php?id=<?= $tournament_id ?>" class="btn btn-outline-secondary">
                                    Cancel
                                </a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Toggle payment method fields
    $('input[name="payment_method"]').change(function() {
        if ($(this).val() === 'credit_card') {
            $('#creditCardFields, #creditCardFieldsInd').show();
        } else {
            $('#creditCardFields, #creditCardFieldsInd').hide();
        }
    });
    
    // Initialize
    $('input[name="payment_method"]:checked').trigger('change');
});
</script>

<?php include 'includes/footer.php'; ?>