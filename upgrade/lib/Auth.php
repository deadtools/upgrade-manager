<?php
class UMEAuth {
    private $config;
    private $storage;
    private $cookieName = 'UME_AUTH';
    private $loginWindowSeconds = 900;
    private $loginMaxFailures = 8;

    public function __construct($config, $storage) {
        $this->config = $config;
        $this->storage = $storage;
    }

    // Kept for bootstrap compatibility. Authentication does not depend on
    // PHP session storage, which is often misconfigured on legacy hosting.
    public function startSession() {
        return true;
    }

    private function cookiePath() {
        $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '/upgrade/index.php';
        $path = rtrim(dirname($script), '/');
        return ($path === '' || $path === '.') ? '/' : $path . '/';
    }

    private function base64UrlEncode($value) {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode($value) {
        $pad = strlen($value) % 4;
        if ($pad) $value .= str_repeat('=', 4 - $pad);
        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    private function signature($payload) {
        return hash_hmac('sha256', $payload, $this->config['hmac_key']);
    }

    private function currentCookie() {
        return isset($_COOKIE[$this->cookieName]) && is_string($_COOKIE[$this->cookieName]) ? $_COOKIE[$this->cookieName] : '';
    }

    private function sessionGeneration($auth) {
        $generation = isset($auth['session_generation']) ? (int)$auth['session_generation'] : 1;
        return $generation > 0 ? $generation : 1;
    }

    // 3.6.3: explicit SameSite=Lax (PHP 7.3+ options array, raw header fallback for PHP 5.6-7.2).
    private function setAuthCookie($value, $expires, $secure) {
        if (PHP_VERSION_ID >= 70300) {
            setcookie($this->cookieName, $value, array('expires' => $expires, 'path' => $this->cookiePath(), 'secure' => (bool)$secure, 'httponly' => true, 'samesite' => 'Lax'));
            return;
        }
        $cookie = $this->cookieName . '=' . rawurlencode((string)$value) . '; Expires=' . gmdate('D, d M Y H:i:s', (int)$expires) . ' GMT; Max-Age=' . max(0, (int)$expires - time()) . '; Path=' . $this->cookiePath() . ($secure ? '; Secure' : '') . '; HttpOnly; SameSite=Lax';
        header('Set-Cookie: ' . $cookie, false);
    }

    private function issueCookie($auth = null) {
        if (!is_array($auth)) $auth = $this->authData();
        $expires = time() + 28800;
        $payload = $this->config['site_id'] . '|' . $expires . '|' . $this->sessionGeneration($auth) . '|' . UMEUtil::randomHex(24);
        $encoded = $this->base64UrlEncode($payload);
        $value = $encoded . '.' . $this->signature($encoded);
        $secure = UMEUtil::isHttps();
        $this->setAuthCookie($value, $expires, $secure);
        $_COOKIE[$this->cookieName] = $value;
    }

    public function loggedIn() {
        $cookie = $this->currentCookie();
        if ($cookie === '' || strpos($cookie, '.') === false) return false;
        list($encoded, $signature) = explode('.', $cookie, 2);
        $expected = $this->signature($encoded);
        $valid = function_exists('hash_equals') ? hash_equals($expected, $signature) : $expected === $signature;
        if (!$valid) return false;
        $payload = $this->base64UrlDecode($encoded);
        if ($payload === false) return false;
        $parts = explode('|', $payload, 4);
        // Cookies issued before 3.5.4 deliberately expire here. They have no
        // session generation and therefore cannot be revoked after a password change.
        if (count($parts) !== 4) return false;
        if ($parts[0] !== $this->config['site_id']) return false;
        if ((int)$parts[1] < time()) return false;
        $auth = $this->authData();
        if ((int)$parts[2] !== $this->sessionGeneration($auth)) return false;
        return true;
    }

    public function csrf() {
        $cookie = $this->currentCookie();
        if (!$this->loggedIn() || $cookie === '') return '';
        return hash_hmac('sha256', 'csrf|' . $cookie, $this->config['hmac_key']);
    }

    public function checkCsrf($value) {
        $expected = $this->csrf();
        if ($expected === '' || !is_string($value)) return false;
        return function_exists('hash_equals') ? hash_equals($expected, $value) : $expected === $value;
    }

    private function authFile() {
        return $this->storage . '/auth.json';
    }

    private function normalizeEmail($email) {
        $email = strtolower(trim((string)$email));
        if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) return '';
        return $email;
    }

    private function configuredUsername() {
        $username = isset($this->config['login_username']) ? trim((string)$this->config['login_username']) : 'admin';
        return $username === '' ? 'admin' : $username;
    }

    private function configuredAdminEmail() {
        if (!empty($this->config['admin_email'])) return $this->normalizeEmail($this->config['admin_email']);
        return $this->normalizeEmail($this->configuredUsername());
    }

    private function authData() {
        $configuredEmail = $this->configuredAdminEmail();
        $auth = UMEUtil::jsonRead($this->authFile(), array(
            'username' => $this->configuredUsername(),
            'admin_email' => $configuredEmail,
            'email_bound' => $configuredEmail !== '',
            'password_hash' => isset($this->config['password_hash']) ? $this->config['password_hash'] : '',
            'force_password_change' => !empty($this->config['force_password_change']),
            'session_generation' => 1
        ));
        if (empty($auth['username'])) $auth['username'] = $this->configuredUsername();
        if (empty($auth['password_hash']) && !empty($this->config['password_hash'])) $auth['password_hash'] = $this->config['password_hash'];
        if (empty($auth['session_generation']) || (int)$auth['session_generation'] < 1) $auth['session_generation'] = 1;
        $email = isset($auth['admin_email']) ? $this->normalizeEmail($auth['admin_email']) : '';
        if ($email === '') $email = $this->normalizeEmail(isset($auth['username']) ? $auth['username'] : '');
        $auth['admin_email'] = $email;
        $auth['email_bound'] = $email !== '' && (!array_key_exists('email_bound', $auth) || !empty($auth['email_bound']));
        return $auth;
    }

    public function requiresPasswordChange() {
        $auth = $this->authData();
        return !empty($auth['force_password_change']);
    }

    public function requiresEmailBinding() {
        $auth = $this->authData();
        return empty($auth['email_bound']) || $this->normalizeEmail(isset($auth['admin_email']) ? $auth['admin_email'] : '') === '';
    }

    public function adminEmail() {
        $auth = $this->authData();
        return $this->normalizeEmail(isset($auth['admin_email']) ? $auth['admin_email'] : '');
    }

    public function recoveryEmailMatches($email) {
        $expected = $this->adminEmail();
        $given = $this->normalizeEmail($email);
        if ($expected === '' || $given === '') return false;
        return function_exists('hash_equals') ? hash_equals($expected, $given) : $expected === $given;
    }

    private function throttleFile() {
        return $this->storage . '/login-attempts.json';
    }

    private function throttleLockFile() {
        return $this->storage . '/login-attempts.lock';
    }

    private function sourceKey() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string)$_SERVER['REMOTE_ADDR']) : '';
        if ($ip === '' || @inet_pton($ip) === false) $ip = 'unknown';
        return hash_hmac('sha256', 'login-source|' . $ip, $this->config['hmac_key']);
    }

    private function throttleData($now) {
        $raw = UMEUtil::jsonRead($this->throttleFile(), array());
        $data = array('format' => 'ume-login-throttle/2.0', 'buckets' => array());
        if (isset($raw['buckets']) && is_array($raw['buckets'])) $data['buckets'] = $raw['buckets'];
        // Honour a recent legacy 3.5.3 lockout once during migration.
        if (isset($raw['count']) && isset($raw['last']) && (int)$raw['count'] >= $this->loginMaxFailures && $now - (int)$raw['last'] < $this->loginWindowSeconds) {
            $data['legacy_lock_until'] = (int)$raw['last'] + $this->loginWindowSeconds;
        } elseif (!empty($raw['legacy_lock_until'])) {
            $data['legacy_lock_until'] = (int)$raw['legacy_lock_until'];
        }
        foreach ($data['buckets'] as $key => $bucket) {
            $last = isset($bucket['last']) ? (int)$bucket['last'] : 0;
            $lockUntil = isset($bucket['lock_until']) ? (int)$bucket['lock_until'] : 0;
            if ($lockUntil <= $now && ($last <= 0 || $now - $last > 86400)) unset($data['buckets'][$key]);
        }
        if (count($data['buckets']) > 256) {
            uasort($data['buckets'], function($a, $b) {
                $al = isset($a['last']) ? (int)$a['last'] : 0;
                $bl = isset($b['last']) ? (int)$b['last'] : 0;
                if ($al === $bl) return 0;
                return $al < $bl ? -1 : 1;
            });
            while (count($data['buckets']) > 256) array_shift($data['buckets']);
        }
        return $data;
    }

    private function writeThrottleData($data) {
        if (empty($data['buckets']) && empty($data['legacy_lock_until'])) {
            @unlink($this->throttleFile());
            return;
        }
        UMEUtil::jsonWriteAtomic($this->throttleFile(), $data);
        @chmod($this->throttleFile(), 0600);
    }

    public function login($username, $password) {
        $auth = $this->authData();
        $lock = @fopen($this->throttleLockFile(), 'c+');
        if (!$lock) throw new Exception('Не удалось открыть защиту входа. Проверьте права runtime storage.');
        if (!@flock($lock, LOCK_EX)) { fclose($lock); throw new Exception('Не удалось заблокировать журнал попыток входа.'); }
        @chmod($this->throttleLockFile(), 0600);

        $now = time();
        $sourceKey = $this->sourceKey();
        try {
            $data = $this->throttleData($now);
            if (!empty($data['legacy_lock_until']) && (int)$data['legacy_lock_until'] > $now) {
                throw new Exception('Слишком много неудачных попыток. Повторите через 15 минут.');
            }
            if (!empty($data['legacy_lock_until']) && (int)$data['legacy_lock_until'] <= $now) unset($data['legacy_lock_until']);

            $bucket = isset($data['buckets'][$sourceKey]) && is_array($data['buckets'][$sourceKey]) ? $data['buckets'][$sourceKey] : array('count' => 0, 'last' => 0, 'lock_until' => 0);
            if (!empty($bucket['lock_until']) && (int)$bucket['lock_until'] > $now) {
                throw new Exception('Слишком много неудачных попыток. Повторите через 15 минут.');
            }
            if (!empty($bucket['last']) && $now - (int)$bucket['last'] >= $this->loginWindowSeconds) {
                $bucket = array('count' => 0, 'last' => 0, 'lock_until' => 0);
            }

            $expectedUsername = isset($auth['username']) ? (string)$auth['username'] : $this->configuredUsername();
            $givenUsername = trim((string)$username);
            if ($this->normalizeEmail($expectedUsername) !== '') {
                $expectedUsername = $this->normalizeEmail($expectedUsername);
                $givenUsername = $this->normalizeEmail($givenUsername);
            }
            $usernameOk = $givenUsername !== '' && (function_exists('hash_equals') ? hash_equals($expectedUsername, $givenUsername) : $expectedUsername === $givenUsername);
            // Always execute password_verify so a wrong username does not create an obvious timing oracle.
            $passwordHash = isset($auth['password_hash']) ? (string)$auth['password_hash'] : '';
            $passwordOk = $passwordHash !== '' && password_verify((string)$password, $passwordHash);

            if ($usernameOk && $passwordOk) {
                unset($data['buckets'][$sourceKey]);
                $this->writeThrottleData($data);
                @flock($lock, LOCK_UN);
                fclose($lock);
                $this->issueCookie($auth);
                return true;
            }

            $bucket['count'] = isset($bucket['count']) ? (int)$bucket['count'] + 1 : 1;
            $bucket['last'] = $now;
            $bucket['lock_until'] = 0;
            $locked = false;
            if ($bucket['count'] >= $this->loginMaxFailures) {
                $bucket['lock_until'] = $now + $this->loginWindowSeconds;
                $locked = true;
            }
            $data['buckets'][$sourceKey] = $bucket;
            $this->writeThrottleData($data);
            if ($locked) throw new Exception('Слишком много неудачных попыток. Повторите через 15 минут.');
            @flock($lock, LOCK_UN);
            fclose($lock);
            return false;
        } catch (Exception $e) {
            @flock($lock, LOCK_UN);
            fclose($lock);
            throw $e;
        } catch (Throwable $e) {
            @flock($lock, LOCK_UN);
            fclose($lock);
            throw $e;
        }
    }

    private function clearLoginThrottle() {
        @unlink($this->throttleFile());
    }

    private function securityEvent($event, $details = array()) {
        $row = array('time' => UMEUtil::now(), 'event' => (string)$event);
        if (is_array($details)) foreach ($details as $key => $value) $row[$key] = $value;
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) UMEUtil::appendLine($this->storage . '/security-events.log', $json);
    }

    private function applyPasswordHash($hash, $forceChange, $issueCookie, $source) {
        $hash = (string)$hash;
        if (!preg_match('/^\$2[aby]\$[0-9]{2}\$[.\/A-Za-z0-9]{53}$/', $hash)) throw new Exception('Некорректный bcrypt-хэш пароля.');
        $auth = $this->authData();
        $auth['password_hash'] = $hash;
        $auth['force_password_change'] = (bool)$forceChange;
        $auth['session_generation'] = $this->sessionGeneration($auth) + 1;
        UMEUtil::jsonWriteAtomic($this->authFile(), $auth);
        @chmod($this->authFile(), 0600);
        $this->clearLoginThrottle();
        $this->securityEvent('password_reset', array('source' => (string)$source, 'force_password_change' => (bool)$forceChange, 'session_generation' => (int)$auth['session_generation']));
        if ($issueCookie) $this->issueCookie($auth);
        return $auth;
    }

    public function username() {
        $auth = $this->authData();
        return isset($auth['username']) ? (string)$auth['username'] : $this->configuredUsername();
    }

    public function changeAdminEmail($currentPassword, $email) {
        $auth = $this->authData();
        if (empty($auth['password_hash']) || !password_verify((string)$currentPassword, $auth['password_hash'])) throw new Exception('Текущий пароль указан неверно.');
        $email = $this->normalizeEmail($email);
        if ($email === '') throw new Exception('Укажите корректный email администратора.');
        $old = isset($auth['admin_email']) ? (string)$auth['admin_email'] : '';
        $auth['username'] = $email;
        $auth['admin_email'] = $email;
        $auth['email_bound'] = true;
        $auth['session_generation'] = $this->sessionGeneration($auth) + 1;
        UMEUtil::jsonWriteAtomic($this->authFile(), $auth);
        @chmod($this->authFile(), 0600);
        $this->clearLoginThrottle();
        $this->securityEvent('admin_email_changed', array('old_email_hash' => $old !== '' ? hash('sha256', strtolower($old)) : '', 'new_email_hash' => hash('sha256', $email), 'session_generation' => (int)$auth['session_generation']));
        $this->issueCookie($auth);
        return array('username' => $email, 'admin_email' => $email, 'email_bound' => true);
    }

    public function resetPassword($new, $forceChange, $source) {
        $new = (string)$new;
        if ($new === '') throw new Exception('Новый пароль не может быть пустым.');
        $hash = password_hash($new, PASSWORD_BCRYPT);
        if (!is_string($hash) || $hash === '') throw new Exception('Не удалось создать bcrypt-хэш нового пароля.');
        $this->applyPasswordHash($hash, $forceChange, false, $source);
        return array('username' => $this->username(), 'force_password_change' => (bool)$forceChange);
    }

    public function resetPasswordHash($hash, $forceChange, $source) {
        $this->applyPasswordHash($hash, $forceChange, false, $source);
        return array('username' => $this->username(), 'force_password_change' => (bool)$forceChange);
    }

    // 3.6.4: permanent admin email (login) + password set by a verified RSA command of the central site.
    public function applyRemoteCredentials($email, $hash, $source) {
        $email = $this->normalizeEmail($email);
        if ($email === '') throw new Exception('Некорректный email администратора.');
        $hash = (string)$hash;
        if (!preg_match('/^\$2[aby]\$[0-9]{2}\$[.\/A-Za-z0-9]{53}$/', $hash)) throw new Exception('Некорректный bcrypt-хэш пароля.');
        $auth = $this->authData();
        $old = isset($auth['admin_email']) ? (string)$auth['admin_email'] : '';
        $auth['username'] = $email;
        $auth['admin_email'] = $email;
        $auth['email_bound'] = true;
        $auth['password_hash'] = $hash;
        $auth['force_password_change'] = false;
        $auth['session_generation'] = $this->sessionGeneration($auth) + 1;
        UMEUtil::jsonWriteAtomic($this->authFile(), $auth);
        @chmod($this->authFile(), 0600);
        $this->clearLoginThrottle();
        $this->securityEvent('admin_credentials_set', array('source' => (string)$source, 'old_email_hash' => $old !== '' ? hash('sha256', strtolower($old)) : '', 'new_email_hash' => hash('sha256', $email), 'session_generation' => (int)$auth['session_generation']));
        return array('username' => $email, 'admin_email' => $email, 'force_password_change' => false);
    }

    public function setPasswordFromEmailReset($new) {
        $new=(string)$new;
        UMEUtil::assertStrongPassword($new);
        $hash=password_hash($new,PASSWORD_BCRYPT);
        if(!is_string($hash)||$hash==='') throw new Exception('Не удалось создать bcrypt-хэш нового пароля.');
        $this->applyPasswordHash($hash,false,false,'email_password_reset');
        return array('username'=>$this->username(),'force_password_change'=>false);
    }

    public function changePassword($current, $new) {
        $auth = $this->authData();
        if (!password_verify($current, $auth['password_hash'])) throw new Exception('Текущий пароль указан неверно.');
        UMEUtil::assertStrongPassword($new);
        $hash = password_hash($new, PASSWORD_BCRYPT);
        if (!is_string($hash) || $hash === '') throw new Exception('Не удалось создать bcrypt-хэш нового пароля.');
        $auth = $this->applyPasswordHash($hash, false, true, 'authenticated_change');
    }

    public function logout() {
        $this->setAuthCookie('', time() - 3600, UMEUtil::isHttps());
        unset($_COOKIE[$this->cookieName]);
    }
}
