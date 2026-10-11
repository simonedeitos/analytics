<?php

declare(strict_types=1);

ob_start();

require_once dirname(__DIR__) . '/includes/team_service.php';
require_once dirname(__DIR__) . '/includes/auth.php';

final class TeamTestPdo extends PDO
{
    public array $users;
    public array $assignments;
    public array $sessions;
    public array $queries = [];
    public ?string $failOn = null;
    public bool $foreignAssignment = false;
    public bool $committed = false;
    public bool $rolledBack = false;
    private bool $transaction = false;
    private array $snapshot = [];

    public function __construct()
    {
        $this->users = [
            1 => ['id' => 1, 'role' => 'user', 'status' => 'active'],
            2 => ['id' => 2, 'parent_user_id' => 1, 'role' => 'subuser', 'status' => 'active', 'remember_token' => 'old', 'remember_token_expires_at' => '2099-01-01'],
            3 => ['id' => 3, 'parent_user_id' => 1, 'role' => 'subuser', 'status' => 'active'],
            4 => ['id' => 4, 'parent_user_id' => 99, 'role' => 'subuser', 'status' => 'active'],
            5 => ['id' => 5, 'parent_user_id' => 1, 'role' => 'subuser', 'status' => 'disabled'],
            6 => ['id' => 6, 'role' => 'admin', 'status' => 'active'],
        ];
        $this->assignments = [
            ['property_id' => 10, 'subuser_id' => 2, 'assigned_by' => 1, 'assigned_at' => 'old'],
            ['property_id' => 11, 'subuser_id' => 2, 'assigned_by' => 1, 'assigned_at' => 'old'],
            ['property_id' => 11, 'subuser_id' => 3, 'assigned_by' => 1, 'assigned_at' => 'preserved'],
            ['property_id' => 12, 'subuser_id' => 4, 'assigned_by' => 99, 'assigned_at' => 'old'],
        ];
        $this->sessions = [['user_id' => 2], ['user_id' => 2], ['user_id' => 3]];
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new TeamTestStatement($this, $query);
    }

    public function beginTransaction(): bool
    {
        $this->snapshot = [$this->users, $this->assignments, $this->sessions];
        $this->transaction = true;
        return true;
    }

    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): bool
    {
        if ($this->failOn === 'COMMIT') throw new RuntimeException('Injected commit failure');
        $this->committed = true;
        $this->transaction = false;
        return true;
    }
    public function rollBack(): bool
    {
        [$this->users, $this->assignments, $this->sessions] = $this->snapshot;
        $this->rolledBack = true;
        $this->transaction = false;
        return true;
    }
}

