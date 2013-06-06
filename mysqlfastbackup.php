#!/usr/bin/php -q
<?php

/**
* FastDump - very quick script to backup mysql database
*/

$options = getopt('h:u:p::o:t:');
$options['database'] = end($argv);


// Help
function showhelp() {
echo <<<END
EXAMPLE: mysqlfastbackup.php -hlocalhost -uroot -psecret -t /temp -o /backup database
--help    To see a complete list of the parameters.
-h        MySql Host.
-u        MySql User.
-p        MySql Password.
-o        Directory for output file.
-t        Temp drirectory, default is the current directory.
END;
echo "\n";
exit;
}


if (in_array('--help', $argv) === TRUE) {
    showhelp();
}


// Settings
$host = isempty(@$options['h'], 'showhelp', TRUE);
$user = isempty(@$options['u'], 'showhelp', TRUE);
$pass = isempty(@$options['p'], 'showhelp', TRUE);
$database = isempty(@$options['database'], 'showhelp', TRUE);
$tempdir = isset($options['t']) ? rtrim($options['t'], '/') : './';
$outdir = isset($options['o']) ? rtrim($options['o'], '/') : FALSE;


// Function for testing is set
function isempty($in, $alternative, $call = FALSE) {
    if (mb_strlen($in)) {
        return $in;
    } else {
        if ($call) {
            call_user_func($alternative);
            exit;
        }
    }

    return $alternative;
}


// Function for create path if not exists
function make_path($path) {
    //Test if path exist
    if (is_dir($path) || file_exists($path)) return;

    //No, create it
    mkdir($path, 0777, true);
}


// Preparation of the working directory
$workDir = "{$tempdir}/{$database}_" . md5(time());

if (file_exists($workDir)) {
  die("Working directory collision");
} else {
	make_path($workDir);
}


// Connection to database
$dbh = new PDO('mysql:host=' . $host . ';dbname=' . $database, $user, $pass);


// Lock tables
$dbh->query('FLUSH TABLES WITH READ LOCK');


// Get all tables
$tables = array();
foreach ($dbh->query("SHOW TABLE STATUS LIKE '%'") as $tableInfo) {
    $type = mb_strtolower($tableInfo['Engine']);
    $tables[$type][] = $tableInfo['Name'];

    // tables with others engines
    if (!($type == 'myisam' || $type == 'innodb')) {
		$tables['other'][] = $tableInfo['Name'];
    }
}


// InnoDb backup
if (isset($tables['innodb']) && count($tables['innodb']) > 0) {
    $innodbTablesConcat = implode(' ', $tables['innodb']);
    echo shell_exec("sudo -u root /usr/bin/mysqldump -u{$user} -p{$pass} --databases {$database} --tables {$innodbTablesConcat} > {$workDir}/innodb.sql");
}


// MyIsam backup
if (isset($tables['myisam']) && count($tables['myisam']) > 0) {
    make_path("{$workDir}/myisam");
    $myisamTableConcat = implode('\|', $tables['myisam']);
    echo shell_exec("sudo -u root /usr/bin/mysqlhotcopy -u {$user} -p {$pass} {$database}./^\({$myisamTableConcat}\)$/ {$workDir}/myisam");
}

// Other Engines backup
if (isset($tables['other']) && count($tables['other']) > 0) {
	$otherTablesConcat = implode(' ', $tables['other']);
	echo shell_exec("sudo -u root /usr/bin/mysqldump -u{$user} -p{$pass} --databases {$database} --tables {$otherTablesConcat} > {$workDir}/other.sql");
}


// Unlock tables
$dbh->query('UNLOCK TABLES');


// Packing to tar
echo shell_exec("sudo -u root /bin/tar czf {$tempdir}/{$database}.tar.gz -C {$workDir} .");


// Moving to out dir
make_path($outdir);
rename("{$tempdir}/{$database}.tar.gz", "{$outdir}/{$database}.tar.gz");
//echo shell_exec("sudo -u root /bin/mv {$tempdir}/{$database}.tar.gz {$outdir}/{$database}.tar.gz");


// Remove temp
echo shell_exec("sudo -u root /bin/rm -fR {$workDir}");
