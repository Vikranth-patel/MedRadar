<?php
// login.php — sign in (pharmacy or admin) + pharmacy registration (starts as "pending")
require_once __DIR__ . '/inc/layout.php';

if (current_user()) { redirect(current_user()['role'] === 'admin' ? 'admin/index.php' : 'dashboard.php'); }

$error = ''; $mode = 'login';
$old = ['name' => '', 'contact' => '', 'email' => '', 'phone' => '', 'address' => '', 'locality' => '', 'pin' => ''];
$hours_in = hours_decode(json_encode(array_fill_keys(array_keys(DAYS), '09:00-21:00')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = ($_POST['action'] ?? 'login') === 'register' ? 'register' : 'login';
    $mode = $action;

    if (!csrf_ok()) {
        $error = 'Session expired. Please reload the page and try again.';

    } elseif ($action === 'register') {
        $old = ['name' => clean($_POST['name'] ?? '', 120), 'contact' => clean($_POST['contact'] ?? '', 100), 'email' => strtolower(trim(str($_POST['email'] ?? ''))),
                'phone' => clean($_POST['phone'] ?? '', 20), 'address' => clean($_POST['address'] ?? '', 255), 'locality' => clean($_POST['locality'] ?? '', 80), 'pin' => trim(str($_POST['pin'] ?? ''))];
        $password = str($_POST['password'] ?? ''); $confirm = str($_POST['confirm_password'] ?? '');
        [$hours_json, $hours_err] = hours_from_post($_POST);
        foreach (DAYS as $k => $_) { $hours_in[$k] = !empty($_POST['closed'][$k]) ? '' : (($_POST['open'][$k] ?? '09:00') . '-' . ($_POST['close'][$k] ?? '21:00')); }

        if (in_array('', $old, true)) $error = 'Please fill in all fields.';
        elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || strlen($old['email']) > 190) $error = 'Please enter a valid email address.';
        elseif (!valid_phone($old['phone'])) $error = 'Please enter a valid public phone number.';
        elseif (!valid_pin($old['pin'])) $error = 'PIN code must be 6 digits.';
        elseif ($hours_err) $error = $hours_err;
        elseif (strlen($password) < 8) $error = 'Password must be at least 8 characters.';
        elseif ($password !== $confirm) $error = 'Passwords do not match.';
        elseif (rate_blocked('register', client_ip(), 5, 3600)) $error = 'Too many registrations from your connection. Please try again later.';
        else {
            try {
                if (q('SELECT 1 FROM users WHERE email = ?', [$old['email']])->fetch()) {
                    $error = 'This email is already registered. Please sign in instead.';
                } else {
                    $pdo = db(); $pdo->beginTransaction();
                    q("INSERT INTO users (email, password_hash, role, status, created_at) VALUES (?, ?, 'pharmacy', 'active', ?)", [$old['email'], password_hash($password, PASSWORD_DEFAULT), now()]);
                    $uid = (int)$pdo->lastInsertId();
                    q("INSERT INTO pharmacies (owner_user_id, name, contact_person, phone, address, locality, pin_code, hours, approval_status, created_at) VALUES (?,?,?,?,?,?,?,?, 'pending', ?)",
                      [$uid, $old['name'], $old['contact'], $old['phone'], $old['address'], $old['locality'], $old['pin'], $hours_json, now()]);
                    $pdo->commit();
                    rate_hit('register', client_ip());
                    login_user($uid);
                    flash('ok', 'Account created. An administrator must approve your pharmacy before it appears in public search.');
                    redirect('dashboard.php');
                }
            } catch (PDOException $e) {
                if (db()->inTransaction()) db()->rollBack();
                error_log('MedRadar register error: ' . $e->getMessage());
                $error = 'Could not create your account right now. Please try again later.';
            }
        }

    } else {
        $email = strtolower(trim(str($_POST['email'] ?? ''))); $password = str($_POST['password'] ?? '');
        $key = client_ip() . '|' . $email;
        if ($email === '' || $password === '') {
            $error = 'Please enter your email and password.';
        } elseif (rate_blocked('login', $key, 5, 900) || rate_blocked('login_ip', client_ip(), 30, 900)) {
            $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
        } else {
            $row = q('SELECT id, password_hash, role, status FROM users WHERE email = ?', [$email])->fetch();
            if ($row && password_verify($password, $row['password_hash'])) {
                if ($row['status'] !== 'active') {
                    $error = 'This account is suspended. Please contact the administrator.';
                } else {
                    login_user((int)$row['id']);
                    redirect($row['role'] === 'admin' ? 'admin/index.php' : 'dashboard.php');
                }
            } else {
                rate_hit('login', $key); rate_hit('login_ip', client_ip());
                $error = 'Invalid email or password.';
            }
        }
    }
}