final class TeamTestStatement extends PDOStatement
{
    private array|false $result = false;
    public function __construct(private TeamTestPdo $pdo, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        $params ??= [];
        $this->pdo->queries[] = $this->sql;
        if ($this->pdo->failOn && str_contains($this->sql, $this->pdo->failOn)) {
            throw new RuntimeException('Injected database failure');
        }
        if (str_starts_with($this->sql, 'SELECT')) {
            if (str_contains($this->sql, 'FROM property_assignments')) {
                $this->result = $this->pdo->foreignAssignment ? ['property_id' => 12] : false;
                return true;
            }
            $user = $this->pdo->users[$params['id']] ?? null;
            $valid = $user && $user['status'] === 'active';
            if (str_contains($this->sql, 'AND EXISTS')) {
                $valid = $valid && (bool) array_filter($this->pdo->sessions, static fn (array $session): bool =>
                    $session['user_id'] === $params['id']
                    && ($session['id'] ?? '') === $params['session_id']
                    && ($session['expires_at'] ?? '2099-01-01') >= date('Y-m-d H:i:s'));
            }
            if (str_contains($this->sql, "role = 'user'")) $valid = $valid && $user['role'] === 'user';
            if (str_contains($this->sql, "role = 'subuser'")) $valid = $valid && $user['role'] === 'subuser';
            if (isset($params['owner_id'])) $valid = $valid && ($user['parent_user_id'] ?? null) === $params['owner_id'];
            $this->result = $valid ? $user : false;
        } elseif (str_starts_with($this->sql, 'INSERT INTO property_assignments')) {
            foreach ($this->pdo->assignments as $assignment) {
                if ($assignment['subuser_id'] !== $params['target_id']) continue;
                $exists = array_filter($this->pdo->assignments, static fn (array $row): bool => $row['property_id'] === $assignment['property_id'] && $row['subuser_id'] === $params['recipient_id']);
                if (!$exists) {
                    $assignment['subuser_id'] = $params['recipient_id'];
                    $assignment['assigned_by'] = $params['assigned_by'];
                    $assignment['assigned_at'] = 'now';
                    $this->pdo->assignments[] = $assignment;
                }
            }
        } elseif (str_starts_with($this->sql, 'DELETE FROM property_assignments')) {
            $this->pdo->assignments = array_values(array_filter($this->pdo->assignments, static fn (array $row): bool => $row['subuser_id'] !== $params['target_id']));
        } elseif (str_starts_with($this->sql, "UPDATE users SET status = 'disabled'")) {
            $this->pdo->users[$params['id']]['status'] = 'disabled';
            $this->pdo->users[$params['id']]['remember_token'] = null;
            $this->pdo->users[$params['id']]['remember_token_expires_at'] = null;
        } elseif (str_starts_with($this->sql, 'DELETE FROM user_sessions')) {
            $this->pdo->sessions = array_values(array_filter($this->pdo->sessions, static fn (array $row): bool => $row['user_id'] !== $params['user_id']));
        } elseif (str_starts_with($this->sql, 'REPLACE INTO user_sessions')) {
            $this->pdo->sessions[] = ['user_id' => $params['user_id']];
        } elseif (str_starts_with($this->sql, 'UPDATE users SET remember_token = NULL')) {
            $this->pdo->users[$params['id']]['remember_token'] = null;
            $this->pdo->users[$params['id']]['remember_token_expires_at'] = null;
        } elseif (str_starts_with($this->sql, 'UPDATE users SET remember_token = :token')) {
            $this->pdo->users[$params['id']]['remember_token'] = $params['token'];
            $this->pdo->users[$params['id']]['remember_token_expires_at'] = $params['expires_at'];
        } else {
            throw new RuntimeException('Unexpected SQL: ' . $this->sql);
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->result; }
}

final class TeamSqlitePdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace(
            [' FOR UPDATE', 'NOW()', 'ON DUPLICATE KEY UPDATE property_id = VALUES(property_id)'],
            ['', 'CURRENT_TIMESTAMP', 'ON CONFLICT(property_id, subuser_id) DO NOTHING'],
            $query
        );
        return parent::prepare($query, $options);
    }
    public function exec(string $statement): int|false
    {
        $statement = str_replace(
            ['CREATE TEMPORARY TABLE', 'DROP TEMPORARY TABLE', ' ENGINE=InnoDB', 'UNIQUE KEY uniq_assignment', "'2099-01-01'", "'2020-01-01'", "'2021-01-01'"],
            ['CREATE TABLE', 'DROP TABLE', '', 'UNIQUE', "'2099-01-01 00:00:00'", "'2020-01-01 00:00:00'", "'2021-01-01 00:00:00'"],
            $statement
        );
        return parent::exec($statement);
    }
}

function analyticspro_db(): PDO { return $GLOBALS['teamPdo']; }
function analyticspro_redirect(string $path): never { throw new RuntimeException('redirect:' . $path); }
function analyticspro_json(array $data, int $status): never { throw new RuntimeException('http:' . $status); }
function analyticspro_client_ip(): string { return '127.0.0.1'; }

$checks = 0;
function team_check(bool $condition, string $message): void
{
    $GLOBALS['checks']++;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
}
function team_reject(callable $callback, string $message): void
{
    try { $callback(); } catch (RuntimeException $exception) { team_check(true, $message); return; }
    team_check(false, $message);
}

