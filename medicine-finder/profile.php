<?php
require_once __DIR__ . '/inc/layout.php';
[$u, $ph] = require_pharmacy();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'profile') {
        $d = ['name' => clean($_POST['name'] ?? '', 120), 'contact' => clean($_POST['contact'] ?? '', 100), 'phone' => clean($_POST['phone'] ?? '', 20),
              'address' => clean($_POST['address'] ?? '', 255), 'locality' => clean($_POST['locality'] ?? '', 80), 'pin' => trim(str($_POST['pin'] ?? ''))];
        [$hj, $he] = hours_from_post($_POST);
        $cu = trim(str($_POST['closed_until'] ?? '')); $cn = clean($_POST['closure_note'] ?? '', 160);
        if (in_array('', $d, true)) flash('err', 'Please fill in all profile fields.');
        elseif (!valid_phone($d['phone'])) flash('err', 'Please enter a valid phone number.');
        elseif (!valid_pin($d['pin'])) flash('err', 'PIN code must be 6 digits.');
        elseif ($he) flash('err', $he);
        elseif ($cu !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $cu)) flash('err', 'Invalid closure date.');
        else {
            // Only the pharmacies row is touched — inventory.last_confirmed_at is NOT changed by profile edits (T04)
            q('UPDATE pharmacies SET name = ?, contact_person = ?, phone = ?, address = ?, locality = ?, pin_code = ?, hours = ?, closed_until = ?, closure_note = ? WHERE id = ? AND owner_user_id = ?',
              [$d['name'], $d['contact'], $d['phone'], $d['address'], $d['locality'], $d['pin'], $hj, $cu !== '' ? $cu : null, $cn !== '' ? $cn : null, $ph['id'], $u['id']]);
            flash('ok', 'Profile saved. Stock confirmation times are unchanged.');
        }
    } elseif ($act === 'password') {
        $cur = str($_POST['current'] ?? ''); $new = str($_POST['new'] ?? ''); $cf = str($_POST['confirm'] ?? '');
        $hash = q('SELECT password_hash FROM users WHERE id = ?', [$u['id']])->fetchColumn();
        if (!password_verify($cur, $hash)) flash('err', 'Your current password is incorrect.');
        elseif (strlen($new) < 8) flash('err', 'New password must be at least 8 characters.');
        elseif ($new !== $cf) flash('err', 'New passwords do not match.');
        else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            session_regenerate_id(true);
            log_admin((int)$u['id'], 'user', (int)$u['id'], 'password_change', null);   // never logs the password itself
            flash('ok', 'Password changed.');
        }
    }
    redirect('profile.php');
}

page_head('Profile', ['ctx' => 'pharmacy']);
page_nav('pharmacy');
page_banner('Profile & opening hours', $ph['name'], 'pharmacy');
?>
    <main class="container dash-main">
        <?php flash_render(); approval_notice($ph); ?>
        <section class="panel">
            <h2>Public profile</h2>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="profile">
                <div class="form-row">
                    <div class="form-group"><label for="p-name">Pharmacy name</label><input type="text" id="p-name" name="name" value="<?= h($ph['name']) ?>" maxlength="120" required></div>
                    <div class="form-group"><label for="p-contact">Contact person</label><input type="text" id="p-contact" name="contact" value="<?= h($ph['contact_person']) ?>" maxlength="100" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label for="p-phone">Public phone</label><input type="text" id="p-phone" name="phone" value="<?= h($ph['phone']) ?>" required></div>
                    <div class="form-group"><label>Login email</label><input type="text" value="<?= h($u['email']) ?>" disabled></div>
                </div>
                <div class="form-group"><label for="p-address">Address</label><input type="text" id="p-address" name="address" value="<?= h($ph['address']) ?>" maxlength="255" required></div>
                <div class="form-row">
                    <div class="form-group"><label for="p-loc">Locality</label><input type="text" id="p-loc" name="locality" value="<?= h($ph['locality']) ?>" maxlength="80" required></div>
                    <div class="form-group"><label for="p-pin">PIN code</label><input type="text" id="p-pin" name="pin" value="<?= h($ph['pin_code']) ?>" pattern="\d{6}" maxlength="6" required></div>
                </div>
                <?php hours_fields(hours_decode($ph['hours'])); ?>
                <div class="form-row" style="margin-top:1rem">
                    <div class="form-group"><label for="p-cu">Temporarily closed until (optional)</label><input type="date" id="p-cu" name="closed_until" value="<?= h($ph['closed_until'] ?? '') ?>"></div>
                    <div class="form-group"><label for="p-cn">Closure note (optional)</label><input type="text" id="p-cn" name="closure_note" value="<?= h($ph['closure_note'] ?? '') ?>" maxlength="160"></div>
                </div>
                <button class="btn btn-primary" type="submit">Save profile</button>
                <p class="muted small" style="margin-top:.6rem">Saving your profile does not refresh any stock confirmation.</p>
            </form>
        </section>
        <section class="panel">
            <h2>Change password</h2>
            <form method="post" class="add-form"><?= csrf_field() ?><input type="hidden" name="action" value="password">
                <div class="form-group"><label for="pw0">Current password</label><input type="password" id="pw0" name="current" required autocomplete="current-password"></div>
                <div class="form-group"><label for="pw1">New password</label><input type="password" id="pw1" name="new" minlength="8" required autocomplete="new-password"></div>
                <div class="form-group"><label for="pw2">Confirm new</label><input type="password" id="pw2" name="confirm" minlength="8" required autocomplete="new-password"></div>
                <button class="btn btn-primary" type="submit">Change password</button>
            </form>
        </section>
    </main>
<?php page_foot(); ?>
