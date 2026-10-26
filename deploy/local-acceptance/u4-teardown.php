<?php

/**
 * FB-CIVIL-01A-UI U4 — removes the LOCAL acceptance environment: the
 * famboook_u4_test database on the disposable cluster (127.0.0.1:5433), the
 * famboook_u4_* login roles and the state directory (credentials and
 * synthetic sources). The shared identity group roles are cluster objects of
 * the disposable test cluster and stay, as after the automated tests.
 */
const HOST = '127.0.0.1';
const PORT = 5433;
const DATABASE = 'famboook_u4_test';

$state = rtrim((string) (getenv('FAMBOOOK_U4_STATE') ?: (getenv('LOCALAPPDATA') ?: sys_get_temp_dir()).DIRECTORY_SEPARATOR.'famboook-u4'), '\\/');
$pdo = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=postgres', HOST, PORT), 'postgres', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ((int) $pdo->query('SELECT inet_server_port()')->fetchColumn() !== 5433) {
    fwrite(STDERR, "[WRONG_CLUSTER]\n");
    exit(1);
}
$pdo->exec('DROP DATABASE IF EXISTS '.DATABASE.' WITH (FORCE)');
foreach (['famboook_u4_monitor_login', 'famboook_u4_control_login', 'famboook_u4_import_login', 'famboook_u4_app'] as $role) {
    $pdo->exec("DROP ROLE IF EXISTS {$role}");
}
if (is_dir($state)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($state, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && ! $f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($state);
}
echo "U4 acceptance environment removed.\n";