foreach (['keep', 'unassign', 'reassign'] as $mode) {
    $teamPdo = new TeamTestPdo();
    $before = $teamPdo->assignments;
    analyticspro_disable_subuser($teamPdo, 1, 2, $mode, $mode === 'reassign' ? 3 : null);
    team_check($teamPdo->committed && !$teamPdo->inTransaction(), "{$mode} commits");
    team_check($teamPdo->users[2]['status'] === 'disabled' && $teamPdo->users[2]['remember_token'] === null && $teamPdo->users[2]['remember_token_expires_at'] === null, "{$mode} revokes remembered login");
    team_check($teamPdo->sessions === [['user_id' => 3]], "{$mode} revokes only target sessions");
    team_check(str_contains($teamPdo->queries[0], "role = 'user'") && str_contains($teamPdo->queries[0], 'FOR UPDATE'), 'tenant owner locked first');
    team_check(str_contains($teamPdo->queries[1], 'FOR UPDATE'), 'target locked');
    if ($mode === 'keep') team_check($before === $teamPdo->assignments, 'keep leaves assignments untouched');
    if ($mode === 'unassign') team_check(count($teamPdo->assignments) === 2, 'unassign removes only source assignments');
    if ($mode === 'reassign') {
        team_check(count($teamPdo->assignments) === 3, 'reassign transfers assignments without duplicates');
        team_check(count(array_filter($teamPdo->assignments, static fn (array $row): bool => $row['subuser_id'] === 2)) === 0, 'reassign removes source');
        team_check($teamPdo->assignments[0]['assigned_at'] === 'preserved', 'existing recipient assignment metadata preserved');
        team_check(str_contains($teamPdo->queries[3], 'FOR UPDATE'), 'recipient locked');
        team_check(str_contains($teamPdo->queries[4], 'ON DUPLICATE KEY UPDATE') && str_contains($teamPdo->queries[4], 'p.user_id = :owner_id'), 'reassignment SQL handles uniqueness and tenant scope');
    }
}

foreach ([[99, 2, 'keep', null], [6, 2, 'keep', null], [1, 4, 'keep', null], [1, 5, 'keep', null], [1, 1, 'keep', null], [1, 2, 'invalid', null], [1, 2, 'reassign', null], [1, 2, 'reassign', 2], [1, 2, 'reassign', 4], [1, 2, 'reassign', 5], [1, 2, 'reassign', 1], [1, 2, 'reassign', 999]] as $arguments) {
    $teamPdo = new TeamTestPdo();
    $before = [$teamPdo->users, $teamPdo->assignments, $teamPdo->sessions];
    team_reject(static fn () => analyticspro_disable_subuser($teamPdo, ...$arguments), 'invalid owner/target/mode/recipient rejected');
    team_check($before === [$teamPdo->users, $teamPdo->assignments, $teamPdo->sessions] && !$teamPdo->inTransaction(), 'rejection leaves database unchanged');
}
foreach (['INSERT INTO property_assignments', 'DELETE FROM property_assignments', "UPDATE users SET status", 'DELETE FROM user_sessions'] as $failure) {
    $teamPdo = new TeamTestPdo();
    $before = [$teamPdo->users, $teamPdo->assignments, $teamPdo->sessions];
    $teamPdo->failOn = $failure;
    team_reject(static fn () => analyticspro_disable_subuser($teamPdo, 1, 2, 'reassign', 3), 'database failures propagate');
    team_check($teamPdo->rolledBack && $before === [$teamPdo->users, $teamPdo->assignments, $teamPdo->sessions], 'failure rolls back every change');
}
foreach (['reassign', 'unassign'] as $mode) {
    $teamPdo = new TeamTestPdo();
    $teamPdo->foreignAssignment = true;
    $before = [$teamPdo->users, $teamPdo->assignments, $teamPdo->sessions];
    team_reject(static fn () => analyticspro_disable_subuser($teamPdo, 1, 2, $mode, 3), 'inconsistent cross-tenant source rejected');
    team_check($teamPdo->rolledBack && $before === [$teamPdo->users, $teamPdo->assignments, $teamPdo->sessions], 'foreign assignment rejection is atomic');
}

$teamPdo = new TeamTestPdo();
$teamPdo->users[1]['status'] = 'disabled';
team_reject(static fn () => analyticspro_disable_subuser($teamPdo, 1, 2, 'keep'), 'disabled owner rejected');
$teamPdo = new TeamTestPdo();
$teamPdo->beginTransaction();
team_reject(static fn () => analyticspro_disable_subuser($teamPdo, 1, 2, 'keep'), 'caller transaction rejected without corrupting it');
team_check($teamPdo->inTransaction(), 'caller transaction remains intact');
$teamPdo->rollBack();

