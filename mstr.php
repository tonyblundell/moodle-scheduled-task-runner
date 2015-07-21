<?php
define('CLI_SCRIPT', true);
require_once dirname(__FILE__) . '/config.php';
require_once $CFG->libdir . '/cronlib.php';

// Runs a scheduled task outside of the cron functionality.
// Bypasses all cron checks around blocking tasks, schedule, syncronicity, etc.
// Intended for use by programmers developing scheduled tasks.
//
// Usage:
// php mstr.php - List all tasks
// php mstr.php classname - Run the given task
//
// !!! NOT FOR USE IN PRODUCTION !!!
// !!! DO NOT COMMIT TO SOURCE CONTROL !!!

// Grab all tasks.
$tasks = array();
$taskrecords = $DB->get_records('task_scheduled', null, 'classname');
foreach ($taskrecords as $taskrecord) {
    $task = \core\task\manager::scheduled_task_from_record($taskrecord);
    $tasks[$taskrecord->classname] = $task;
}

// If no task name was passed at the command line, list all tasks and exit.
if (count($argv) <= 1) {
    foreach (array_keys($tasks) as $taskname) {
        echo "$taskname\n";
    }
    die();
}

// If a task name was passed at the CL, check that it is valid, exit if not.
$arg = $argv[1];
if (!array_key_exists($arg, $tasks)) {
    die("Task not found: $arg");
}

// Valid task name given - run it. The following code was stolen from cronlib.
$task = $tasks[$arg];
mtrace("Execute scheduled task: " . $task->get_name());
cron_trace_time_and_memory();
$predbqueries = null;
$predbqueries = $DB->perf_get_queries();
$pretime      = microtime(1);
try {
    $task->execute();
    if (isset($predbqueries)) {
        mtrace("... used " . ($DB->perf_get_queries() - $predbqueries) . " dbqueries");
        mtrace("... used " . (microtime(1) - $pretime) . " seconds");
    }
    mtrace("Scheduled task complete: " . $task->get_name());
} catch (Exception $e) {
    if ($DB && $DB->is_transaction_started()) {
        error_log('Database transaction aborted automatically in ' . get_class($task));
        $DB->force_transaction_rollback();
    }
    if (isset($predbqueries)) {
        mtrace("... used " . ($DB->perf_get_queries() - $predbqueries) . " dbqueries");
        mtrace("... used " . (microtime(1) - $pretime) . " seconds");
    }
    mtrace("Scheduled task failed: " . $task->get_name() . "," . $e->getMessage());
}