page_head('Pharmacy sign in & registration', ['ctx' => 'auth']);
page_nav('public');
?>
    <main class="auth-wrap">
        <aside class="auth-visual <?= $mode === 'register' ? 'is-register' : '' ?>" aria-hidden="true">
            <div>
                <h2>Honest stock, kept fresh.</h2>
                <p>List catalogue products, confirm what's on your shelf, and answer patients' confirmation requests.</p>
            </div>
        </aside>

        <section class="auth-panel">
            <div class="auth-box <?= $mode === 'register' ? 'wide' : '' ?>" id="auth-box">
                <span class="eyebrow">Pharmacy &amp; admin portal</span>
                <h1 id="auth-title"><?= $mode === 'register' ? 'Register your pharmacy' : 'Welcome back' ?></h1>
                <p id="auth-sub"><?= $mode === 'register' ? 'New pharmacies start as “pending” until an administrator approves them.' : 'Sign in to manage inventory and requests.' ?></p>

                <div class="tabs" role="tablist">
                    <button type="button" class="tab <?= $mode === 'login' ? 'active' : '' ?>" data-tab="login" role="tab">Sign in</button>
                    <button type="button" class="tab <?= $mode === 'register' ? 'active' : '' ?>" data-tab="register" role="tab">Register</button>
                </div>

                <?php flash_render(); ?>
                <?php if ($error !== ''): ?><div class="alert alert-err" role="alert"><span><?= h($error) ?></span><button type="button" aria-label="Dismiss">×</button></div><?php endif; ?>

                <form action="login.php" method="POST" class="tab-panel <?= $mode === 'login' ? '' : 'hidden' ?>" data-panel="login">
                    <?= csrf_field() ?><input type="hidden" name="action" value="login">
                    <div class="form-group"><label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?= $mode === 'login' ? h($_POST['email'] ?? '') : '' ?>" required autocomplete="username"></div>
                    <div class="form-group"><label for="password">Password</label>
                        <div class="pw-wrap"><input type="password" id="password" name="password" required autocomplete="current-password">
                        <button type="button" class="pw-toggle" aria-label="Show password">👁</button></div></div>
                    <button type="submit" class="btn btn-primary btn-block">Sign in</button>
                    <div class="demo-note"><strong>Demo accounts</strong> (fictional data, password <code>password123</code>):<br>
                        <code>sanjivani@example.com</code> · <code>admin@medradar.test</code><br>
                        New here? <a href="#register" data-tab="register" style="color:var(--primary);font-weight:700">Register your pharmacy</a></div>
                </form>

                <form action="login.php" method="POST" class="tab-panel <?= $mode === 'register' ? '' : 'hidden' ?>" data-panel="register">
                    <?= csrf_field() ?><input type="hidden" name="action" value="register">
                    <div class="form-row">
                        <div class="form-group"><label for="r-name">Pharmacy name</label><input type="text" id="r-name" name="name" value="<?= h($old['name']) ?>" maxlength="120" required></div>
                        <div class="form-group"><label for="r-contact">Contact person</label><input type="text" id="r-contact" name="contact" value="<?= h($old['contact']) ?>" maxlength="100" required></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label for="r-email">Login email</label><input type="email" id="r-email" name="email" value="<?= h($old['email']) ?>" required autocomplete="email"></div>
                        <div class="form-group"><label for="r-phone">Public phone</label><input type="text" id="r-phone" name="phone" value="<?= h($old['phone']) ?>" inputmode="tel" required></div>
                    </div>
                    <div class="form-group"><label for="r-address">Address</label><input type="text" id="r-address" name="address" value="<?= h($old['address']) ?>" maxlength="255" required></div>
                    <div class="form-row">
                        <div class="form-group"><label for="r-locality">Locality</label><input type="text" id="r-locality" name="locality" value="<?= h($old['locality']) ?>" maxlength="80" required></div>
                        <div class="form-group"><label for="r-pin">PIN code</label><input type="text" id="r-pin" name="pin" value="<?= h($old['pin']) ?>" inputmode="numeric" pattern="\d{6}" maxlength="6" required></div>
                    </div>
                    <?php hours_fields($hours_in); ?>
                    <div class="form-row">
                        <div class="form-group"><label for="r-pass">Password</label><div class="pw-wrap"><input type="password" id="r-pass" name="password" minlength="8" required autocomplete="new-password"><button type="button" class="pw-toggle" aria-label="Show password">👁</button></div></div>
                        <div class="form-group"><label for="r-pass2">Confirm password</label><input type="password" id="r-pass2" name="confirm_password" minlength="8" required autocomplete="new-password"></div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Create account</button>
                    <div class="demo-note">Already registered? <a href="#login" data-tab="login" style="color:var(--primary);font-weight:700">Sign in</a></div>
                </form>
            </div>
        </section>
    </main>
<?php page_foot([], 'auth'); ?>