$_SESSION = ['analyticspro_user' => ['id' => 2, 'role' => 'admin', 'status' => 'active']];
team_check(analyticspro_current_user()['role'] === 'subuser', 'authentication refreshes cached privileges from database');
analyticspro_disable_subuser($teamPdo, 1, 2, 'keep');
$_COOKIE['analyticspro_remember'] = '2:stale-token';
team_check(analyticspro_current_user() === null && !isset($_SESSION['analyticspro_user'], $_COOKIE['analyticspro_remember']), 'disabled cached session and cookie rejected');
$_SESSION['analyticspro_user'] = ['id' => 5, 'status' => 'active'];
team_reject(static fn () => analyticspro_require_auth(), 'page guard rejects disabled user');
$_SESSION['analyticspro_user'] = ['id' => 5, 'status' => 'active'];
team_reject(static fn () => analyticspro_api_require_auth(), 'API guard rejects disabled user');
team_reject(static fn () => analyticspro_login(['id' => 5, 'status' => 'active']), 'login rejects supplied active status when database is disabled');
team_check($teamPdo->rolledBack && !$teamPdo->inTransaction() && str_contains(end($teamPdo->queries), 'FOR UPDATE'), 'login checks active database user under transaction lock and rolls back rejection');
$_SESSION['analyticspro_user'] = ['id' => 999, 'status' => 'active'];
team_check(analyticspro_current_user() === null, 'deleted cached user rejected');
$_COOKIE['analyticspro_remember'] = '5:stale-token';
analyticspro_attempt_remember_login();
team_check(analyticspro_current_user() === null, 'remember login rejects inactive database user');
session_set_save_handler(new class implements SessionHandlerInterface {
    private array $sessions = [];
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return $this->sessions[$id] ?? ''; }
    public function write(string $id, string $data): bool { $this->sessions[$id] = $data; return true; }
    public function destroy(string $id): bool { unset($this->sessions[$id]); return true; }
    public function gc(int $maxLifetime): int|false { return 0; }
}, true);
session_start();
foreach (['REPLACE INTO user_sessions', 'UPDATE users SET remember_token = NULL', 'UPDATE users SET remember_token = :token', 'COMMIT'] as $failure) {
    $teamPdo = new TeamTestPdo();
    $teamPdo->failOn = $failure;
    $before = [$teamPdo->users, $teamPdo->assignments, $teamPdo->sessions];
    $_SESSION = [];
    $headersBefore = headers_list();
    team_reject(static fn () => analyticspro_login(['id' => 2, 'status' => 'active'], true), 'login database failure propagates');
    team_check($teamPdo->rolledBack && $before === [$teamPdo->users, $teamPdo->assignments, $teamPdo->sessions], 'login DB exception restores session/token database writes');
    team_check(!isset($_SESSION['analyticspro_user']) && headers_list() === $headersBefore, 'login DB failure sets neither authenticated session nor remember cookie');
}
$teamPdo = new TeamTestPdo();
$_SESSION = [];
analyticspro_login(['id' => 2, 'role' => 'admin', 'status' => 'disabled'], true);
team_check($teamPdo->committed && !$teamPdo->inTransaction(), 'successful login commits database writes');
team_check($_SESSION['analyticspro_user']['role'] === 'subuser', 'successful login trusts locked DB role rather than supplied role/status');
team_check($teamPdo->users[2]['remember_token'] !== null && count($teamPdo->sessions) === 4, 'successful remembered login persists token and session');
$teamPdo->sessions[] = ['id' => session_id(), 'user_id' => 2, 'expires_at' => '2099-01-01'];
team_check(analyticspro_current_user() !== null, 'persisted active session accepted');
analyticspro_disable_subuser($teamPdo, 1, 2, 'keep');
$teamPdo->users[2]['status'] = 'active';
team_check(analyticspro_current_user() === null, 'disable then reactivate before next request cannot revive deleted session');
$_SESSION['analyticspro_user'] = ['id' => 3, 'status' => 'active'];
$teamPdo->sessions[] = ['id' => session_id(), 'user_id' => 3, 'expires_at' => '2000-01-01'];
team_check(analyticspro_current_user() === null, 'expired persisted session rejected');
$_SESSION['analyticspro_user'] = ['id' => 3, 'status' => 'active'];
$teamPdo->sessions = [];
team_check(analyticspro_current_user() === null, 'active user with missing persisted session rejected');

echo "PASS: team service/auth regression ({$checks} checks; PDO doubles, no schema changes)\n";

$mysqlDsn = getenv('ANALYTICSPRO_TEST_MYSQL_DSN') ?: getenv('TEST_MYSQL_DSN');
$integrationBackend = $mysqlDsn ? 'MySQL' : 'SQLite';
{
    $teamPdo = $mysqlDsn ? new PDO($mysqlDsn, getenv('ANALYTICSPRO_TEST_MYSQL_USER') ?: getenv('TEST_MYSQL_USER') ?: 'root', getenv('ANALYTICSPRO_TEST_MYSQL_PASS') ?: getenv('TEST_MYSQL_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]) : new TeamSqlitePdo();
    if ($mysqlDsn) {
        // MySQL cannot read and write the same temporary table in INSERT SELECT.
        $testDatabase = 'analytics_team_service_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $teamPdo->exec('CREATE DATABASE `' . $testDatabase . '`');
        $teamPdo->exec('USE `' . $testDatabase . '`');
        register_shutdown_function(static function () use ($teamPdo, $testDatabase): void {
            $teamPdo->exec('DROP DATABASE IF EXISTS `' . $testDatabase . '`');
        });
    }
    $teamPdo->exec("CREATE TABLE users (
        id INT PRIMARY KEY, parent_user_id INT NULL, role VARCHAR(20), status VARCHAR(20),
        remember_token VARCHAR(255) NULL, remember_token_expires_at DATETIME NULL
    ) ENGINE=InnoDB");
    $teamPdo->exec('CREATE TABLE properties (id BIGINT PRIMARY KEY, user_id INT NOT NULL) ENGINE=InnoDB');
    $teamPdo->exec('CREATE TABLE property_assignments (
        property_id BIGINT NOT NULL, subuser_id INT NOT NULL, assigned_by INT NOT NULL,
        assigned_at DATETIME NOT NULL, UNIQUE KEY uniq_assignment (property_id, subuser_id)
    ) ENGINE=InnoDB');
    $teamPdo->exec('CREATE TABLE user_sessions (
        id VARCHAR(128) PRIMARY KEY, user_id INT NOT NULL,
        ip_address VARCHAR(45) NULL, user_agent VARCHAR(255) NULL, expires_at DATETIME NULL
    ) ENGINE=InnoDB');

    $resetMysql = static function () use ($teamPdo): void {
        foreach (['user_sessions', 'property_assignments', 'properties', 'users'] as $table) {
            $teamPdo->exec('DELETE FROM ' . $table);
        }
        $teamPdo->exec("INSERT INTO users VALUES
            (1, NULL, 'user', 'active', NULL, NULL),
            (2, 1, 'subuser', 'active', 'remembered', '2099-01-01'),
            (3, 1, 'subuser', 'active', NULL, NULL),
            (4, 99, 'subuser', 'active', NULL, NULL),
            (5, 1, 'subuser', 'disabled', NULL, NULL)");
        $teamPdo->exec('INSERT INTO properties VALUES (10, 1), (11, 1), (12, 99)');
        $teamPdo->exec("INSERT INTO property_assignments VALUES
            (10, 2, 1, '2020-01-01'), (11, 2, 1, '2020-01-01'),
            (11, 3, 1, '2021-01-01'), (12, 4, 99, '2020-01-01')");
        $teamPdo->exec("INSERT INTO user_sessions (id, user_id, expires_at) VALUES ('target-one', 2, '2099-01-01'), ('target-two', 2, '2099-01-01'), ('other', 3, '2099-01-01')");
    };
    $snapshotMysql = static fn (): array => [
        $teamPdo->query('SELECT * FROM users ORDER BY id')->fetchAll(),
        $teamPdo->query('SELECT * FROM property_assignments ORDER BY property_id, subuser_id')->fetchAll(),
        $teamPdo->query('SELECT * FROM user_sessions ORDER BY id')->fetchAll(),
    ];
    $mysqlChecksBefore = $checks;
    foreach (['keep', 'unassign', 'reassign'] as $mode) {
        $resetMysql();
        $before = $snapshotMysql();
        analyticspro_disable_subuser($teamPdo, 1, 2, $mode, $mode === 'reassign' ? 3 : null);
        $after = $snapshotMysql();
        team_check(!$teamPdo->inTransaction(), "MySQL {$mode} commits");
        team_check($after[0][1]['status'] === 'disabled' && $after[0][1]['remember_token'] === null && $after[0][1]['remember_token_expires_at'] === null, "MySQL {$mode} disables and clears tokens");
        team_check(count($after[2]) === 1 && (int) $after[2][0]['user_id'] === 3, "MySQL {$mode} revokes target sessions");
        team_check(count($after[1]) === ['keep' => 4, 'unassign' => 2, 'reassign' => 3][$mode], "MySQL {$mode} assignment count");
        if ($mode === 'keep') team_check($before[1] === $after[1], 'MySQL keep preserves exact assignment data');
        if ($mode === 'reassign') {
            team_check((int) $after[1][0]['subuser_id'] === 3 && (int) $after[1][1]['subuser_id'] === 3, 'MySQL source assignments transferred');
            team_check($after[1][1]['assigned_at'] === '2021-01-01 00:00:00', 'MySQL duplicate assignment preserves recipient metadata');
        }
    }
    foreach ([[1, 4, 'keep', null], [1, 5, 'keep', null], [1, 2, 'reassign', 4], [1, 2, 'reassign', 5], [1, 2, 'reassign', 2]] as $arguments) {
        $resetMysql();
        $before = $snapshotMysql();
        team_reject(static fn () => analyticspro_disable_subuser($teamPdo, ...$arguments), 'MySQL rejects invalid target/recipient');
        team_check($before === $snapshotMysql() && !$teamPdo->inTransaction(), 'MySQL rollback preserves all data');
    }
    foreach (['reassign', 'unassign'] as $mode) {
        $resetMysql();
        $teamPdo->exec("INSERT INTO property_assignments VALUES (12, 2, 99, '2020-01-01')");
        $before = $snapshotMysql();
        team_reject(static fn () => analyticspro_disable_subuser($teamPdo, 1, 2, $mode, 3), 'SQL rejects foreign source assignment');
        team_check($before === $snapshotMysql() && !$teamPdo->inTransaction(), 'SQL foreign assignment rejection rolls back');
    }
    $resetMysql();
    $_SESSION = [];
    analyticspro_login(['id' => 2, 'role' => 'admin', 'status' => 'disabled'], true);
    $loggedIn = $snapshotMysql();
    team_check($_SESSION['analyticspro_user']['role'] === 'subuser' && !$teamPdo->inTransaction(), 'SQL successful login uses locked DB identity and commits');
    team_check(count($loggedIn[2]) === 4 && $loggedIn[0][1]['remember_token'] !== null, 'SQL login persists a new session and remember hash');
    analyticspro_disable_subuser($teamPdo, 1, 2, 'keep');
    $revoked = $snapshotMysql();
    team_check(count($revoked[2]) === 1 && $revoked[0][1]['remember_token'] === null && $revoked[0][1]['status'] === 'disabled', 'SQL disable revokes login-created session and remember token');
    $teamPdo->exec("UPDATE users SET status = 'active' WHERE id = 2");
    team_check(analyticspro_current_user() === null, 'SQL reactivation cannot revive deleted PHP session');

    if ($mysqlDsn) {
        $resetMysql();
        $teamPdo->exec("CREATE TRIGGER team_fail_session_delete BEFORE DELETE ON user_sessions
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected deletion failure'");
        $before = $snapshotMysql();
        team_reject(static fn () => analyticspro_disable_subuser($teamPdo, 1, 2, 'reassign', 3), 'MySQL late session deletion failure propagates');
        team_check($before === $snapshotMysql() && !$teamPdo->inTransaction(), 'MySQL late failure rolls back assignments, status and tokens');
        $teamPdo->exec('DROP TRIGGER team_fail_session_delete');
    }
    $resetMysql();
    $sessionStmt = $teamPdo->prepare("INSERT INTO user_sessions (id, user_id, expires_at) VALUES (:id, 2, '2099-01-01')");
    $sessionStmt->execute(['id' => session_id()]);
    $_SESSION = ['analyticspro_user' => ['id' => 2, 'role' => 'admin', 'status' => 'active']];
    team_check(analyticspro_current_user()['role'] === 'subuser', 'MySQL authentication refreshes cached role');
    analyticspro_disable_subuser($teamPdo, 1, 2, 'keep');
    team_check(analyticspro_current_user() === null, 'MySQL authentication rejects newly disabled session');
    team_reject(static fn () => analyticspro_login(['id' => 2, 'status' => 'active']), 'MySQL login rejects stale active status');
    team_check(!$teamPdo->inTransaction(), 'SQL rejected login transaction rolled back');
    foreach (['user_sessions', 'property_assignments', 'properties', 'users'] as $table) {
        $teamPdo->exec('DROP TABLE ' . $table);
    }
    echo "PASS: {$integrationBackend} team/auth SQL integration (" . ($checks - $mysqlChecksBefore) . " checks)\n";
}
$_SESSION = [];
session_destroy();
ob_end_flush();
